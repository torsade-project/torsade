<?php

// torsade/tests/Feature/Network/TypedRangeTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\RangeKind;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Subnet;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * A range of a subnet has a kind: handed out by a DHCP server, kept for
 * static addresses, or reserved. The kind says what the range may contain,
 * and where the next address is taken from.
 */
class TypedRangeTest extends NetworkTestCase
{
    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function store(string $kind, string $start, string $end, array $extra = []): TestResponse
    {
        return $this->actingAs($this->editor())->post(route('ip-ranges.store', $this->subnet), [
            'kind' => $kind,
            'start_address' => $start,
            'end_address' => $end,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function update(IpRange $range, string $kind, string $start, string $end, array $extra = []): TestResponse
    {
        return $this->actingAs($this->editor())->put(route('ip-ranges.update', $range), [
            'kind' => $kind,
            'start_address' => $start,
            'end_address' => $end,
            ...$extra,
        ]);
    }

    /**
     * What the page of the subnet receives about its free addresses.
     *
     * @return array{intervals: list<array<string, mixed>>, count: int, next: array<string, mixed>|null}
     */
    private function free(?Subnet $subnet = null): array
    {
        $response = $this->actingAs($this->reader())->get(route('subnets.show', $subnet ?? $this->subnet))->assertOk();

        return [
            'intervals' => (array) $response->inertiaProps('freeIntervals'),
            'count' => (int) $response->inertiaProps('freeCount'),
            'next' => $response->inertiaProps('nextFreeAddress'),
        ];
    }

    // --- The three kinds --------------------------------------------------------

    public function test_a_range_is_a_dhcp_a_static_or_a_reserved_range(): void
    {
        $this->assertSame(['dhcp', 'static', 'reserved'], array_column(RangeKind::cases(), 'value'));
        $this->assertSame(
            [['value' => 'dhcp', 'label' => 'DHCP'], ['value' => 'static', 'label' => 'Fixe'], ['value' => 'reserved', 'label' => 'Réservée']],
            RangeKind::options(),
        );
    }

    public function test_an_editor_adds_ranges_of_the_three_kinds_with_a_name(): void
    {
        $this->store('static', '192.168.100.1', '192.168.100.49', ['name' => 'Infrastructure', 'description' => 'Switches and servers'])
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Plage créée.');
        $this->store('dhcp', '192.168.100.100', '192.168.100.199', ['name' => 'Postes'])->assertSessionHasNoErrors();
        $this->store('reserved', '192.168.100.200', '192.168.100.229')->assertSessionHasNoErrors();

        $ranges = IpRange::query()->orderBy('start_address')->get();

        $this->assertSame(
            [['static', 'Infrastructure'], ['dhcp', 'Postes'], ['reserved', null]],
            $ranges->map(fn (IpRange $range): array => [$range->kind->value, $range->name])->all(),
        );
        $this->assertSame('Switches and servers', $ranges[0]->description);

        // The page of the subnet shows each range with its kind.
        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->subnet))
            ->assertInertia(fn (Assert $page) => $page
                ->has('ipRanges', 3)
                ->where('ipRanges.0', [
                    'id' => $ranges[0]->id,
                    'kind' => 'static',
                    'kind_label' => 'Fixe',
                    'name' => 'Infrastructure',
                    'start_address' => '192.168.100.1',
                    'end_address' => '192.168.100.49',
                    'size' => 49,
                    'description' => 'Switches and servers',
                ])
                ->where('ipRanges.1.kind_label', 'DHCP')
                ->where('ipRanges.2.kind_label', 'Réservée')
                ->where('ipRanges.2.name', null)
                ->where('rangeKinds', RangeKind::options())
            );
    }

    public function test_a_range_must_say_its_kind(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('ip-ranges.store', $this->subnet), ['start_address' => '192.168.100.10', 'end_address' => '192.168.100.20']),
            'kind',
            'Le champ type est obligatoire.',
        );
        $this->assertRefused($this->store('dynamic', '192.168.100.10', '192.168.100.20'), 'kind');
        $this->assertRefused($this->store('static', '192.168.100.10', '192.168.100.20', ['name' => str_repeat('a', 65)]), 'name');

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    // --- What a range may contain ----------------------------------------------------

    public function test_two_ranges_never_overlap_whatever_their_kinds(): void
    {
        $this->store('dhcp', '192.168.100.100', '192.168.100.199')->assertSessionHasNoErrors();

        $message = 'Cette plage chevauche la plage 192.168.100.100 – 192.168.100.199.';

        foreach (['dhcp', 'static', 'reserved'] as $kind) {
            $this->assertRefused($this->store($kind, '192.168.100.150', '192.168.100.250'), 'start_address', $message);
            $this->assertRefused($this->store($kind, '192.168.100.199', '192.168.100.199'), 'start_address', $message);
        }

        $this->store('static', '192.168.100.1', '192.168.100.99')->assertSessionHasNoErrors();

        // A reserved range between the two would touch both.
        $this->assertRefused(
            $this->store('reserved', '192.168.100.99', '192.168.100.100'),
            'start_address',
            'Cette plage chevauche la plage 192.168.100.1 – 192.168.100.99.',
        );

        // Side by side, the three kinds live together.
        $this->store('reserved', '192.168.100.200', '192.168.100.253')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 3);
    }

    public function test_a_static_or_a_reserved_range_may_contain_the_gateway_and_static_addresses(): void
    {
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.250']);

        // What a DHCP range refuses.
        $this->assertRefused(
            $this->store('dhcp', '192.168.100.1', '192.168.100.49'),
            'start_address',
            "Une plage DHCP ne peut pas contenir d'adresses fixes : 192.168.100.10.",
        );
        $this->assertRefused(
            $this->store('dhcp', '192.168.100.251', '192.168.100.254'),
            'start_address',
            'Une plage DHCP ne peut pas contenir la passerelle (192.168.100.254).',
        );

        // The other two kinds accept it.
        $this->store('static', '192.168.100.1', '192.168.100.49')->assertSessionHasNoErrors();
        $this->store('reserved', '192.168.100.240', '192.168.100.254')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 2);
    }

    public function test_a_static_address_is_documented_in_a_static_or_a_reserved_range_but_not_in_a_dhcp_range(): void
    {
        $editor = $this->editor();

        IpRange::factory()->static()->between('192.168.100.1', '192.168.100.49')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.229')->create(['subnet_id' => $this->subnet->id]);

        $add = fn (string $address, string $assignment = 'static') => $this->actingAs($editor)
            ->post(route('ip-addresses.store', $this->subnet), ['address' => $address, 'assignment' => $assignment, 'status' => 'active']);

        $add('192.168.100.10')->assertSessionHasNoErrors();
        $add('192.168.100.210')->assertSessionHasNoErrors();

        $this->assertRefused(
            $add('192.168.100.150'),
            'address',
            'Une adresse fixe ne peut pas se trouver dans la plage DHCP 192.168.100.100 – 192.168.100.199. '
            .'Choisissez une autre adresse, ou faites-en une réservation DHCP.',
        );

        $add('192.168.100.150', 'dhcp_reservation')->assertSessionHasNoErrors();

        // The same in a quick entry.
        $response = $this->actingAs($editor)->post(route('ip-addresses.bulk', $this->subnet), [
            'addresses' => [['address' => '192.168.100.11'], ['address' => '192.168.100.211'], ['address' => '192.168.100.151']],
        ]);

        $response->assertRedirect();
        $this->assertSame(['addresses.2.address'], $this->errorFields());
    }

    public function test_the_gateway_may_move_into_a_static_or_a_reserved_range_but_not_into_a_dhcp_range(): void
    {
        $editor = $this->editor();

        IpRange::factory()->static()->between('192.168.100.1', '192.168.100.49')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.229')->create(['subnet_id' => $this->subnet->id]);

        $move = fn (string $gateway) => $this->actingAs($editor)
            ->put(route('subnets.update', $this->subnet), ['network' => '192.168.100.0/24', 'gateway' => $gateway, 'status' => 'active']);

        $move('192.168.100.1')->assertSessionHasNoErrors();
        $move('192.168.100.200')->assertSessionHasNoErrors();

        $this->assertRefused(
            $move('192.168.100.150'),
            'gateway',
            'La passerelle se trouve dans la plage DHCP 192.168.100.100 – 192.168.100.199.',
        );

        $this->assertSame('192.168.100.200', $this->subnet->refresh()->gateway);
    }

    // --- Changing the kind of a range -------------------------------------------------

    public function test_a_range_becomes_a_dhcp_range_only_if_it_holds_neither_the_gateway_nor_a_static_address(): void
    {
        $withAddresses = IpRange::factory()->static()->between('192.168.100.1', '192.168.100.49')->create(['subnet_id' => $this->subnet->id]);
        $withGateway = IpRange::factory()->reserved()->between('192.168.100.240', '192.168.100.254')->create(['subnet_id' => $this->subnet->id]);
        $empty = IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.229')->create(['subnet_id' => $this->subnet->id]);

        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.11', 'status' => 'retired']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.12']);

        // The kind is what is refused: the message is on it, and lists
        // what is in the way.
        $this->assertRefused(
            $this->update($withAddresses, 'dhcp', '192.168.100.1', '192.168.100.49'),
            'kind',
            "Une plage DHCP ne peut pas contenir d'adresses fixes : 192.168.100.10, 192.168.100.11.",
        );
        $this->assertRefused(
            $this->update($withGateway, 'dhcp', '192.168.100.240', '192.168.100.254'),
            'kind',
            'Une plage DHCP ne peut pas contenir la passerelle (192.168.100.254).',
        );

        $this->assertSame(RangeKind::Static, $withAddresses->refresh()->kind);
        $this->assertSame(RangeKind::Reserved, $withGateway->refresh()->kind);

        // A range that holds neither changes freely, both ways.
        $this->update($empty, 'dhcp', '192.168.100.200', '192.168.100.229')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Plage modifiée.');

        $this->assertSame(RangeKind::Dhcp, $empty->refresh()->kind);

        $this->update($empty, 'static', '192.168.100.200', '192.168.100.229', ['name' => 'Imprimantes'])->assertSessionHasNoErrors();

        $this->assertSame([RangeKind::Static, 'Imprimantes'], [$empty->refresh()->kind, $empty->name]);

        // Shrunk around what is in the way, the first one can change too.
        $this->update($withAddresses, 'dhcp', '192.168.100.20', '192.168.100.49')->assertSessionHasNoErrors();
    }

    public function test_a_dhcp_range_that_grows_over_a_static_address_is_refused_on_its_bounds(): void
    {
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.90']);

        $this->assertRefused(
            $this->update($range, 'dhcp', '192.168.100.80', '192.168.100.199'),
            'start_address',
            "Une plage DHCP ne peut pas contenir d'adresses fixes : 192.168.100.90.",
        );
    }

    public function test_the_life_of_a_typed_range_is_logged(): void
    {
        $this->store('static', '192.168.100.1', '192.168.100.49', ['name' => 'Infrastructure']);

        $range = IpRange::query()->sole();
        $created = $this->lastEntry(ActivityEvent::IpRangeCreated);

        $this->assertTrue($range->is($created->subject));
        $this->assertSame(
            [
                'subnet' => '192.168.100.0/24',
                'kind' => 'static',
                'start_address' => '192.168.100.1',
                'end_address' => '192.168.100.49',
                'name' => 'Infrastructure',
                'description' => null,
            ],
            $created->attribute_changes?->toArray()['attributes'],
        );

        $this->update($range, 'reserved', '192.168.100.1', '192.168.100.49', ['name' => 'Réserve']);

        $this->assertSame(
            ['old' => ['kind' => 'static', 'name' => 'Infrastructure'], 'attributes' => ['kind' => 'reserved', 'name' => 'Réserve']],
            $this->lastEntry(ActivityEvent::IpRangeUpdated)->attribute_changes?->toArray(),
        );

        $this->actingAs($this->editor())
            ->delete(route('ip-ranges.destroy', $range))
            ->assertInertiaFlash('toast.message', 'Plage supprimée.');

        $this->assertSame('reserved', $this->lastEntry(ActivityEvent::IpRangeDeleted)->attribute_changes?->toArray()['old']['kind']);

        // The log shows the kind in words.
        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => 'ip_range.updated']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Plage modifiée')
                ->where('entries.data.0.changes', [
                    ['field' => 'Type', 'old' => 'Fixe', 'new' => 'Réservée'],
                    ['field' => 'Nom', 'old' => 'Infrastructure', 'new' => 'Réserve'],
                ]));
    }

    // --- Free addresses, cut at the bounds of the ranges ------------------------------

    public function test_the_free_addresses_are_cut_at_the_bounds_of_the_ranges_and_say_where_they_lie(): void
    {
        IpRange::factory()->static()->between('192.168.100.1', '192.168.100.49')->create(['subnet_id' => $this->subnet->id, 'name' => 'Infrastructure']);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.229')->create(['subnet_id' => $this->subnet->id, 'name' => 'Extension']);

        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.1']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.60']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.210']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.150']);

        $free = $this->free();

        $this->assertSame([
            ['start' => '192.168.100.2', 'end' => '192.168.100.9', 'count' => 8, 'kind' => 'static', 'range' => 'Infrastructure'],
            ['start' => '192.168.100.11', 'end' => '192.168.100.49', 'count' => 39, 'kind' => 'static', 'range' => 'Infrastructure'],
            // What follows the static range is another interval, outside of
            // every range.
            ['start' => '192.168.100.50', 'end' => '192.168.100.59', 'count' => 10, 'kind' => null, 'range' => null],
            ['start' => '192.168.100.61', 'end' => '192.168.100.99', 'count' => 39, 'kind' => null, 'range' => null],
            // The DHCP range holds no free address at all.
            ['start' => '192.168.100.200', 'end' => '192.168.100.209', 'count' => 10, 'kind' => 'reserved', 'range' => 'Extension'],
            ['start' => '192.168.100.211', 'end' => '192.168.100.229', 'count' => 19, 'kind' => 'reserved', 'range' => 'Extension'],
            ['start' => '192.168.100.230', 'end' => '192.168.100.253', 'count' => 24, 'kind' => null, 'range' => null],
        ], $free['intervals']);

        // 254 usable, minus the gateway, the hundred addresses of the DHCP
        // range and four addresses documented outside of it.
        $this->assertSame(254 - 1 - 100 - 4, $free['count']);
        $this->assertSame(array_sum(array_column($free['intervals'], 'count')), $free['count']);
    }

    // --- The next free address ----------------------------------------------------------

    public function test_the_next_free_address_is_the_lowest_one_of_a_static_range(): void
    {
        // The static range is not the first thing in the subnet.
        IpRange::factory()->reserved()->between('192.168.100.1', '192.168.100.9')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->static()->between('192.168.100.50', '192.168.100.59')->create(['subnet_id' => $this->subnet->id, 'name' => 'Serveurs']);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);

        // Lower addresses are free, outside of every range: the static
        // range still comes first.
        $this->assertSame(
            ['address' => '192.168.100.50', 'kind' => 'static', 'range' => 'Serveurs'],
            $this->free()['next'],
        );

        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.50']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.51']);

        $this->assertSame('192.168.100.52', $this->free()['next']['address']);
    }

    public function test_with_two_static_ranges_the_lowest_address_wins(): void
    {
        IpRange::factory()->static()->between('192.168.100.200', '192.168.100.209')->create(['subnet_id' => $this->subnet->id, 'name' => 'Imprimantes']);
        IpRange::factory()->static()->between('192.168.100.20', '192.168.100.21')->create(['subnet_id' => $this->subnet->id]);

        $this->assertSame(['address' => '192.168.100.20', 'kind' => 'static', 'range' => null], $this->free()['next']);

        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.20']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.21']);

        // The first one is full: the second one takes over.
        $this->assertSame(['address' => '192.168.100.200', 'kind' => 'static', 'range' => 'Imprimantes'], $this->free()['next']);
    }

    public function test_without_a_free_address_in_a_static_range_the_next_one_is_the_lowest_outside_of_any_range(): void
    {
        // No range at all: as before.
        $this->assertSame(['address' => '192.168.100.1', 'kind' => null, 'range' => null], $this->free()['next']);

        IpRange::factory()->reserved()->between('192.168.100.1', '192.168.100.9')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->static()->between('192.168.100.10', '192.168.100.11')->create(['subnet_id' => $this->subnet->id]);
        IpRange::factory()->between('192.168.100.12', '192.168.100.99')->create(['subnet_id' => $this->subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.11']);

        // Neither in the reserved range, which comes first, nor in the
        // DHCP range.
        $this->assertSame(['address' => '192.168.100.100', 'kind' => null, 'range' => null], $this->free()['next']);
    }

    public function test_an_address_of_a_reserved_or_of_a_dhcp_range_is_never_proposed(): void
    {
        $subnet = $this->subnet('10.0.0.0/29', ['gateway' => '10.0.0.6']);

        IpRange::factory()->reserved()->between('10.0.0.1', '10.0.0.3')->create(['subnet_id' => $subnet->id]);
        IpRange::factory()->between('10.0.0.4', '10.0.0.5')->create(['subnet_id' => $subnet->id]);

        $free = $this->free($subnet);

        // Three addresses are free, all of them in the reserved range:
        // there is none to propose.
        $this->assertSame(
            [['start' => '10.0.0.1', 'end' => '10.0.0.3', 'count' => 3, 'kind' => 'reserved', 'range' => null]],
            $free['intervals'],
        );
        $this->assertSame(3, $free['count']);
        $this->assertNull($free['next']);

        // A full subnet has nothing to propose either.
        $full = $this->subnet('10.0.1.0/30');
        IpAddress::factory()->create(['subnet_id' => $full->id, 'address' => '10.0.1.1']);
        IpAddress::factory()->create(['subnet_id' => $full->id, 'address' => '10.0.1.2']);

        $this->assertSame(['intervals' => [], 'count' => 0, 'next' => null], $this->free($full));
    }

    public function test_an_address_of_a_reserved_range_can_still_be_documented_by_hand(): void
    {
        IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.229')->create(['subnet_id' => $this->subnet->id]);

        // Never proposed, never refused.
        $this->actingAs($this->editor())
            ->post(route('ip-addresses.store', $this->subnet), ['address' => '192.168.100.205', 'assignment' => 'static', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 1);
    }
}
