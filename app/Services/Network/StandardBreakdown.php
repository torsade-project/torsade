<?php

// torsade/app/Services/Network/StandardBreakdown.php

namespace App\Services\Network;

use App\Enums\RangeKind;
use App\Models\AddressingRule;
use App\Models\IpRange;
use App\Models\Subnet;
use App\Support\Actor;
use App\Support\AddressingScheme;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The standard breakdown of the addressing rule, applied to a subnet: the
 * ranges of the rule, shifted from the network address of the subnet, are
 * created in it.
 *
 * The ranges are worked out here, from the rule in force and from the
 * subnet as it is stored: nothing of them comes from the browser. Each one
 * goes through the rules of ranges like a range typed by hand, and all of
 * them are created or none is.
 *
 * @phpstan-type PreviewRange array{kind: string, kind_label: string, start_address: string, end_address: string, name: string|null, size: int, fits: bool}
 */
final class StandardBreakdown
{
    public function __construct(private readonly IpRanges $ranges) {}

    /**
     * Whether the breakdown is offered for a subnet: the rule has ranges,
     * the kind of the subnet accepts some, and it has none yet. The count
     * of its ranges must be loaded as "ip_ranges_count", or is read.
     */
    public function isOffered(Subnet $subnet, ?AddressingScheme $scheme): bool
    {
        return $scheme !== null
            && $scheme->hasBreakdown()
            && $subnet->kind->allowsRanges()
            && ! $subnet->ipRanges()->exists();
    }

    /**
     * The ranges the breakdown would create in a subnet, to be shown before
     * anything is created. One that does not fit is listed too, and marked.
     *
     * @return list<PreviewRange>
     */
    public function preview(Subnet $subnet, AddressingScheme $scheme): array
    {
        return array_map(
            fn (array $range): array => [
                'kind' => $range['kind'],
                'kind_label' => __(RangeKind::from($range['kind'])->label()),
                'start_address' => $range['start_address'],
                'end_address' => $range['end_address'],
                'name' => $range['name'],
                'size' => Ipv4::rangeSize($range['start_address'], $range['end_address']),
                'fits' => $range['fits'],
            ],
            $scheme->breakdownOf($subnet->network, $subnet->prefix_length),
        );
    }

    /**
     * Create the ranges of the rule in a subnet that has none: all of
     * them, or none.
     *
     * @param  string  $field  Where a refusal is told.
     * @return list<IpRange>
     *
     * @throws ValidationException
     */
    public function apply(Subnet $subnet, Actor $actor, string $field = 'breakdown'): array
    {
        return DB::transaction(function () use ($subnet, $actor, $field): array {
            // The subnet is locked and read again: the ranges are laid out
            // in the subnet as it is stored.
            $subnet = Subnet::query()->whereKey($subnet->getKey())->lockForUpdate()->firstOrFail();

            $rule = AddressingRule::query()->with(['aggregateBlock', 'ranges'])->first();
            $scheme = $rule === null ? null : AddressingScheme::fromRule($rule);

            if ($scheme === null || ! $scheme->hasBreakdown()) {
                throw ValidationException::withMessages([
                    $field => __('The addressing rule has no standard breakdown to apply.'),
                ]);
            }

            if (! $subnet->kind->allowsRanges()) {
                throw ValidationException::withMessages([
                    $field => __('A subnet of the kind ":kind" has no ranges.', ['kind' => __($subnet->kind->label())]),
                ]);
            }

            if ($subnet->ipRanges()->exists()) {
                throw ValidationException::withMessages([
                    $field => __('This subnet already has ranges: the standard breakdown only applies to a subnet without any.'),
                ]);
            }

            $placed = $scheme->breakdownOf($subnet->network, $subnet->prefix_length);

            foreach ($placed as $range) {
                if (! $range['fits']) {
                    [$first, $last] = Ipv4::usableRange($subnet->network, $subnet->prefix_length);

                    throw ValidationException::withMessages([
                        $field => __('The standard breakdown does not fit in :cidr: the range :range goes beyond its usable addresses (:first – :last).', [
                            'cidr' => $subnet->cidr(),
                            'range' => $range['start_address'].' – '.$range['end_address'],
                            'first' => $first,
                            'last' => $last,
                        ]),
                    ]);
                }
            }

            $created = [];

            foreach ($placed as $range) {
                try {
                    $created[] = $this->ranges->create($subnet, [
                        'kind' => $range['kind'],
                        'start_address' => $range['start_address'],
                        'end_address' => $range['end_address'],
                        'name' => $range['name'],
                    ], $actor);
                } catch (ValidationException $exception) {
                    // A gateway or a static address in a DHCP range, for
                    // instance: the rule of ranges says which, and the
                    // range is named with it.
                    throw ValidationException::withMessages([
                        $field => __('The range :range cannot be created. :reason', [
                            'range' => $range['start_address'].' – '.$range['end_address'],
                            'reason' => collect($exception->errors())->flatten()->first(),
                        ]),
                    ]);
                }
            }

            return $created;
        });
    }
}
