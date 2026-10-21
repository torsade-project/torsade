<?php

// torsade/tests/Feature/Network/LogicalLayoutTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\DiagramNode;
use App\Enums\DiagramView;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Device;
use App\Models\DiagramPosition;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page of the logical diagram of a site, and its layout placed by hand:
 * devices, subnets and the internet, read with the page, saved all at once
 * over the version they were read at, refused in words when they cannot
 * be, and given back to the automatic layout; apart from the layout of the
 * physical diagram.
 */
class LogicalLayoutTest extends NetworkTestCase
{
    private Site $office;

    private Site $workshop;

    /** @var array<string, Device> */
    private array $devices = [];

    /** @var array<string, Subnet> */
    private array $subnets = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
        $this->workshop = Site::factory()->create(['name' => 'Atelier', 'code' => 'ATE']);

        // The access of the carrier and the network of the offices, the
        // firewall between them, a core switch; a subnet of the workshop.
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique']);

        $this->subnets['carrier'] = $this->subnet('203.0.113.8/29', ['site_id' => $this->office->id, 'kind' => SubnetKind::External]);
        $this->subnets['offices'] = $this->subnet('10.10.10.0/24', ['vlan_id' => $vlan->id, 'gateway' => '10.10.10.1']);
        $this->subnets['workshop'] = $this->subnet('10.20.10.0/24', ['site_id' => $this->workshop->id]);

        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('sw-atelier', DeviceType::Switch, $this->workshop);

        $this->address('carrier', '203.0.113.10', 'fw-siege');
        $this->address('offices', '10.10.10.1', 'fw-siege');
        $this->address('offices', '10.10.10.2', 'sw-coeur');
    }

    private function device(string $name, DeviceType $type, ?Site $site = null, Status $status = Status::Active): Device
    {
        return $this->devices[$name] = Device::factory()->create([
            'site_id' => ($site ?? $this->office)->id,
            'name' => $name,
            'type' => $type,
            'status' => $status,
        ]);
    }

    private function address(string $subnet, string $address, string $device): void
    {
        IpAddress::factory()->create(['subnet_id' => $this->subnets[$subnet]->id, 'address' => $address, 'device_id' => $this->devices[$device]->id]);
    }

    /**
     * The key of a node on the diagram, from its name: a device, a subnet,
     * or the internet.
     */
    private function key(string $name): string
    {
        return match (true) {
            $name === 'internet' => 'internet',
            isset($this->subnets[$name]) => 'subnet-'.$this->subnets[$name]->id,
            default => 'device-'.$this->devices[$name]->id,
        };
    }

    /**
     * The props of the page of the logical diagram.
     *
     * @return array<string, mixed>
     */
    private function page(?User $user = null, ?Site $site = null): array
    {
        $props = [];

        $this->actingAs($user ?? $this->reader())
            ->get(route('diagrams.logical', ['site' => ($site ?? $this->office)->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props): void {
                $page->component('diagrams/Logical');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * @return array{positions: list<array{node: string, x: int, y: int}>, version: string}
     */
    private function layout(?User $user = null): array
    {
        /** @var array{positions: list<array{node: string, x: int, y: int}>, version: string} $layout */
        $layout = $this->page($user)['layout'];

        return $layout;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $positions  By the name of the node.
     * @return TestResponse<Response>
     */
    private function save(array $positions, string $version, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->editor())->put(
            route('sites.diagrams.logical.layout.update', $this->office),
            [
                'version' => $version,
                'positions' => array_map(
                    fn (string $name, array $at): array => ['node' => $this->key($name), 'x' => $at[0], 'y' => $at[1]],
                    array_keys($positions),
                    $positions,
                ),
            ],
        );
    }

    /**
     * The positions stored for a diagram of the head office, by node key.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function stored(DiagramView $view = DiagramView::Logical): array
    {
        return DiagramPosition::query()
            ->where('site_id', $this->office->id)
            ->where('view', $view->value)
            ->get()
            ->mapWithKeys(fn (DiagramPosition $position): array => [
                match ($position->node) {
                    DiagramNode::Internet => 'internet',
                    DiagramNode::Subnet => 'subnet-'.$position->subnet_id,
                    DiagramNode::Device => 'device-'.$position->device_id,
                } => [$position->x, $position->y],
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $positions  By the name of the node.
     * @return array<string, array{0: int, 1: int}>
     */
    private function keyed(array $positions): array
    {
        $keyed = [];

        foreach ($positions as $name => $at) {
            $keyed[$this->key($name)] = $at;
        }

        ksort($keyed);

        return $keyed;
    }

    // --- The page -----------------------------------------------------------

    public function test_the_page_draws_the_diagram_of_the_site_in_its_address_for_every_role(): void
    {
        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $props = $this->page($user);

            $this->assertSame(['id' => $this->office->id, 'name' => 'Siège', 'code' => 'SIE'], $props['site']);
            $this->assertSame(['Atelier', 'Siège'], array_column($props['sites'], 'name'));
            $this->assertSame(['10.10.10.0/24', '203.0.113.8/29'], array_column($props['diagram']['subnets'], 'cidr'));
            $this->assertSame(['positions' => [], 'version' => hash('sha256', '')], $props['layout']);
            $this->assertSame($user->role->value !== 'reader', $props['canEditLayout']);
        }

        // Without a site, the first one by name; an unknown one is not
        // found.
        $this->actingAs($this->reader())->get(route('diagrams.logical'))->assertRedirect(route('diagrams.logical', ['site' => $this->workshop->id]));
        $this->actingAs($this->reader())->get(route('diagrams.logical', ['site' => 999999999]))->assertNotFound();

        // Nobody without an account.
        auth()->logout();

        $this->get(route('diagrams.logical', ['site' => $this->office->id]))->assertRedirect(route('login'));
    }

    public function test_without_any_site_the_page_says_so(): void
    {
        DiagramPosition::query()->delete();
        IpAddress::query()->delete();
        Device::query()->delete();
        Subnet::query()->delete();
        Vlan::query()->delete();
        Site::query()->delete();

        $this->actingAs($this->reader())
            ->get(route('diagrams.logical'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagrams/Logical')
                ->where('site', null)
                ->where('diagram', null)
                ->where('layout', null));
    }

    // --- Reading and saving -------------------------------------------------

    public function test_a_layout_of_devices_subnets_and_the_internet_is_saved_all_at_once_with_one_entry_of_the_log(): void
    {
        $version = $this->layout()['version'];
        $positions = ['internet' => [0, -160], 'carrier' => [-40, -80], 'fw-siege' => [0, 0], 'offices' => [-200, 80], 'sw-coeur' => [8, 160], 'sw-atelier' => [1_000_000, -1_000_000]];

        // The device of the workshop is not drawn: it has nothing on the
        // network of the head office.
        $this->save($positions, $version)->assertSessionHasErrors('positions');
        $this->assertSame([], $this->stored());

        unset($positions['sw-atelier']);

        $this->save($positions, $version)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('diagrams.logical', ['site' => $this->office->id]));

        $this->assertSame($this->keyed($positions), $this->stored());

        // One entry, on the site, which tells the view and the number of
        // elements placed.
        $this->assertSame(1, $this->countEntries(ActivityEvent::SiteDiagramSaved));

        $entry = $this->lastEntry(ActivityEvent::SiteDiagramSaved);

        $this->assertSame($this->office->id, $entry->subject_id);
        $this->assertSame(['diagram' => 'logical', 'nodes_count' => 5], ($entry->attribute_changes?->toArray() ?? [])['attributes']);

        // The page reads them, by their key, under another version.
        $layout = $this->layout();

        $this->assertEqualsCanonicalizing(
            array_map(fn (string $key, array $at): array => ['node' => $key, 'x' => $at[0], 'y' => $at[1]], array_keys($this->keyed($positions)), $this->keyed($positions)),
            $layout['positions'],
        );
        $this->assertNotSame($version, $layout['version']);

        // Saved again: what is not sent is not placed any more.
        $this->save(['offices' => [16, 16]], $layout['version'])->assertSessionHasNoErrors();
        $this->assertSame($this->keyed(['offices' => [16, 16]]), $this->stored());
    }

    public function test_the_layouts_of_the_two_diagrams_of_a_site_are_apart(): void
    {
        // A position of the physical diagram, of a device both draw.
        DiagramPosition::query()->create(['site_id' => $this->office->id, 'view' => DiagramView::Physical, 'node' => DiagramNode::Device, 'device_id' => $this->devices['fw-siege']->id, 'x' => 8, 'y' => 8]);

        $this->assertSame([], $this->layout()['positions']);

        $this->save(['fw-siege' => [80, 80]], $this->layout()['version'])->assertSessionHasNoErrors();

        $this->assertSame($this->keyed(['fw-siege' => [8, 8]]), $this->stored(DiagramView::Physical));
        $this->assertSame($this->keyed(['fw-siege' => [80, 80]]), $this->stored());

        $this->actingAs($this->editor())->delete(route('sites.diagrams.logical.layout.destroy', $this->office))->assertSessionHasNoErrors();

        $this->assertSame($this->keyed(['fw-siege' => [8, 8]]), $this->stored(DiagramView::Physical));
        $this->assertSame([], $this->stored());
    }

    public function test_the_position_of_a_node_no_longer_drawn_is_left_out(): void
    {
        $this->save(['internet' => [0, 0], 'carrier' => [0, 80], 'fw-siege' => [0, 160]], $this->layout()['version'])->assertSessionHasNoErrors();

        // Retired, the subnet of the carrier is drawn no more, nor the
        // internet with it.
        $this->subnets['carrier']->update(['status' => Status::Retired]);

        $this->assertSame([['node' => $this->key('fw-siege'), 'x' => 0, 'y' => 160]], $this->layout()['positions']);
    }

    public function test_a_layout_changed_in_the_meantime_is_refused_and_nothing_is_written(): void
    {
        $version = $this->layout()['version'];

        $this->save(['offices' => [0, 0]], $version)->assertSessionHasNoErrors();

        $this->assertRefused($this->save(['offices' => [80, 80]], $version), 'version', 'La disposition a été modifiée entre-temps : rechargez la page.');
        $this->assertSame($this->keyed(['offices' => [0, 0]]), $this->stored());
        $this->assertSame(1, $this->countEntries(ActivityEvent::SiteDiagramSaved));
    }

    public function test_a_node_not_drawn_on_the_diagram_is_refused_in_words(): void
    {
        $version = $this->layout()['version'];

        $this->device('sw-ancien', DeviceType::Switch, status: Status::Retired);

        foreach (['workshop', 'sw-atelier', 'sw-ancien'] as $name) {
            $this->assertRefused(
                $this->save(['fw-siege' => [0, 0], $name => [0, 160]], $version),
                'positions',
                'Certains de ces éléments ne sont plus dessinés sur ce schéma : rechargez la page.',
            );
        }

        // Without an external subnet, no internet.
        $this->subnets['carrier']->update(['kind' => SubnetKind::Segment]);

        $this->assertRefused($this->save(['internet' => [0, 0]], $version), 'positions', 'Certains de ces éléments ne sont plus dessinés sur ce schéma : rechargez la page.');
        $this->assertSame([], $this->stored());
    }

    public function test_a_position_out_of_the_bounds_off_the_grid_or_of_no_node_is_refused_in_words(): void
    {
        $version = $this->layout()['version'];
        $put = fn (array $payload): TestResponse => $this->actingAs($this->editor())->put(route('sites.diagrams.logical.layout.update', $this->office), $payload);
        $line = fn (mixed $x, mixed $y = 0, mixed $node = 'internet'): array => ['node' => $node, 'x' => $x, 'y' => $y];

        foreach ([
            [[$line(1_000_008)], 'positions.0.x', 'Un élément est placé hors des limites du schéma : rechargez la page.'],
            [[$line(0, 7)], 'positions.0.y', 'Un élément est placé hors de la grille du schéma : rechargez la page.'],
            [[$line(0), $line(8)], 'positions.1.node', 'Un élément est placé deux fois : rechargez la page.'],
            [[$line(0, 0, 'router-1')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 'device-0')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 'subnet-dix')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 'internet-1')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 12)], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[['device' => $this->devices['fw-siege']->id, 'x' => 0, 'y' => 0]], 'positions.0', "La disposition n'a pas pu être lue : rechargez la page."],
            [[], 'positions', "La disposition n'a pas pu être lue : rechargez la page."],
        ] as [$positions, $field, $message]) {
            $this->assertRefused($put(['version' => $version, 'positions' => $positions]), $field, $message);
        }

        $this->assertSame([], $this->stored());
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDiagramSaved));
    }

    public function test_saving_200_positions_costs_the_same_as_saving_three(): void
    {
        $queries = 0;

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $measure = function (array $positions) use (&$queries): int {
            $version = $this->layout()['version'];
            $queries = 0;

            $this->save($positions, $version)->assertSessionHasNoErrors();

            return $queries;
        };

        $few = $measure(['internet' => [0, 0], 'carrier' => [0, 80], 'fw-siege' => [0, 160]]);

        // Then two hundred nodes: the internet, fifty subnets and a hundred
        // and forty-nine switches, all placed.
        $positions = ['internet' => [0, 0], 'carrier' => [0, 80], 'offices' => [0, 240], 'fw-siege' => [0, 160], 'sw-coeur' => [0, 320]];

        foreach (range(1, 48) as $number) {
            $this->subnets['net-'.$number] = $this->subnet('10.30.'.$number.'.0/24', ['site_id' => $this->office->id]);
            $positions['net-'.$number] = [8 * $number, 400];
        }

        foreach (range(1, 147) as $number) {
            $this->device('sw-'.$number, DeviceType::Switch);
            $positions['sw-'.$number] = [8 * $number, 640];
        }

        $this->assertCount(200, $positions);

        $many = $measure($positions);

        $this->assertCount(200, $this->stored());
        $this->assertSame($few, $many);
    }

    // --- Back to the automatic layout ---------------------------------------

    public function test_the_automatic_layout_comes_back_with_one_entry_of_the_log_which_tells_the_view(): void
    {
        $this->save(['internet' => [0, 0], 'carrier' => [0, 80], 'fw-siege' => [0, 160]], $this->layout()['version'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor())
            ->delete(route('sites.diagrams.logical.layout.destroy', $this->office))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('diagrams.logical', ['site' => $this->office->id]));

        $this->assertSame([], $this->stored());

        $entry = $this->lastEntry(ActivityEvent::SiteDiagramReset);

        $this->assertSame($this->office->id, $entry->subject_id);
        $this->assertSame(['diagram' => 'logical', 'nodes_count' => 3], ($entry->attribute_changes?->toArray() ?? [])['old']);

        // The log page tells both, the view named.
        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event', 'site.diagram_reset')
                ->where('entries.data.0.changes.0.field', 'Schéma')
                ->where('entries.data.0.changes.0.old', 'Schéma logique')
                ->where('entries.data.0.changes.1.field', "Nombre d'éléments")
                ->where('entries.data.0.changes.1.old', '3')
                ->where('entries.data.1.event', 'site.diagram_saved')
                ->where('entries.data.1.changes.0.new', 'Schéma logique')
                ->where('entries.data.1.changes.1.new', '3'));
    }

    // --- Rights -------------------------------------------------------------

    public function test_a_reader_can_neither_save_a_layout_nor_give_it_back_to_the_automatic_layout(): void
    {
        $version = $this->layout()['version'];

        $this->save(['internet' => [0, 0]], $version, $this->reader())->assertForbidden();

        DiagramPosition::query()->create(['site_id' => $this->office->id, 'view' => DiagramView::Logical, 'node' => DiagramNode::Internet, 'x' => 8, 'y' => 8]);

        $this->actingAs($this->reader())->delete(route('sites.diagrams.logical.layout.destroy', $this->office))->assertForbidden();

        $this->assertSame(['internet' => [8, 8]], $this->stored());
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDiagramSaved));
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDiagramReset));

        auth()->logout();

        $this->put(route('sites.diagrams.logical.layout.update', $this->office), ['version' => $version, 'positions' => []])->assertRedirect(route('login'));
        $this->delete(route('sites.diagrams.logical.layout.destroy', $this->office))->assertRedirect(route('login'));
    }
}
