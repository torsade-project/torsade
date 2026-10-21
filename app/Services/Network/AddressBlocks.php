<?php

// torsade/app/Services/Network/AddressBlocks.php

namespace App\Services\Network;

use App\Casts\AsIpAddress;
use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Subnet;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to an address block goes through this class, whether it
 * comes from the interface or from a command: the rules are enforced here
 * and each change is written to the activity log.
 *
 * Only what makes no sense in any network is refused: two blocks of sites
 * that overlap, a block of a site around a block of the organisation, a
 * block inside a subnet. A subnet that is not where the blocks say is
 * never refused: it is a deviation.
 *
 * @phpstan-type BlockData array{network: string, prefix_length: int, site_id?: int|null, name?: string|null, description?: string|null, status?: string}
 */
final class AddressBlocks
{
    use PhrasesMessages;
    use TranslatesUniqueViolations;

    /**
     * How many prefixes the free space of a block is listed as.
     */
    public const int LISTED_FREE_PREFIXES = 50;

    public function __construct(private readonly ChangeLog $log) {}

    /**
     * @param  BlockData  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Actor $actor): AddressBlock
    {
        return DB::transaction(function () use ($data, $actor): AddressBlock {
            $block = new AddressBlock;

            $this->check($block, $data);

            $block->fill($this->attributes($data, $block));

            $this->save($block);

            $this->log->created(ActivityEvent::AddressBlockCreated, $block, $actor);

            return $block;
        });
    }

    /**
     * @param  BlockData  $data
     *
     * @throws ValidationException
     */
    public function update(AddressBlock $block, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($block, $data, $actor): void {
            $before = $block->auditValues();

            $this->check($block, $data);

            $block->fill($this->attributes($data, $block));

            $this->save($block);

            // The site may have changed: read it again for the log.
            $block->unsetRelation('site');

            $this->log->updated(ActivityEvent::AddressBlockUpdated, $block, $before, $actor);
        });
    }

    /**
     * No object of the network is tied to a block by a key: it can be
     * deleted, and the subnets it held stay as they are. Only the block the
     * addressing rule starts from is kept: the rule would mean nothing
     * without it.
     *
     * @throws ValidationException
     */
    public function delete(AddressBlock $block, Actor $actor): void
    {
        DB::transaction(function () use ($block, $actor): void {
            if ($this->ruleStartingFrom($block) !== null) {
                throw ValidationException::withMessages([
                    'address_block' => __('The addressing rule starts from this block: change the rule or delete it first.'),
                ]);
            }

            $this->log->deleted(ActivityEvent::AddressBlockDeleted, $block, $actor);

            $block->delete();
        });
    }

    /**
     * The blocks laid out by nesting. The blocks of the organisation come
     * in the order of their addresses, each one followed by what it holds:
     * other blocks of the organisation, and blocks of sites. The blocks of
     * sites that no block of the organisation holds come apart.
     *
     * @param  Collection<int, AddressBlock>  $blocks  Sorted by address, then from the largest to the smallest.
     * @return array{nested: list<array{block: AddressBlock, depth: int}>, outside: list<AddressBlock>}
     */
    public function tree(Collection $blocks): array
    {
        $nested = [];
        $outside = [];

        // The blocks of the organisation around the current one, from the
        // largest to the smallest.
        $around = [];

        foreach ($blocks as $block) {
            while ($around !== [] && ! Ipv4::prefixContains(
                $around[array_key_last($around)]->network,
                $around[array_key_last($around)]->prefix_length,
                $block->network,
                $block->prefix_length,
            )) {
                array_pop($around);
            }

            if ($block->site_id !== null && $around === []) {
                $outside[] = $block;

                continue;
            }

            $nested[] = ['block' => $block, 'depth' => count($around)];

            // Only a block of the organisation holds other blocks.
            if ($block->site_id === null) {
                $around[] = $block;
            }
        }

        return ['nested' => $nested, 'outside' => $outside];
    }

    /**
     * The subnets a block holds, in the order of their addresses. Two CIDR
     * blocks are either disjoint or nested: a subnet that starts inside the
     * block and is not larger than it lies inside it.
     *
     * @return Collection<int, Subnet>
     */
    public function subnetsIn(AddressBlock $block): Collection
    {
        return Subnet::query()
            ->where('ip_version', 4)
            ->whereBetween('network', [
                AsIpAddress::toBinary($block->network),
                AsIpAddress::toBinary(Ipv4::broadcast($block->network, $block->prefix_length)),
            ])
            ->where('prefix_length', '>=', $block->prefix_length)
            ->orderBy('network')
            ->get();
    }

    /**
     * How much of a block its subnets take, and what is left of it as CIDR
     * prefixes: the first ones are listed, all of them are counted.
     *
     * @param  iterable<Subnet>  $subnets  The subnets the block holds.
     * @return array{size: int, occupied: int, free: int, free_prefix_count: int, free_prefixes: list<array{cidr: string, size: int}>}
     */
    public function space(AddressBlock $block, iterable $subnets): array
    {
        $taken = [];

        foreach ($subnets as $subnet) {
            $taken[] = [$subnet->network, $subnet->prefix_length];
        }

        $free = Ipv4::freePrefixes($block->network, $block->prefix_length, $taken, self::LISTED_FREE_PREFIXES);

        return [
            'size' => Ipv4::size($block->prefix_length),
            'occupied' => $free['taken'],
            'free' => $free['addresses'],
            'free_prefix_count' => $free['count'],
            'free_prefixes' => array_map(
                fn (array $prefix): array => ['cidr' => $prefix['network'].'/'.$prefix['prefix'], 'size' => $prefix['size']],
                $free['prefixes'],
            ),
        ];
    }

    /**
     * @param  BlockData  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, AddressBlock $block): array
    {
        return [
            'site_id' => $data['site_id'] ?? null,
            'network' => $data['network'],
            'prefix_length' => $data['prefix_length'],
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? ($block->exists ? $block->status->value : Status::Active->value),
        ];
    }

    /**
     * Enforce the rules on the values about to be saved, for a new block or
     * for an existing one.
     *
     * @param  BlockData  $data
     *
     * @throws ValidationException
     */
    private function check(AddressBlock $block, array $data): void
    {
        $network = $data['network'];
        $prefix = $data['prefix_length'];
        $siteId = $data['site_id'] ?? null;

        if (! Ipv4::isValid($network)) {
            throw ValidationException::withMessages(['network' => __('Enter a valid IPv4 address, such as 192.168.1.10.')]);
        }

        if ($prefix < 1 || $prefix > Ipv4::MAX_PREFIX) {
            throw ValidationException::withMessages([
                'prefix_length' => __('The prefix length must be between 1 and :max.', ['max' => Ipv4::MAX_PREFIX]),
            ]);
        }

        // An address typed with host bits is most often a mistake about the
        // block: say which one it belongs to instead of guessing.
        if (! Ipv4::isNetworkAddress($network, $prefix)) {
            throw ValidationException::withMessages([
                'network' => __(':typed is not a network address. Did you mean :suggestion?', [
                    'typed' => $network.'/'.$prefix,
                    'suggestion' => Ipv4::network($network, $prefix).'/'.$prefix,
                ]),
            ]);
        }

        // The block the addressing rule starts from stays a block of the
        // organisation, large enough to be cut into blocks of sites. Its
        // address may change: the rule then proposes other addresses.
        $rule = $block->exists ? $this->ruleStartingFrom($block) : null;

        if ($rule !== null) {
            $errors = [];

            if ($siteId !== null) {
                $errors['site_id'] = __('The addressing rule starts from this block: it stays a block of the organisation.');
            }

            if ($rule->site_prefix_length !== null && $prefix >= $rule->site_prefix_length) {
                $errors['prefix_length'] = __('The addressing rule cuts this block into blocks of sites in /:length: its prefix length must stay lower than :length.', [
                    'length' => $rule->site_prefix_length,
                ]);
            } elseif ($rule->site_prefix_length === null && $prefix >= $rule->vlan_prefix_length) {
                $errors['prefix_length'] = __('The addressing rule cuts this block into subnets of VLANs in /:length: its prefix length must stay lower than :length.', [
                    'length' => $rule->vlan_prefix_length,
                ]);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        // The other blocks are locked, so that two simultaneous requests
        // cannot both pass.
        $others = AddressBlock::query()
            ->where('ip_version', 4)
            ->whereKeyNot($block->getKey())
            ->with('site')
            ->orderBy('network')
            ->orderBy('prefix_length')
            ->lockForUpdate()
            ->get();

        $holds = fn (AddressBlock $other): bool => Ipv4::prefixContains($network, $prefix, $other->network, $other->prefix_length);
        $isHeldBy = fn (AddressBlock $other): bool => Ipv4::prefixContains($other->network, $other->prefix_length, $network, $prefix);

        if ($others->contains(fn (AddressBlock $other): bool => $holds($other) && $isHeldBy($other))) {
            throw ValidationException::withMessages(['network' => $this->existsMessage()]);
        }

        $ofSites = $others->filter(fn (AddressBlock $other): bool => $other->site_id !== null);
        $ofOrganisation = $others->filter(fn (AddressBlock $other): bool => $other->site_id === null);

        if ($siteId !== null) {
            // The blocks of sites share nothing, whether they belong to the
            // same site or not.
            $overlapping = $ofSites->filter(fn (AddressBlock $other): bool => $holds($other) || $isHeldBy($other));

            if ($overlapping->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'network' => __('This block overlaps :blocks.', ['blocks' => $this->enumerate($overlapping->map($this->blockName(...))->all())]),
                ]);
            }

            $held = $ofOrganisation->filter($holds);

            if ($held->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'network' => __('A block of a site cannot contain a block of the organisation: it would contain :blocks.', [
                        'blocks' => $this->enumerate($held->map($this->blockName(...))->all()),
                    ]),
                ]);
            }
        } else {
            $holder = $ofSites->first($isHeldBy);

            if ($holder !== null) {
                throw ValidationException::withMessages([
                    'network' => __('A block of the organisation cannot be inside the block of a site: it would be inside :block.', [
                        'block' => $this->blockName($holder),
                    ]),
                ]);
            }
        }

        // Subnets never overlap: one of them at most is around the block.
        $subnet = Subnet::query()
            ->where('ip_version', 4)
            ->where('prefix_length', '<', $prefix)
            ->orderBy('network')
            ->lockForUpdate()
            ->get()
            ->first(fn (Subnet $subnet): bool => Ipv4::prefixContains($subnet->network, $subnet->prefix_length, $network, $prefix));

        if ($subnet !== null) {
            throw ValidationException::withMessages([
                'network' => __('A block cannot be inside a subnet: it would be inside the subnet :subnet.', ['subnet' => $subnet->cidr()]),
            ]);
        }
    }

    /**
     * The addressing rule, when it starts from the given block. The rule is
     * locked: it cannot be pointed at the block while this one changes.
     */
    private function ruleStartingFrom(AddressBlock $block): ?AddressingRule
    {
        return AddressingRule::query()
            ->where('aggregate_block_id', $block->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function existsMessage(): string
    {
        return __('This block already exists.');
    }

    /**
     * @throws ValidationException
     */
    private function save(AddressBlock $block): void
    {
        $this->guardingUniqueness(fn (): bool => $block->save(), [
            'address_blocks_network_prefix_length_unique' => ['network', $this->existsMessage()],
        ]);
    }
}
