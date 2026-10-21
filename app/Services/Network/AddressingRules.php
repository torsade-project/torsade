<?php

// torsade/app/Services/Network/AddressingRules.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\GatewayPosition;
use App\Enums\RangeKind;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Site;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\AddressingScheme;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The addressing rule of the organisation: how its block is cut into one
 * block per site, each cut into one subnet per VLAN, and how such a subnet
 * is usually cut into ranges.
 *
 * Every change to the rule goes through this class, whether it comes from
 * the interface or from a command: the rule is checked as a whole here and
 * each change is written to the activity log.
 *
 * A rule proposes and points out what differs. Saving or deleting it never
 * changes another object.
 *
 * @phpstan-type RuleRangeData array{kind: string, start_offset: int, end_offset: int, name?: string|null}
 * @phpstan-type RuleData array{aggregate_block_id: int, site_prefix_length: int|null, vlan_prefix_length: int, gateway_position: string, ranges?: array<int, RuleRangeData>}
 */
final class AddressingRules
{
    use TranslatesUniqueViolations;

    /**
     * How many ranges a rule can cut a subnet into.
     */
    public const int MAX_RANGES = 20;

    /**
     * The longest prefix of a subnet of a VLAN: a /30 still has room for a
     * gateway and a host.
     */
    public const int MAX_VLAN_PREFIX = 30;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly AddressBlocks $blocks,
    ) {}

    /**
     * The rule of the installation, with its block and its ranges, or null
     * when the organisation has none.
     */
    public function current(): ?AddressingRule
    {
        return AddressingRule::query()->with(['aggregateBlock', 'ranges'])->first();
    }

    /**
     * What the rule works out, or null without a rule: nothing is then
     * proposed and nothing differs from it.
     */
    public function scheme(): ?AddressingScheme
    {
        $rule = $this->current();

        return $rule === null ? null : AddressingScheme::fromRule($rule);
    }

    /**
     * Create the rule, or change the one there is: its block, its lengths,
     * its gateway and its ranges are saved together or not at all.
     *
     * @param  RuleData  $data
     *
     * @throws ValidationException Messages about a range are keyed "ranges.<line>.<field>".
     */
    public function save(array $data, Actor $actor): AddressingRule
    {
        return DB::transaction(function () use ($data, $actor): AddressingRule {
            $rule = AddressingRule::query()->with(['aggregateBlock', 'ranges'])->lockForUpdate()->first();

            // The block is locked: it cannot be given to a site or shrunk
            // while the rule is checked against it.
            $block = AddressBlock::query()->with('site')->whereKey($data['aggregate_block_id'])->lockForUpdate()->first();

            $ranges = array_values($data['ranges'] ?? []);

            $errors = $this->errors($rule, $block, $data, $ranges);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $before = $rule?->auditValues();

            $rule ??= new AddressingRule;

            $rule->fill([
                'aggregate_block_id' => $data['aggregate_block_id'],
                'site_prefix_length' => $data['site_prefix_length'],
                'vlan_prefix_length' => $data['vlan_prefix_length'],
                'gateway_position' => $data['gateway_position'],
            ]);

            // Two simultaneous requests can both find no rule: the database
            // keeps one.
            $this->guardingUniqueness(fn (): bool => $rule->save(), [
                'addressing_rules_singleton_unique' => ['aggregate_block_id', __('An addressing rule already exists.')],
            ]);

            // The ranges are few and have no life of their own: they are
            // written again as a whole, from the lowest.
            $rule->ranges()->delete();

            usort($ranges, fn (array $a, array $b): int => $a['start_offset'] <=> $b['start_offset']);

            foreach ($ranges as $range) {
                $name = trim((string) ($range['name'] ?? ''));

                $rule->ranges()->create([
                    'kind' => $range['kind'],
                    'start_offset' => $range['start_offset'],
                    'end_offset' => $range['end_offset'],
                    'name' => $name === '' ? null : $name,
                ]);
            }

            $rule->unsetRelation('aggregateBlock')->unsetRelation('ranges')->load(['aggregateBlock', 'ranges']);

            if ($before === null) {
                $this->log->created(ActivityEvent::AddressingRuleCreated, $rule, $actor);
            } else {
                $this->log->updated(ActivityEvent::AddressingRuleUpdated, $rule, $before, $actor);
            }

            return $rule;
        });
    }

    /**
     * Delete the rule and its ranges, and nothing else: the blocks, the
     * subnets and the ranges created from it stay as they are.
     */
    public function delete(AddressingRule $rule, Actor $actor): void
    {
        DB::transaction(function () use ($rule, $actor): void {
            $rule->loadMissing(['aggregateBlock', 'ranges']);

            $this->log->deleted(ActivityEvent::AddressingRuleDeleted, $rule, $actor);

            // The ranges go with the rule, by the key that ties them.
            $rule->delete();
        });
    }

    /**
     * Declare the block the rule expects a site to have, with the status
     * of the site. It goes through the rules of blocks like any other: a
     * block in the way refuses it.
     *
     * @param  string  $field  Where the refusal is told.
     *
     * @throws ValidationException
     */
    public function createExpectedBlock(Site $site, Actor $actor, string $field = 'block'): AddressBlock
    {
        return DB::transaction(function () use ($site, $actor, $field): AddressBlock {
            $scheme = $this->scheme();

            if ($scheme === null) {
                throw ValidationException::withMessages([
                    $field => __('There is no addressing rule: no block is expected for this site.'),
                ]);
            }

            $expected = $scheme->siteBlock($site->number);

            if ($expected === null) {
                throw ValidationException::withMessages([
                    $field => match (true) {
                        ! $scheme->hasSiteLevel() => __('The rule has no level of sites: it expects no block for a site.'),
                        $site->number === null => __('This site has no number: the rule expects no block for it.'),
                        default => __('The rule allows the numbers :min to :max: it expects no block for the site :number.', [
                            'min' => 0,
                            'max' => $scheme->maxSiteNumber(),
                            'number' => $site->number,
                        ]),
                    },
                ]);
            }

            try {
                return $this->blocks->create([
                    'network' => $expected['network'],
                    'prefix_length' => $expected['prefix_length'],
                    'site_id' => $site->id,
                    // The block of a site that is only planned is
                    // planned too.
                    'status' => $site->status->value,
                ], $actor);
            } catch (ValidationException $exception) {
                // Whatever field the rules of blocks name, the block was
                // not typed here: the refusal is told where it was asked.
                throw ValidationException::withMessages([
                    $field => __('The block :block cannot be created. :reason', [
                        'block' => AddressingScheme::cidr($expected),
                        'reason' => collect($exception->errors())->flatten()->first(),
                    ]),
                ]);
            }
        });
    }

    /**
     * What is wrong with the rule about to be saved, every field at once:
     * the lengths only make sense together, and the ranges depend on them.
     *
     * @param  RuleData  $data
     * @param  list<RuleRangeData>  $ranges
     * @return array<string, string>
     */
    private function errors(?AddressingRule $rule, ?AddressBlock $block, array $data, array $ranges): array
    {
        $errors = [];

        $site = $data['site_prefix_length'];
        $vlan = $data['vlan_prefix_length'];

        if ($block === null) {
            $errors['aggregate_block_id'] = __('This block no longer exists.');
        } elseif ($block->site_id !== null) {
            $errors['aggregate_block_id'] = __('The rule starts from a block of the organisation: :block belongs to the site :site.', [
                'block' => $block->cidr(),
                'site' => $block->site?->name,
            ]);
        } elseif ($site !== null && $site <= $block->prefix_length) {
            $errors['site_prefix_length'] = __('The blocks of sites are cut out of the block of the organisation: their length must be greater than :length.', [
                'length' => $block->prefix_length,
            ]);
        }

        // Without a level of sites, the subnets of VLANs are cut straight
        // out of the block of the organisation, whose prefix is in another
        // table: the database cannot check it.
        if ($vlan > self::MAX_VLAN_PREFIX) {
            $errors['vlan_prefix_length'] = __('A subnet of a VLAN is a /:max at most: beyond, it has no room for a gateway and a host.', [
                'max' => self::MAX_VLAN_PREFIX,
            ]);
        } elseif ($site !== null && $vlan <= $site) {
            $errors['vlan_prefix_length'] = __('The subnets of VLANs are cut out of the blocks of sites: their length must be greater than :length.', [
                'length' => $site,
            ]);
        } elseif ($site === null && $block !== null && $vlan <= $block->prefix_length) {
            $errors['vlan_prefix_length'] = __('Without a level of sites, the subnets of VLANs are cut out of the block of the organisation: their length must be greater than :length.', [
                'length' => $block->prefix_length,
            ]);
        }

        if (count($ranges) > self::MAX_RANGES) {
            $errors['ranges'] = trans_choice(
                'A subnet is cut into :count range at most.|A subnet is cut into :count ranges at most.',
                self::MAX_RANGES,
            );
        }

        // Without a length that makes sense, the size of a subnet is not
        // known: only what the ranges say between themselves is checked.
        $last = $vlan >= 1 && $vlan <= self::MAX_VLAN_PREFIX ? Ipv4::size($vlan) - 2 : null;

        $gateway = match (GatewayPosition::tryFrom($data['gateway_position'])) {
            GatewayPosition::First => 1,
            GatewayPosition::Last => $last,
            default => null,
        };

        // The first range that no longer fits, should the length of the
        // subnets be what has just changed.
        $leftOut = null;

        foreach ($ranges as $line => $range) {
            $start = $range['start_offset'];
            $end = $range['end_offset'];

            if ($start < 1) {
                $errors["ranges.{$line}.start_offset"] = __('The start is 1 at least: the offset 0 is the network address.');
            }

            if ($end < $start) {
                $errors["ranges.{$line}.end_offset"] = __('The end of the range cannot be lower than its start.');

                continue;
            }

            if ($last !== null && $end > $last) {
                $errors["ranges.{$line}.end_offset"] = __('The end is :max at most in a /:length subnet: its last address is the broadcast address.', [
                    'max' => $last,
                    'length' => $vlan,
                ]);

                $leftOut ??= $start.' – '.$end;
            }

            foreach (array_slice($ranges, 0, $line) as $other) {
                if ($other['end_offset'] >= $other['start_offset'] && $start <= $other['end_offset'] && $other['start_offset'] <= $end) {
                    $errors["ranges.{$line}.start_offset"] ??= __('This range overlaps the range :range.', [
                        'range' => $other['start_offset'].' – '.$other['end_offset'],
                    ]);

                    break;
                }
            }

            // The gateway is a fixed address: a DHCP server must not hand
            // it out.
            if ($range['kind'] === RangeKind::Dhcp->value && $gateway !== null && $start <= $gateway && $gateway <= $end) {
                $errors["ranges.{$line}.kind"] = __('A DHCP range cannot contain the gateway of the rule, at the offset :offset.', [
                    'offset' => $gateway,
                ]);
            }
        }

        // Shortening the subnets under ranges that are there is refused on
        // the length too, with the range it would leave out.
        if ($leftOut !== null && $rule !== null && $rule->vlan_prefix_length !== $vlan && ! isset($errors['vlan_prefix_length'])) {
            $errors['vlan_prefix_length'] = __('In a /:length subnet, the range :range no longer fits: an offset is :max at most.', [
                'length' => $vlan,
                'range' => $leftOut,
                'max' => $last,
            ]);
        }

        return $errors;
    }
}
