<?php

// torsade/tests/Feature/Network/LinkIndexTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\LinkMedium;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Link;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\LinkFactory;
use Database\Factories\PortFactory;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The list of every link: its two ends, its medium, its status and where
 * its ends do not agree, filtered by the site of one end or of the other,
 * by status, and to the links with a deviation.
 */
class LinkIndexTest extends NetworkTestCase
{
    private User $reader;

    private Site $office;

    private Site $workshopSite;

    private Site $depot;

    /** @var array<string, Device> */
    private array $devices = [];

    /** @var array<string, array<string, Port>> The ports of each device, by name. */
    private array $ports = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = $this->reader();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->workshopSite = Site::factory()->create(['name' => 'Atelier']);
        $this->depot = Site::factory()->create(['name' => 'Dépôt']);

        foreach ([
            ['sw-coeur-01', $this->office, [8]],
            ['sw-serveurs', $this->office, [4, 4]],
            ['sw-etage-10', $this->office, [4]],
            ['sw-etage-2', $this->office, [4]],
            ['sw-atelier', $this->workshopSite, [4]],
            ['sw-depot', $this->depot, [2]],
        ] as [$name, $site, $members]) {
            $device = Device::factory()->create(['site_id' => $site->id, 'name' => $name, 'type' => DeviceType::Switch]);

            $this->devices[$name] = $device;

            foreach ($members as $index => $quantity) {
                foreach (PortFactory::series(DeviceMember::factory()->create(['device_id' => $device->id, 'position' => $index + 1]), $quantity) as $port) {
                    $this->ports[$name][$port->name] = $port;
                }
            }
        }
    }

    private function port(string $device, string $name): Port
    {
        return $this->ports[$device][$name];
    }

    /**
     * @param  array{0: string, 1: string}  $first
     * @param  array{0: string, 1: string}  $second
     * @param  array<string, mixed>  $attributes
     */
    private function link(array $first, array $second, array $attributes = []): Link
    {
        return LinkFactory::between($this->port(...$first), $this->port(...$second), $attributes);
    }

    /**
     * @param  list<string>  $ports
     */
    private function aggregate(string $device, string $name, array $ports): PortAggregate
    {
        $aggregate = PortAggregate::factory()->create(['device_id' => $this->devices[$device]->id, 'name' => $name]);

        foreach ($ports as $port) {
            $this->port($device, $port)->update(['port_aggregate_id' => $aggregate->id]);
        }

        return $aggregate;
    }

    /**
     * An access port on a VLAN of the site of its device, by number.
     */
    private function access(string $device, string $port, int $vid): void
    {
        $holder = $this->port($device, $port);
        $holder->update(['mode' => PortMode::Access]);

        $vlan = Vlan::query()->firstWhere(['site_id' => $this->devices[$device]->site_id, 'vid' => $vid])
            ?? Vlan::factory()->create(['site_id' => $this->devices[$device]->site_id, 'vid' => $vid, 'name' => 'VLAN '.$vid]);

        PortVlan::factory()->create(['port_id' => $holder->id, 'vlan_id' => $vlan->id, 'tagged' => false]);
    }

    /**
     * The lines of the page, each as its links in a few words, for some
     * filters.
     *
     * @param  array<string, mixed>  $filters
     * @return list<list<string>>
     */
    private function listed(array $filters = []): array
    {
        return array_map(
            fn (array $line): array => array_map(
                fn (array $link): string => $link['near']['device']['name'].' › '.$link['near']['port']['name'].' ↔ '.$link['far']['device']['name'].' › '.$link['far']['port']['name'],
                $line['links'],
            ),
            (array) $this->actingAs($this->reader)->get(route('links.index', $filters))->assertOk()->inertiaProps('links'),
        );
    }

    /**
     * Five links: two of the head office, one to the workshop, one to the
     * depot, and one between the workshop and the depot.
     */
    private function network(): void
    {
        // Two access ports on different VLANs: a deviation.
        $this->access('sw-etage-2', '1/0/1', 10);
        $this->access('sw-coeur-01', '1/0/1', 20);
        $this->link(['sw-etage-2', '1/0/1'], ['sw-coeur-01', '1/0/1'], ['medium' => LinkMedium::Copper]);

        $this->link(['sw-serveurs', '2/0/1'], ['sw-coeur-01', '1/0/2'], ['status' => Status::Planned]);
        $this->link(['sw-coeur-01', '1/0/3'], ['sw-atelier', '1/0/1'], ['medium' => LinkMedium::FiberSinglemode, 'description' => 'Fibre entre les deux bâtiments']);
        $this->link(['sw-depot', '1/0/1'], ['sw-coeur-01', '1/0/4'], ['status' => Status::Retired]);

        // A port shut down under an active link: a deviation.
        $this->port('sw-depot', '1/0/2')->update(['enabled' => false]);
        $this->link(['sw-atelier', '1/0/2'], ['sw-depot', '1/0/2']);
    }

    // --- The list ---------------------------------------------------------------------

    public function test_the_page_lists_every_link_with_its_two_ends_by_the_device_of_its_end_1(): void
    {
        $this->network();
        $this->link(['sw-etage-10', '1/0/2'], ['sw-etage-2', '1/0/2']);
        $this->link(['sw-coeur-01', '1/0/8'], ['sw-etage-10', '1/0/1']);

        // By the name of the device of the end 1, a number read as a
        // number, then in the order of its ports.
        $this->assertSame(
            [
                ['sw-atelier › 1/0/2 ↔ sw-depot › 1/0/2'],
                ['sw-coeur-01 › 1/0/3 ↔ sw-atelier › 1/0/1'],
                ['sw-coeur-01 › 1/0/8 ↔ sw-etage-10 › 1/0/1'],
                ['sw-depot › 1/0/1 ↔ sw-coeur-01 › 1/0/4'],
                ['sw-etage-2 › 1/0/1 ↔ sw-coeur-01 › 1/0/1'],
                ['sw-etage-10 › 1/0/2 ↔ sw-etage-2 › 1/0/2'],
                ['sw-serveurs › 2/0/1 ↔ sw-coeur-01 › 1/0/2'],
            ],
            $this->listed(),
        );

        $this->actingAs($this->reader)
            ->get(route('links.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('links/Index')
                ->where('total', 7)
                ->where('filters', ['site' => '', 'status' => '', 'deviations' => ''])
                // The end 1 of a link is its near end, with its device and
                // its site; its end 2, the far one.
                ->where('links.1.aggregated', false)
                ->where('links.1.links.0.near.device', ['id' => $this->devices['sw-coeur-01']->id, 'name' => 'sw-coeur-01', 'site' => ['id' => $this->office->id, 'name' => 'Siège']])
                ->where('links.1.links.0.far.device', ['id' => $this->devices['sw-atelier']->id, 'name' => 'sw-atelier', 'site' => ['id' => $this->workshopSite->id, 'name' => 'Atelier']])
                ->where('links.1.links.0.medium_label', 'Fibre monomode')
                ->where('links.1.links.0.status_label', 'Active')
                ->where('links.1.links.0.description', 'Fibre entre les deux bâtiments')
                // What the filters and the window of a link choose among.
                ->where('sites', [
                    ['id' => $this->workshopSite->id, 'name' => 'Atelier'],
                    ['id' => $this->depot->id, 'name' => 'Dépôt'],
                    ['id' => $this->office->id, 'name' => 'Siège'],
                ])
                ->where('statuses', [
                    ['value' => 'planned', 'label' => 'Prévue'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'retired', 'label' => 'Retirée'],
                ])
                ->has('media', 5));
    }

    public function test_the_page_shows_the_deviations_of_each_link_named_from_nowhere_in_particular(): void
    {
        $this->network();

        $deviations = array_map(
            fn (array $line): array => array_column($line['deviations'], 'reason'),
            (array) $this->actingAs($this->reader)->get(route('links.index'))->inertiaProps('links'),
        );

        $this->assertSame(
            [
                // A port is named with its device: no device is the one of
                // the page.
                ['sw-depot › 1/0/2 est désactivé'],
                [],
                [],
                ["VLAN 10 d'un côté, 20 de l'autre"],
                [],
            ],
            $deviations,
        );
    }

    public function test_the_links_between_two_aggregates_are_one_line(): void
    {
        $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']);
        $this->aggregate('sw-coeur-01', 'Po2', ['1/0/7', '1/0/8']);

        $this->link(['sw-serveurs', '2/0/4'], ['sw-coeur-01', '1/0/8'], ['status' => Status::Planned]);
        $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/7']);
        $this->link(['sw-coeur-01', '1/0/1'], ['sw-etage-2', '1/0/1']);

        $this->assertSame(
            [
                ['sw-coeur-01 › 1/0/1 ↔ sw-etage-2 › 1/0/1'],
                ['sw-serveurs › 1/0/4 ↔ sw-coeur-01 › 1/0/7', 'sw-serveurs › 2/0/4 ↔ sw-coeur-01 › 1/0/8'],
            ],
            $this->listed(),
        );

        $this->actingAs($this->reader)
            ->get(route('links.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 3)
                ->where('links.1.aggregated', true)
                ->where('links.1.links.0.near.aggregate.name', 'Po1')
                ->where('links.1.links.0.far.aggregate.name', 'Po2'));

        // An aggregated link is kept whole as soon as one of its links has
        // the status asked for.
        $this->assertSame(
            [['sw-serveurs › 1/0/4 ↔ sw-coeur-01 › 1/0/7', 'sw-serveurs › 2/0/4 ↔ sw-coeur-01 › 1/0/8']],
            $this->listed(['status' => 'planned']),
        );
    }

    // --- The filters ------------------------------------------------------------------

    public function test_a_site_keeps_the_links_one_of_whose_ends_is_on_it(): void
    {
        $this->network();

        // The workshop: a link whose end 2 is there, and one whose end 1
        // is.
        $this->assertSame(
            [['sw-atelier › 1/0/2 ↔ sw-depot › 1/0/2'], ['sw-coeur-01 › 1/0/3 ↔ sw-atelier › 1/0/1']],
            $this->listed(['site' => $this->workshopSite->id]),
        );
        $this->assertSame(
            [['sw-atelier › 1/0/2 ↔ sw-depot › 1/0/2'], ['sw-depot › 1/0/1 ↔ sw-coeur-01 › 1/0/4']],
            $this->listed(['site' => $this->depot->id]),
        );
        // The head office: every link but the one between the two others.
        $this->assertCount(4, $this->listed(['site' => $this->office->id]));

        // A site without a device has no link, and the page says how many
        // there are in all.
        $empty = Site::factory()->create(['name' => 'Agence']);

        $this->actingAs($this->reader)
            ->get(route('links.index', ['site' => $empty->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('links', [])
                ->where('total', 5)
                ->where('filters.site', (string) $empty->id));
    }

    public function test_a_status_keeps_the_links_that_have_it(): void
    {
        $this->network();

        $this->assertSame([['sw-serveurs › 2/0/1 ↔ sw-coeur-01 › 1/0/2']], $this->listed(['status' => 'planned']));
        $this->assertSame([['sw-depot › 1/0/1 ↔ sw-coeur-01 › 1/0/4']], $this->listed(['status' => 'retired']));
        $this->assertCount(3, $this->listed(['status' => 'active']));
    }

    public function test_the_links_with_a_deviation_are_listed_alone_on_demand(): void
    {
        $this->network();

        $this->assertSame(
            [['sw-atelier › 1/0/2 ↔ sw-depot › 1/0/2'], ['sw-etage-2 › 1/0/1 ↔ sw-coeur-01 › 1/0/1']],
            $this->listed(['deviations' => 1]),
        );
        // Left out, or unticked: every link.
        $this->assertCount(5, $this->listed(['deviations' => 0]));
        $this->assertCount(5, $this->listed(['deviations' => '']));

        $this->actingAs($this->reader)
            ->get(route('links.index', ['deviations' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.deviations', '1')->where('total', 5));
    }

    public function test_the_filters_are_combined(): void
    {
        $this->network();

        $this->assertSame(
            [['sw-etage-2 › 1/0/1 ↔ sw-coeur-01 › 1/0/1']],
            $this->listed(['site' => $this->office->id, 'deviations' => 1]),
        );
        $this->assertSame(
            [['sw-atelier › 1/0/2 ↔ sw-depot › 1/0/2']],
            $this->listed(['site' => $this->depot->id, 'status' => 'active', 'deviations' => 1]),
        );
        $this->assertSame([], $this->listed(['site' => $this->workshopSite->id, 'status' => 'planned']));
        $this->assertSame([], $this->listed(['status' => 'retired', 'deviations' => 1]));
    }

    public function test_a_deviation_is_worked_out_on_every_link_whatever_the_filters_show(): void
    {
        // An aggregate whose two links face ports in no aggregate; one of
        // the two is planned.
        $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']);
        $this->aggregate('sw-coeur-01', 'Po2', ['1/0/7']);

        $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/7']);
        $this->link(['sw-serveurs', '2/0/4'], ['sw-coeur-01', '1/0/8'], ['status' => Status::Planned]);

        // Alone, the active link would face one aggregate: with the other
        // one, the aggregate of the stack faces a port outside it.
        $shown = (array) $this->actingAs($this->reader)->get(route('links.index', ['status' => 'active']))->inertiaProps('links');

        $this->assertCount(1, $shown);
        $this->assertSame(
            [['kind' => 'aggregate_one_sided', 'reason' => 'sw-serveurs › Po1 : en face, ports hors agrégat sur sw-coeur-01']],
            $shown[0]['deviations'],
        );
    }

    public function test_a_filter_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->reader)->get(route('links.index', ['site' => 999999]))->assertSessionHasErrors('site');
        $this->actingAs($this->reader)->get(route('links.index', ['site' => 'siege']))->assertSessionHasErrors('site');
        $this->actingAs($this->reader)->get(route('links.index', ['status' => 'broken']))->assertSessionHasErrors('status');
        $this->actingAs($this->reader)->get(route('links.index', ['deviations' => 'oui']))->assertSessionHasErrors('deviations');
    }

    // --- Rights, and an empty network ---------------------------------------------------

    public function test_the_three_roles_read_the_page_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('links.index'))->assertRedirect(route('login'));

        foreach ([$this->reader, $this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)
                ->get(route('links.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('links/Index')->where('links', [])->where('total', 0));
        }
    }
}
