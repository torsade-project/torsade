<?php

// torsade/app/Services/Network/Subnets.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\RangeKind;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\IpRange;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a subnet goes through this class, whether it comes from
 * the interface or from a command: the rules of the addressing plan are
 * enforced here and each change is written to the activity log.
 *
 * @phpstan-type FreeInterval array{start: string, end: string, count: int, kind: string|null, range: string|null}
 * @phpstan-type SubnetData array{network: string, prefix_length: int, kind?: string|null, vlan_id?: int|null, site_id?: int|null, gateway?: string|null, name?: string|null, description?: string|null, status?: string}
 */
final class Subnets
{
    use PhrasesMessages;
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly StandardBreakdown $breakdown,
    ) {}

    /**
     * Create a subnet and, when asked, cut it into the ranges of the
     * addressing rule. Both are saved together or not at all: ranges that
     * cannot be created leave no subnet behind.
     *
     * @param  SubnetData  $data
     *
     * @throws ValidationException A refused breakdown is told on "apply_breakdown".
     */
    public function create(array $data, Actor $actor, bool $withBreakdown = false): Subnet
    {
        return DB::transaction(function () use ($data, $actor, $withBreakdown): Subnet {
            $subnet = new Subnet;

            $this->check($subnet, $data);

            $subnet->fill($this->attributes($data, $subnet));

            $this->save($subnet);

            $this->log->created(ActivityEvent::SubnetCreated, $subnet, $actor);

            if ($withBreakdown) {
                // Worked out from the rule in force and from the subnet
                // just saved, never from what was typed.
                $this->breakdown->apply($subnet, $actor, 'apply_breakdown');
            }

            return $subnet;
        });
    }

    /**
     * @param  SubnetData  $data
     *
     * @throws ValidationException
     */
    public function update(Subnet $subnet, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($subnet, $data, $actor): void {
            $before = $subnet->auditValues();

            $this->check($subnet, $data);

            $subnet->fill($this->attributes($data, $subnet));

            $this->save($subnet);

            // The VLAN and the site may have changed: read them again
            // for the log.
            $subnet->unsetRelation('vlan')->unsetRelation('site');

            $this->log->updated(ActivityEvent::SubnetUpdated, $subnet, $before, $actor);
        });
    }

    /**
     * The subnet of a VLAN follows it to another site. Called within the
     * transaction that moves the VLAN, with the values of the subnet read
     * before the move.
     *
     * @param  array<string, scalar|null>  $before
     */
    public function followVlan(Subnet $subnet, Vlan $vlan, array $before, Actor $actor): void
    {
        $subnet->site_id = $vlan->site_id;
        $subnet->save();

        $subnet->setRelation('vlan', $vlan)->unsetRelation('site');

        $this->log->updated(ActivityEvent::SubnetUpdated, $subnet, $before, $actor);
    }

    /**
     * A subnet is only deleted once no address is documented in it. Its DHCP
     * ranges go with it, each deletion being logged.
     *
     * @throws ValidationException
     */
    public function delete(Subnet $subnet, Actor $actor): void
    {
        DB::transaction(function () use ($subnet, $actor): void {
            $addresses = $subnet->ipAddresses()->lockForUpdate()->count();

            if ($addresses > 0) {
                throw ValidationException::withMessages([
                    'subnet' => trans_choice(
                        'An IP address is still documented in this subnet: delete it first.|:count IP addresses are still documented in this subnet: delete them first.',
                        $addresses,
                    ),
                ]);
            }

            foreach ($subnet->ipRanges()->orderBy('start_address')->get() as $range) {
                $range->setRelation('subnet', $subnet);

                $this->log->deleted(ActivityEvent::IpRangeDeleted, $range, $actor);

                $range->delete();
            }

            $this->log->deleted(ActivityEvent::SubnetDeleted, $subnet, $actor);

            $subnet->delete();
        });
    }

    /**
     * The usable addresses of the subnet that are neither the gateway, nor in
     * a DHCP range, nor documented, as intervals. Each interval is cut at
     * the bounds of the static and reserved ranges, and says which one it
     * lies in: where a free address is says whether it should be used.
     *
     * @return list<FreeInterval>
     */
    public function freeIntervals(Subnet $subnet): array
    {
        /** @var list<string> $addresses */
        $addresses = $subnet->ipAddresses()->pluck('address')->all();

        if ($subnet->gateway !== null) {
            $addresses[] = $subnet->gateway;
        }

        $ranges = $subnet->ipRanges()->orderBy('start_address')->get();

        $free = Ipv4::freeIntervals(
            $subnet->network,
            $subnet->prefix_length,
            $addresses,
            $ranges
                ->filter(fn (IpRange $range): bool => $range->kind === RangeKind::Dhcp)
                ->map(fn (IpRange $range): array => [$range->start_address, $range->end_address])
                ->all(),
        );

        $names = [];
        $marks = [];

        foreach ($ranges as $range) {
            if ($range->kind !== RangeKind::Dhcp) {
                $marks[] = [$range->start_address, $range->end_address, (string) $range->id];
                $names[(string) $range->id] = [$range->kind->value, $range->name];
            }
        }

        return array_map(
            fn (array $piece): array => [
                'start' => $piece['start'],
                'end' => $piece['end'],
                'count' => $piece['count'],
                // "static", "reserved", or null outside of every range.
                'kind' => $piece['mark'] === null ? null : $names[$piece['mark']][0],
                'range' => $piece['mark'] === null ? null : $names[$piece['mark']][1],
            ],
            Ipv4::markIntervals($free, $marks),
        );
    }

    /**
     * The address to document next: the lowest free address of a static
     * range and, failing that, the lowest one outside of every range. A
     * free address of a reserved range is never proposed, and those of a
     * DHCP range are not free at all.
     *
     * @param  list<FreeInterval>  $free  What freeIntervals() returned.
     * @return array{address: string, kind: string|null, range: string|null}|null Null when there is nothing to propose.
     */
    public function nextFreeAddress(array $free): ?array
    {
        foreach ([RangeKind::Static->value, null] as $kind) {
            foreach ($free as $interval) {
                if ($interval['kind'] === $kind) {
                    return ['address' => $interval['start'], 'kind' => $kind, 'range' => $interval['range']];
                }
            }
        }

        return null;
    }

    /**
     * @param  SubnetData  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Subnet $subnet): array
    {
        $vlanId = $data['vlan_id'] ?? null;

        return [
            'vlan_id' => $vlanId,
            // A subnet carried by a VLAN is on the site of that VLAN,
            // whatever was asked for.
            'site_id' => $vlanId === null
                ? ($data['site_id'] ?? null)
                : Vlan::query()->whereKey($vlanId)->value('site_id'),
            'kind' => $this->kind($subnet, $data)->value,
            'network' => $data['network'],
            'prefix_length' => $data['prefix_length'],
            'gateway' => $data['gateway'] ?? null,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? ($subnet->exists ? $subnet->status->value : Status::Active->value),
        ];
    }

    /**
     * The kind asked for; failing that, the one the subnet already has, or
     * a segment for a new subnet.
     *
     * @param  SubnetData  $data
     */
    private function kind(Subnet $subnet, array $data): SubnetKind
    {
        return SubnetKind::tryFrom($data['kind'] ?? '')
            ?? ($subnet->exists ? $subnet->kind : SubnetKind::Segment);
    }

    /**
     * What the kind of a subnet does not accept: a link between two routers
     * or the address of a router has neither gateway nor ranges, and its
     * size is fixed.
     *
     * A subnet that changes kind is refused as a whole, with everything
     * that stands in the way in one message; otherwise each refusal is told
     * on its field.
     *
     * @return array<string, string> Messages by field.
     */
    private function kindErrors(Subnet $subnet, SubnetKind $kind, ?int $vlanId, int $prefix, ?string $gateway): array
    {
        $label = __($kind->label());

        $errors = [];
        $inTheWay = [];

        if ($vlanId !== null && ! $kind->allowsVlan()) {
            $errors['vlan_id'] = __('A subnet of the kind ":kind" is not carried by a VLAN.', ['kind' => $label]);
            $inTheWay[] = __('it is carried by a VLAN');
        }

        if (! $kind->allowsPrefix($prefix)) {
            $expected = $kind === SubnetKind::PointToPoint ? __('/30 or /31') : '/'.implode(', /', $kind->prefixLengths());

            $errors['prefix_length'] = __('The prefix of a subnet of the kind ":kind" is :expected.', ['kind' => $label, 'expected' => $expected]);
            $inTheWay[] = __('its prefix is /:prefix (expected: :expected)', ['prefix' => $prefix, 'expected' => $expected]);
        }

        if ($gateway !== null && ! $kind->allowsGateway()) {
            $errors['gateway'] = __('A subnet of the kind ":kind" has no gateway.', ['kind' => $label]);
            $inTheWay[] = __('it has a gateway (:gateway)', ['gateway' => $gateway]);
        }

        if ($subnet->exists && ! $kind->allowsRanges()) {
            $ranges = $subnet->ipRanges()->count();

            if ($ranges > 0) {
                $errors['kind'] = trans_choice(
                    'A subnet of the kind ":kind" has no ranges: delete its range first.|A subnet of the kind ":kind" has no ranges: delete its :count ranges first.',
                    $ranges,
                    ['kind' => $label],
                );
                $inTheWay[] = trans_choice('it has a range|it has :count ranges', $ranges);
            }
        }

        if ($inTheWay !== [] && $subnet->exists && $subnet->kind !== $kind) {
            return ['kind' => __('This subnet cannot become ":kind": :reasons.', ['kind' => $label, 'reasons' => implode(', ', $inTheWay)])];
        }

        return $errors;
    }

    /**
     * Enforce the rules of the addressing plan on the values about to be
     * saved, for a new subnet or for an existing one.
     *
     * @param  SubnetData  $data
     *
     * @throws ValidationException
     */
    private function check(Subnet $subnet, array $data): void
    {
        $network = $data['network'];
        $prefix = $data['prefix_length'];
        $gateway = $data['gateway'] ?? null;
        $vlanId = $data['vlan_id'] ?? null;
        $kind = $this->kind($subnet, $data);

        if (! Ipv4::isValid($network)) {
            throw ValidationException::withMessages(['network' => __('Enter a valid IPv4 address, such as 192.168.1.10.')]);
        }

        if ($prefix < 1 || $prefix > Ipv4::MAX_PREFIX) {
            throw ValidationException::withMessages([
                'prefix_length' => __('The prefix length must be between 1 and :max.', ['max' => Ipv4::MAX_PREFIX]),
            ]);
        }

        // An address typed with host bits is most often a mistake about the
        // subnet: say which network it belongs to instead of guessing.
        if (! Ipv4::isNetworkAddress($network, $prefix)) {
            throw ValidationException::withMessages([
                'network' => __(':typed is not a network address. Did you mean :suggestion?', [
                    'typed' => $network.'/'.$prefix,
                    'suggestion' => Ipv4::network($network, $prefix).'/'.$prefix,
                ]),
            ]);
        }

        $errors = [];

        $overlapping = $this->overlapping($subnet, $network, $prefix);

        if ($overlapping !== []) {
            $errors['network'] = __('This subnet overlaps :subnets.', ['subnets' => $this->enumerate($overlapping)]);
        } else {
            // A block is cut into subnets, never the other way round. A
            // subnet as large as a block is fine: a small site may be a
            // single network.
            $held = AddressBlock::query()
                ->where('ip_version', 4)
                ->where('prefix_length', '>', $prefix)
                ->with('site')
                ->orderBy('network')
                ->lockForUpdate()
                ->get()
                ->filter(fn (AddressBlock $block): bool => Ipv4::prefixContains($network, $prefix, $block->network, $block->prefix_length));

            if ($held->isNotEmpty()) {
                $errors['network'] = __('A subnet cannot contain a block: it would contain :blocks.', [
                    'blocks' => $this->enumerate($held->map($this->blockName(...))->all()),
                ]);
            }
        }

        // Where a gateway is not accepted at all, where it lies does not
        // matter: the kind says so below.
        $gatewayError = $kind->allowsGateway() ? $this->gatewayError($subnet, $network, $prefix, $gateway) : null;

        if ($gatewayError !== null) {
            $errors['gateway'] = $gatewayError;
        }

        if ($subnet->exists && ! isset($errors['network']) && ($network !== $subnet->network || $prefix !== $subnet->prefix_length)) {
            $leftOut = $this->leftOut($subnet, $network, $prefix, $gatewayError === null ? null : $gateway);

            if ($leftOut !== []) {
                $errors['network'] = __('This change would leave out :items.', ['items' => $this->enumerate($leftOut)]);
            }
        }

        if ($vlanId !== null) {
            $other = Subnet::query()->where('vlan_id', $vlanId)->whereKeyNot($subnet->getKey())->first();

            if ($other !== null) {
                $errors['vlan_id'] = $this->vlanTakenMessage($other->cidr());
            }
        }

        $errors = [...$errors, ...$this->kindErrors($subnet, $kind, $vlanId, $prefix, $gateway)];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The other subnets that share addresses with the given block, whatever
     * their status: there is a single addressing domain. The rows are locked
     * so that two simultaneous requests cannot both pass.
     *
     * @return list<string>
     */
    private function overlapping(Subnet $subnet, string $network, int $prefix): array
    {
        return array_values(
            Subnet::query()
                ->where('ip_version', 4)
                ->whereKeyNot($subnet->getKey())
                ->orderBy('network')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Subnet $other): bool => Ipv4::overlaps($network, $prefix, $other->network, $other->prefix_length))
                ->map(fn (Subnet $other): string => $other->cidr())
                ->all(),
        );
    }

    /**
     * The gateway must be an address a router can hold in the subnet, and
     * must stay out of the DHCP ranges, like any fixed address.
     */
    private function gatewayError(Subnet $subnet, string $network, int $prefix, ?string $gateway): ?string
    {
        if ($gateway === null) {
            return null;
        }

        if (! Ipv4::isValid($gateway)) {
            return __('Enter a valid IPv4 address, such as 192.168.1.10.');
        }

        if (! Ipv4::contains($network, $prefix, $gateway)) {
            return __('The gateway must be inside the subnet.');
        }

        if (! Ipv4::isUsable($network, $prefix, $gateway)) {
            return __('The gateway cannot be the network address or the broadcast address of the subnet.');
        }

        if ($subnet->exists) {
            foreach ($subnet->dhcpRanges()->get() as $range) {
                if (Ipv4::inRange($gateway, $range->start_address, $range->end_address)) {
                    return __('The gateway is inside the DHCP range :range.', ['range' => $range->label()]);
                }
            }
        }

        return null;
    }

    /**
     * What the subnet already holds and would no longer fit in the new block.
     *
     * @param  string|null  $refusedGateway  The gateway, when it was refused for the new block.
     * @return list<string>
     */
    private function leftOut(Subnet $subnet, string $network, int $prefix, ?string $refusedGateway): array
    {
        $items = [];

        if ($refusedGateway !== null && Ipv4::isValid($refusedGateway) && ! Ipv4::isUsable($network, $prefix, $refusedGateway)) {
            $items[] = __('the gateway :address', ['address' => $refusedGateway]);
        }

        foreach ($subnet->ipRanges()->orderBy('start_address')->get() as $range) {
            if (! Ipv4::isUsable($network, $prefix, $range->start_address) || ! Ipv4::isUsable($network, $prefix, $range->end_address)) {
                $items[] = __('the range :range', ['range' => $range->label()]);
            }
        }

        /** @var list<string> $addresses */
        $addresses = $subnet->ipAddresses()->orderBy('address')->lockForUpdate()->pluck('address')->all();

        foreach ($addresses as $address) {
            if (! Ipv4::isUsable($network, $prefix, $address)) {
                $items[] = __('the address :address', ['address' => $address]);
            }
        }

        return $items;
    }

    private function vlanTakenMessage(?string $cidr = null): string
    {
        return $cidr === null
            ? __('This VLAN already has a subnet.')
            : __('This VLAN already has a subnet (:cidr).', ['cidr' => $cidr]);
    }

    /**
     * @throws ValidationException
     */
    private function save(Subnet $subnet): void
    {
        $this->guardingUniqueness(fn (): bool => $subnet->save(), [
            'subnets_network_prefix_length_unique' => ['network', __('This subnet already exists.')],
            'subnets_vlan_id_unique' => ['vlan_id', $this->vlanTakenMessage()],
        ]);
    }
}
