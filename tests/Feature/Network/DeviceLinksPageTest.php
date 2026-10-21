<?php

// torsade/tests/Feature/Network/DeviceLinksPageTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\LinkMedium;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Http\Controllers\Network\DeviceController;
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
 * What the page of a device shows of its links: what each linked port
 * faces, the section of its links with the aggregated ones as one line,
 * where their two ends do not agree, and what the window "Link…" chooses
 * among.
 */
class DeviceLinksPageTest extends NetworkTestCase
{
    private User $reader;

    private Site $office;

    private Site $workshopSite;

    /** @var array<string, Device> */
    private array $devices = [];

    /** @var array<string, array<string, Port>> The ports of each device, by name. */
    private array $ports = [];

    /** @var array<int, array<int, Vlan>> The VLANs of each site, by number. */
    private array $vlans = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = $this->reader();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->workshopSite = Site::factory()->create(['name' => 'Atelier']);

        foreach ([
            ['sw-serveurs', $this->office, DeviceType::Switch, [4, 4]],
            ['sw-coeur-01', $this->office, DeviceType::Switch, [8]],
            ['fw-siege', $this->office, DeviceType::Firewall, [2]],
            ['sw-atelier', $this->workshopSite, DeviceType::Switch, [4]],
        ] as [$name, $site, $type, $members]) {
            $this->device($name, $site, $type, $members);
        }
    }

    /**
     * A device with members of so many ports each.
     *
     * @param  list<int>  $members
     */
    private function device(string $name, Site $site, DeviceType $type, array $members): Device
    {
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => $name, 'type' => $type]);

        $this->devices[$name] = $device;

        foreach ($members as $index => $quantity) {
            foreach (PortFactory::series(DeviceMember::factory()->create(['device_id' => $device->id, 'position' => $index + 1]), $quantity) as $port) {
                $this->ports[$name][$port->name] = $port;
            }
        }

        return $device;
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
     * @param  list<int>  $tagged
     */
    private function carry(Port|PortAggregate $holder, PortMode $mode, ?int $untagged = null, array $tagged = []): void
    {
        $holder->mode = $mode;
        $holder->save();

        $site = $holder->device->site_id;
        $key = $holder instanceof Port ? 'port_id' : 'port_aggregate_id';

        foreach (array_filter([$untagged]) as $vid) {
            PortVlan::factory()->create([$key => $holder->id, 'vlan_id' => $this->vlan($site, $vid)->id, 'tagged' => false]);
        }

        foreach ($tagged as $vid) {
            PortVlan::factory()->create([$key => $holder->id, 'vlan_id' => $this->vlan($site, $vid)->id, 'tagged' => true]);
        }
    }

    private function vlan(int $site, int $vid): Vlan
    {
        return $this->vlans[$site][$vid] ??= Vlan::factory()->create(['site_id' => $site, 'vid' => $vid, 'name' => 'VLAN '.$vid]);
    }

    /**
     * A prop of the page of a device.
     */
    private function shown(string $device, string $prop): mixed
    {
        return $this->actingAs($this->reader)->get(route('devices.show', $this->devices[$device]))->assertOk()->inertiaProps($prop);
    }

    /**
     * A prop the page only works out when a window asks for it.
     *
     * @param  array<string, mixed>  $query
     */
    private function asked(string $device, string $prop, array $query = []): mixed
    {
        $found = false;

        $this->actingAs($this->reader)
            ->get(route('devices.show', ['device' => $this->devices[$device], ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found, $prop): void {
                $page
                    ->missing($prop)
                    ->reloadOnly($prop, function (Assert $reload) use (&$found, $prop): void {
                        $found = $reload->toArray()['props'][$prop];
                    });
            });

        return $found;
    }

    /**
     * The lines of the section of links, each as its ends in a few words.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function lines(string $device): array
    {
        return array_map(
            fn (array $line): array => [
                $line['aggregated'] ? 'aggregated' : 'link',
                array_map(
                    fn (array $link): string => $link['near']['port']['name'].' ↔ '.$link['far']['device']['name'].' › '.$link['far']['port']['name'],
                    $line['links'],
                ),
            ],
            (array) $this->shown($device, 'links'),
        );
    }

    // --- The table of ports -------------------------------------------------------

    public function test_each_linked_port_shows_the_device_and_the_port_it_faces(): void
    {
        $link = $this->link(['sw-serveurs', '1/0/2'], ['sw-coeur-01', '1/0/5']);
        $this->link(['sw-atelier', '1/0/1'], ['sw-serveurs', '2/0/4']);

        $ports = collect((array) $this->shown('sw-serveurs', 'ports'))->keyBy('name');

        $this->assertSame(
            ['id' => $link->id, 'device' => ['id' => $this->devices['sw-coeur-01']->id, 'name' => 'sw-coeur-01'], 'port' => '1/0/5'],
            $ports['1/0/2']['link'],
        );
        // Whichever end of its link the port is.
        $this->assertSame(['sw-atelier', '1/0/1'], [$ports['2/0/4']['link']['device']['name'], $ports['2/0/4']['link']['port']]);
        // A port that is not linked says so.
        $this->assertNull($ports['1/0/1']['link']);
        $this->assertSame(2, $ports->filter(fn (array $port): bool => $port['link'] !== null)->count());

        // The other end shows the same link from its side.
        $facing = collect((array) $this->shown('sw-coeur-01', 'ports'))->keyBy('name');

        $this->assertSame(
            ['id' => $link->id, 'device' => ['id' => $this->devices['sw-serveurs']->id, 'name' => 'sw-serveurs'], 'port' => '1/0/2'],
            $facing['1/0/5']['link'],
        );
    }

    // --- The section of links -----------------------------------------------------

    public function test_the_section_lists_one_line_per_link_in_the_order_of_the_ports(): void
    {
        $this->link(['sw-serveurs', '2/0/1'], ['sw-coeur-01', '1/0/1'], ['medium' => LinkMedium::Copper, 'description' => 'Cordon bleu']);
        $this->link(['sw-atelier', '1/0/3'], ['sw-serveurs', '1/0/4'], ['status' => Status::Planned]);
        $this->link(['sw-serveurs', '1/0/2'], ['fw-siege', '1/0/1'], ['medium' => LinkMedium::FiberSinglemode, 'status' => Status::Retired]);
        // A link between two other devices is not a link of this one.
        $this->link(['sw-coeur-01', '1/0/8'], ['fw-siege', '1/0/2']);

        $this->assertSame(
            [
                ['link', ['1/0/2 ↔ fw-siege › 1/0/1']],
                ['link', ['1/0/4 ↔ sw-atelier › 1/0/3']],
                ['link', ['2/0/1 ↔ sw-coeur-01 › 1/0/1']],
            ],
            $this->lines('sw-serveurs'),
        );

        $lines = (array) $this->shown('sw-serveurs', 'links');
        $second = $lines[1]['links'][0];

        // The port of the device is the near end, whichever end of the
        // link it is; the far end comes with its device and its site.
        $this->assertSame(
            [
                'device' => ['id' => $this->devices['sw-serveurs']->id, 'name' => 'sw-serveurs', 'site' => ['id' => $this->office->id, 'name' => 'Siège']],
                'port' => ['id' => $this->port('sw-serveurs', '1/0/4')->id, 'name' => '1/0/4', 'enabled' => true],
                'aggregate' => null,
            ],
            $second['near'],
        );
        $this->assertSame(['id' => $this->devices['sw-atelier']->id, 'name' => 'sw-atelier', 'site' => ['id' => $this->workshopSite->id, 'name' => 'Atelier']], $second['far']['device']);

        // The medium, the status agreed with a link, and the description.
        $this->assertSame(
            [
                [LinkMedium::FiberSinglemode->value, 'Fibre monomode', 'retired', 'Retirée', null],
                [null, null, 'planned', 'Prévue', null],
                ['copper', 'Cuivre', 'active', 'Active', 'Cordon bleu'],
            ],
            array_map(
                fn (array $line): array => [$line['links'][0]['medium'], $line['links'][0]['medium_label'], $line['links'][0]['status'], $line['links'][0]['status_label'], $line['links'][0]['description']],
                $lines,
            ),
        );

        // What the windows of a link choose among.
        $this->assertSame(['copper', 'fiber_multimode', 'fiber_singlemode', 'direct_attach', 'other'], array_column((array) $this->shown('sw-serveurs', 'linkMedia'), 'value'));
        $this->assertSame(['Prévue', 'Active', 'Retirée'], array_column((array) $this->shown('sw-serveurs', 'statuses'), 'label'));

        // A device without a link has an empty section, and one without a
        // member too.
        $this->device('sw-seul', $this->office, DeviceType::Switch, [2]);
        $this->device('sw-vide', $this->office, DeviceType::Switch, []);

        $this->assertSame([], $this->lines('sw-seul'));
        $this->assertSame([], $this->lines('sw-vide'));
    }

    public function test_the_links_between_two_aggregates_are_shown_as_one_aggregated_link(): void
    {
        $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']);
        $this->aggregate('sw-coeur-01', 'Po2', ['1/0/1', '1/0/2']);

        // Created in any order: the line holds them in the order of the
        // ports of the device.
        $this->link(['sw-coeur-01', '1/0/2'], ['sw-serveurs', '2/0/4'], ['medium' => LinkMedium::DirectAttach]);
        $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/1'], ['medium' => LinkMedium::DirectAttach]);
        // A link of its own, before them in the order of the ports.
        $this->link(['sw-serveurs', '1/0/1'], ['fw-siege', '1/0/1']);

        $this->assertSame(
            [
                ['link', ['1/0/1 ↔ fw-siege › 1/0/1']],
                ['aggregated', ['1/0/4 ↔ sw-coeur-01 › 1/0/1', '2/0/4 ↔ sw-coeur-01 › 1/0/2']],
            ],
            $this->lines('sw-serveurs'),
        );

        $line = ((array) $this->shown('sw-serveurs', 'links'))[1];

        $this->assertSame(['Po1', 'Po2'], [$line['links'][0]['near']['aggregate']['name'], $line['links'][0]['far']['aggregate']['name']]);
        $this->assertSame([], $line['deviations']);

        // The same aggregated link, seen from the other device.
        $this->assertSame(
            [['aggregated', ['1/0/1 ↔ sw-serveurs › 1/0/4', '1/0/2 ↔ sw-serveurs › 2/0/4']]],
            $this->lines('sw-coeur-01'),
        );

        // A port of an aggregate that faces a port outside any aggregate
        // is a line of its own, and so is one link between two other
        // aggregates.
        $this->aggregate('sw-serveurs', 'Po3', ['1/0/3', '2/0/3']);
        $this->aggregate('sw-coeur-01', 'Po4', ['1/0/4']);
        $this->link(['sw-serveurs', '1/0/3'], ['sw-coeur-01', '1/0/8']);
        $this->link(['sw-serveurs', '2/0/3'], ['sw-coeur-01', '1/0/4']);

        $this->assertSame(
            [
                ['link', ['1/0/1 ↔ fw-siege › 1/0/1']],
                ['link', ['1/0/3 ↔ sw-coeur-01 › 1/0/8']],
                ['aggregated', ['1/0/4 ↔ sw-coeur-01 › 1/0/1', '2/0/4 ↔ sw-coeur-01 › 1/0/2']],
                ['aggregated', ['2/0/3 ↔ sw-coeur-01 › 1/0/4']],
            ],
            $this->lines('sw-serveurs'),
        );
    }

    // --- Deviations -----------------------------------------------------------------

    public function test_the_deviations_of_a_link_are_shown_on_its_line_on_both_devices(): void
    {
        $this->carry($this->port('sw-serveurs', '1/0/1'), PortMode::Trunk, 99, [10, 20]);
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 1, [10, 20, 30]);
        $this->link(['sw-serveurs', '1/0/1'], ['sw-coeur-01', '1/0/1']);

        // A firewall port has no mode: nothing to compare.
        $this->link(['sw-serveurs', '1/0/2'], ['fw-siege', '1/0/1']);

        $near = (array) $this->shown('sw-serveurs', 'links');

        $this->assertSame(
            [
                ['kind' => 'link_native_vlans', 'reason' => "Natif 99 d'un côté, 1 de l'autre"],
                ['kind' => 'link_tagged_vlans', 'reason' => 'VLAN 30 seulement sur sw-coeur-01'],
            ],
            $near[0]['deviations'],
        );
        $this->assertSame($near[0]['deviations'], $near[0]['links'][0]['deviations']);
        $this->assertSame([], $near[1]['deviations']);

        // From the other device, its own side comes first.
        $this->assertSame(
            [
                ['kind' => 'link_native_vlans', 'reason' => "Natif 1 d'un côté, 99 de l'autre"],
                ['kind' => 'link_tagged_vlans', 'reason' => 'VLAN 30 seulement sur sw-coeur-01'],
            ],
            ((array) $this->shown('sw-coeur-01', 'links'))[0]['deviations'],
        );

        // And in the list of the deviations of each page, on the link.
        $this->assertSame(
            [['key' => 'link-'.Link::query()->orderBy('id')->value('id'), 'label' => '1/0/1 ↔ sw-coeur-01 › 1/0/1', 'section' => 'links', 'deviations' => $near[0]['deviations']]],
            $this->shown('sw-serveurs', 'linkDeviations'),
        );
        $this->assertSame(['1/0/1 ↔ sw-serveurs › 1/0/1'], array_column((array) $this->shown('sw-coeur-01', 'linkDeviations'), 'label'));
        $this->assertSame([], $this->shown('fw-siege', 'linkDeviations'));
    }

    public function test_the_deviations_of_an_aggregated_link_are_told_once_on_its_line(): void
    {
        $this->carry($this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']), PortMode::Trunk, 99, [10, 20]);
        $this->carry($this->aggregate('sw-coeur-01', 'Po2', ['1/0/1', '1/0/2']), PortMode::Trunk, 99, [10]);

        $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/1']);
        $this->link(['sw-serveurs', '2/0/4'], ['sw-coeur-01', '1/0/2']);

        $expected = [['kind' => 'link_tagged_vlans', 'reason' => 'VLAN 20 seulement sur sw-serveurs']];
        $line = ((array) $this->shown('sw-serveurs', 'links'))[0];

        // Each of its links has the deviation; the line tells it once.
        $this->assertSame($expected, $line['deviations']);
        $this->assertSame([$expected, $expected], array_column($line['links'], 'deviations'));

        // In the list of the page, the aggregated link is one object.
        $this->assertSame(
            [['key' => $line['key'], 'label' => 'Po1 ↔ sw-coeur-01 › Po2', 'section' => 'links', 'deviations' => $expected]],
            $this->shown('sw-serveurs', 'linkDeviations'),
        );
        $this->assertSame(['Po2 ↔ sw-serveurs › Po1'], array_column((array) $this->shown('sw-coeur-01', 'linkDeviations'), 'label'));
    }

    public function test_the_deviations_of_an_aggregate_are_shown_on_the_aggregate_and_told_once_in_the_list(): void
    {
        $uplink = $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']);

        // Its two links lead to two devices, and face ports that are in no
        // aggregate.
        $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/1']);
        $this->link(['sw-serveurs', '2/0/4'], ['sw-atelier', '1/0/1']);

        $aggregates = collect((array) $this->shown('sw-serveurs', 'aggregates'))->keyBy('name');

        $this->assertSame(
            [
                ['kind' => 'aggregate_several_devices', 'reason' => 'Po1 mène à sw-atelier et sw-coeur-01'],
                ['kind' => 'aggregate_one_sided', 'reason' => 'Po1 : en face, ports hors agrégat sur sw-coeur-01'],
                ['kind' => 'aggregate_one_sided', 'reason' => 'Po1 : en face, ports hors agrégat sur sw-atelier'],
            ],
            $aggregates['Po1']['deviations'],
        );

        // On each link, what its aggregate faces on that device.
        $lines = (array) $this->shown('sw-serveurs', 'links');

        $this->assertSame([['kind' => 'aggregate_one_sided', 'reason' => 'Po1 : en face, ports hors agrégat sur sw-coeur-01']], $lines[0]['deviations']);
        $this->assertSame([['kind' => 'aggregate_one_sided', 'reason' => 'Po1 : en face, ports hors agrégat sur sw-atelier']], $lines[1]['deviations']);

        // The list of the page tells it once, on the aggregate: its links
        // do not repeat it.
        $this->assertSame(
            [['key' => 'aggregate-'.$uplink->id, 'label' => 'Po1', 'section' => 'aggregates', 'deviations' => $aggregates['Po1']['deviations']]],
            $this->shown('sw-serveurs', 'linkDeviations'),
        );

        // On the page of the other device, the aggregate is not one of its
        // own: its link tells it, with the device of the aggregate.
        $this->assertSame(
            [[
                'key' => 'link-'.Link::query()->orderBy('id')->value('id'),
                'label' => '1/0/1 ↔ sw-serveurs › 1/0/4',
                'section' => 'links',
                'deviations' => [['kind' => 'aggregate_one_sided', 'reason' => 'sw-serveurs › Po1 : en face, ports hors agrégat sur sw-coeur-01']],
            ]],
            $this->shown('sw-coeur-01', 'linkDeviations'),
        );
        // That an aggregate leads to several devices is told on its own
        // device only.
        $this->assertSame([], array_filter(
            array_merge(...array_column((array) $this->shown('sw-coeur-01', 'linkDeviations'), 'deviations')),
            fn (array $deviation): bool => $deviation['kind'] === 'aggregate_several_devices',
        ));
        $this->assertSame([], collect((array) $this->shown('sw-coeur-01', 'aggregates'))->pluck('deviations')->flatten(1)->all());
    }

    public function test_a_port_shut_down_under_an_active_link_is_told_on_the_link_and_on_the_page_of_its_device(): void
    {
        $this->port('sw-coeur-01', '1/0/3')->update(['enabled' => false]);
        $this->link(['sw-serveurs', '1/0/1'], ['sw-coeur-01', '1/0/3']);

        // On the link, from both devices.
        $this->assertSame(
            [['kind' => 'link_on_disabled_port', 'reason' => 'sw-coeur-01 › 1/0/3 est désactivé']],
            ((array) $this->shown('sw-serveurs', 'links'))[0]['deviations'],
        );
        $this->assertSame(
            [['kind' => 'link_on_disabled_port', 'reason' => '1/0/3 est désactivé']],
            ((array) $this->shown('sw-coeur-01', 'links'))[0]['deviations'],
        );

        // In the list of the page of the device whose port is shut down,
        // and not in the one of the other device.
        $this->assertSame(['1/0/3 ↔ sw-serveurs › 1/0/1'], array_column((array) $this->shown('sw-coeur-01', 'linkDeviations'), 'label'));
        $this->assertSame([], $this->shown('sw-serveurs', 'linkDeviations'));
    }

    // --- The window "Link…" ---------------------------------------------------------

    public function test_the_opposite_device_is_looked_for_by_its_name_on_every_site(): void
    {
        $this->link(['sw-coeur-01', '1/0/1'], ['fw-siege', '1/0/1']);
        $this->link(['sw-coeur-01', '1/0/2'], ['sw-atelier', '1/0/1']);

        // Without anything typed: every other device, by name, each with
        // its site, its type and how many of its ports are free.
        $this->assertSame(
            [
                'items' => [
                    ['id' => $this->devices['fw-siege']->id, 'name' => 'fw-siege', 'site' => 'Siège', 'type_label' => 'Pare-feu', 'free_ports' => 1],
                    ['id' => $this->devices['sw-atelier']->id, 'name' => 'sw-atelier', 'site' => 'Atelier', 'type_label' => 'Switch', 'free_ports' => 3],
                    ['id' => $this->devices['sw-coeur-01']->id, 'name' => 'sw-coeur-01', 'site' => 'Siège', 'type_label' => 'Switch', 'free_ports' => 6],
                ],
                'more' => false,
            ],
            $this->asked('sw-serveurs', 'linkable'),
        );

        $names = fn (string $search): array => array_column($this->asked('sw-serveurs', 'linkable', ['search' => $search])['items'], 'name');

        // A part of the name, whatever its case, on every site; never the
        // device itself.
        $this->assertSame(['sw-atelier', 'sw-coeur-01'], $names('SW-'));
        $this->assertSame(['sw-atelier'], $names('atel'));
        $this->assertSame([], $names('sw-serveurs'));
        $this->assertSame([], $names('routeur'));

        // What is typed is looked for as it is.
        $this->assertSame([], $names('%'));
        $this->assertSame([], $names('sw_coeur'));

        // A device without a port, or without a free one, is listed: the
        // window says why it cannot be chosen.
        $this->device('sw-vide', $this->office, DeviceType::Switch, []);
        $this->link(['fw-siege', '1/0/2'], ['sw-atelier', '1/0/2']);

        $free = array_column($this->asked('sw-serveurs', 'linkable')['items'], 'free_ports', 'name');

        $this->assertSame(['fw-siege' => 0, 'sw-atelier' => 2, 'sw-coeur-01' => 6, 'sw-vide' => 0], $free);
    }

    public function test_the_devices_found_are_listed_twenty_at_a_time(): void
    {
        foreach (range(1, DeviceController::LINKABLE_LISTED) as $number) {
            Device::factory()->create(['site_id' => $this->workshopSite->id, 'name' => sprintf('ap-%02d', $number), 'type' => DeviceType::AccessPoint]);
        }

        $found = $this->asked('sw-serveurs', 'linkable');

        // Twenty-three other devices: the first twenty, and the word that
        // there are more.
        $this->assertCount(DeviceController::LINKABLE_LISTED, $found['items']);
        $this->assertTrue($found['more']);
        $this->assertSame(['ap-01', 'ap-20'], [$found['items'][0]['name'], $found['items'][19]['name']]);

        $narrowed = $this->asked('sw-serveurs', 'linkable', ['search' => 'ap-1']);

        // "ap-10" to "ap-19".
        $this->assertCount(10, $narrowed['items']);
        $this->assertFalse($narrowed['more']);
    }

    public function test_the_free_ports_of_the_device_chosen_are_listed_with_what_they_carry(): void
    {
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Access, 10, [30]);
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 99, [10, 20]);
        $this->carry($this->aggregate('sw-coeur-01', 'Po1', ['1/0/5', '1/0/6']), PortMode::Trunk, 99, [10]);
        $this->aggregate('sw-coeur-01', 'Po2', ['1/0/7']);
        $this->port('sw-coeur-01', '1/0/4')->update(['enabled' => false]);

        // A linked port is not free, whichever end it is.
        $this->link(['sw-coeur-01', '1/0/3'], ['fw-siege', '1/0/1']);
        $this->link(['sw-atelier', '1/0/1'], ['sw-coeur-01', '1/0/8']);

        $found = $this->asked('sw-serveurs', 'oppositePorts', ['opposite' => $this->devices['sw-coeur-01']->id]);

        $this->assertSame(['id' => $this->devices['sw-coeur-01']->id, 'name' => 'sw-coeur-01', 'site' => 'Siège'], $found['device']);
        $this->assertSame(
            [
                ['1/0/1', 1, true, null, 'Accès 10, voix 30'],
                ['1/0/2', 1, true, null, 'Trunk, natif 99 : 10, 20'],
                ['1/0/4', 1, false, null, null],
                // The mode and the VLANs of a port of an aggregate are
                // those of the aggregate.
                ['1/0/5', 1, true, 'Po1', 'Trunk, natif 99 : 10'],
                ['1/0/6', 1, true, 'Po1', 'Trunk, natif 99 : 10'],
                ['1/0/7', 1, true, 'Po2', null],
            ],
            array_map(fn (array $port): array => [$port['name'], $port['member'], $port['enabled'], $port['aggregate'], $port['summary']], $found['ports']),
        );
        $this->assertSame($this->port('sw-coeur-01', '1/0/1')->id, $found['ports'][0]['id']);

        // The ports of a stack come member by member.
        $stack = $this->asked('sw-coeur-01', 'oppositePorts', ['opposite' => $this->devices['sw-serveurs']->id]);

        $this->assertSame(
            ['1/0/1' => 1, '1/0/2' => 1, '1/0/3' => 1, '1/0/4' => 1, '2/0/1' => 2, '2/0/2' => 2, '2/0/3' => 2, '2/0/4' => 2],
            array_column($stack['ports'], 'member', 'name'),
        );

        // No device chosen, the device itself, or one that is gone: no
        // list.
        $this->assertNull($this->asked('sw-serveurs', 'oppositePorts'));
        $this->assertNull($this->asked('sw-serveurs', 'oppositePorts', ['opposite' => $this->devices['sw-serveurs']->id]));
        $this->assertNull($this->asked('sw-serveurs', 'oppositePorts', ['opposite' => 999999]));

        $this->actingAs($this->reader)
            ->get(route('devices.show', ['device' => $this->devices['sw-serveurs'], 'opposite' => 'sw-coeur-01']))
            ->assertSessionHasErrors('opposite');
    }
}
