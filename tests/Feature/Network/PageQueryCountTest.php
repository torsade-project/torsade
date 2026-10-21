<?php

// torsade/tests/Feature/Network/PageQueryCountTest.php

namespace Tests\Feature\Network;

use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\Link;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The pages that work out deviations, those of the addressing rule
 * included, read what they need a fixed number of times: the page of a
 * site, the page of a block, the table of the catalogue and the page of the
 * addressing rule cost the same number of queries whatever they list. So
 * do the pages of the places and of the devices: the list of devices, the
 * page of a device and the page that turns host names into devices. And so
 * do the pages that list ports: the page of a stack, whatever the number of
 * its members and of their ports, and the page of a VLAN, whatever the
 * number of ports that carry it. And so do the page of a device whatever
 * the number of its links, and the list of every link. And so do the list
 * of jacks and the page of a patch panel, whatever the number of jacks and
 * of cords.
 */
class PageQueryCountTest extends NetworkTestCase
{
    /**
     * The number of queries a page sends, and how many objects it lists,
     * to check that the volume was really there.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{queries: int, props: array<string, mixed>}
     */
    private function load(User $user, string $route, array $parameters = []): array
    {
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->actingAs($user)->get(route($route, $parameters))->assertOk();

        // What is listened to from here on no longer counts.
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return ['queries' => $queries, 'props' => (array) $response->inertiaProps()];
    }

    /**
     * A site with its blocks, its VLANs attached to the catalogue, each
     * with a subnet and addresses, subnets without a VLAN, and subnets of
     * another site in its blocks. The index sets them apart.
     */
    private function fillSite(Site $site, Site $neighbour, int $index, int $volume): void
    {
        // One /16 per block, taken from 10.<index>.x.x: 10.<index * 8 + n>.0.0/16.
        for ($block = 0; $block < min($volume, 4); $block++) {
            AddressBlock::factory()->cidr('10.'.($index * 8 + $block).'.0.0/16')->forSite($site)->create();
        }

        for ($number = 1; $number <= $volume; $number++) {
            $entry = CatalogVlan::factory()->create(['vid' => $index * 1000 + $number, 'name' => "Entrée {$index}-{$number}"]);

            // Every other VLAN differs from its entry.
            $vlan = Vlan::factory()->create([
                'site_id' => $site->id,
                'catalog_vlan_id' => $entry->id,
                'vid' => $index * 1000 + $number + ($number % 2 === 0 ? 2000 : 0),
                'name' => "Entrée {$index}-{$number}",
            ]);

            $subnet = Subnet::factory()->cidr('10.'.($index * 8).'.'.$number.'.0/24')->create(['vlan_id' => $vlan->id]);
            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.'.($index * 8).'.'.$number.'.10']);

            // Without a VLAN, outside of the blocks of the site.
            Subnet::factory()->cidr('172.'.(16 + $index).'.'.$number.'.0/30')->create(['site_id' => $site->id]);

            // Of the neighbour, and without a site, in a block of this site.
            Subnet::factory()->cidr('10.'.($index * 8).'.'.(100 + $number).'.0/24')->create(['site_id' => $neighbour->id]);
            Subnet::factory()->cidr('10.'.($index * 8).'.'.(200 + $number % 50).'.'.($number * 4 % 256).'/30')->create();
        }
    }

    public function test_the_page_of_a_site_costs_the_same_whatever_the_site_holds(): void
    {
        $reader = $this->reader();
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        $small = Site::factory()->create(['name' => 'Petit site']);
        $large = Site::factory()->create(['name' => 'Grand site']);

        $this->fillSite($small, $neighbour, 1, 2);
        $this->fillSite($large, $neighbour, 2, 30);

        $onSmall = $this->load($reader, 'sites.show', ['site' => $small->id]);
        $onLarge = $this->load($reader, 'sites.show', ['site' => $large->id]);

        // The two pages do not list the same amount of things.
        $this->assertCount(2, $onSmall['props']['vlans']);
        $this->assertCount(30, $onLarge['props']['vlans']);
        $this->assertCount(2, $onSmall['props']['subnets']);
        $this->assertCount(30, $onLarge['props']['subnets']);
        $this->assertCount(2, $onSmall['props']['blocks']);
        $this->assertCount(4, $onLarge['props']['blocks']);
        // Deviations of the three sorts are worked out on both.
        $this->assertGreaterThan(count($onSmall['props']['deviations']), count($onLarge['props']['deviations']));
        $this->assertGreaterThanOrEqual(60, count($onLarge['props']['deviations']));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        // The page does read the database, a handful of times.
        $this->assertGreaterThan(5, $onLarge['queries']);
        $this->assertLessThan(25, $onLarge['queries']);
    }

    public function test_the_page_of_a_block_costs_the_same_whatever_the_block_holds(): void
    {
        $reader = $this->reader();
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        $small = Site::factory()->create(['name' => 'Petit site']);
        $large = Site::factory()->create(['name' => 'Grand site']);

        $this->fillSite($small, $neighbour, 1, 2);
        $this->fillSite($large, $neighbour, 2, 30);

        $smallBlock = AddressBlock::query()->where('site_id', $small->id)->orderBy('network')->firstOrFail();
        $largeBlock = AddressBlock::query()->where('site_id', $large->id)->orderBy('network')->firstOrFail();

        $onSmall = $this->load($reader, 'address-blocks.show', ['addressBlock' => $smallBlock->id]);
        $onLarge = $this->load($reader, 'address-blocks.show', ['addressBlock' => $largeBlock->id]);

        // Its own subnets, those of the neighbour and those without a site.
        $this->assertCount(6, $onSmall['props']['subnets']);
        $this->assertCount(90, $onLarge['props']['subnets']);

        $deviating = fn (array $page): int => count(array_filter($page['props']['subnets'], fn (array $subnet): bool => $subnet['deviations'] !== []));

        $this->assertSame(4, $deviating($onSmall));
        $this->assertSame(60, $deviating($onLarge));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        $this->assertGreaterThan(5, $onLarge['queries']);
        $this->assertLessThan(20, $onLarge['queries']);

        // A block of the organisation around everything costs the same too.
        $whole = AddressBlock::factory()->cidr('10.0.0.0/8')->create();

        $onWhole = $this->load($reader, 'address-blocks.show', ['addressBlock' => $whole->id]);

        $this->assertCount(96, $onWhole['props']['subnets']);
        $this->assertLessThanOrEqual($onLarge['queries'], $onWhole['queries']);
    }

    public function test_the_page_of_a_site_costs_the_same_with_the_deviations_of_the_addressing_rule(): void
    {
        $reader = $this->reader();

        AddressingRule::factory()->withBreakdown()->create();

        // VLANs numbered from 1, each with its subnet; every other subnet
        // is not the one the rule expects for its VLAN.
        $fill = function (Site $site, int $volume): void {
            for ($vid = 1; $vid <= $volume; $vid++) {
                $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid, 'name' => "VLAN {$vid}"]);
                $third = $vid % 2 === 0 ? 100 + $vid : $vid;

                Subnet::factory()->cidr("10.{$site->number}.{$third}.0/24")->create(['vlan_id' => $vlan->id]);
            }
        };

        // Neither site has declared the block the rule expects for it.
        $small = Site::factory()->create(['name' => 'Petit site', 'number' => 40]);
        $large = Site::factory()->create(['name' => 'Grand site', 'number' => 41]);

        $fill($small, 2);
        $fill($large, 30);

        $onSmall = $this->load($reader, 'sites.show', ['site' => $small->id]);
        $onLarge = $this->load($reader, 'sites.show', ['site' => $large->id]);

        // The three deviations of the rule are worked out on both pages.
        $this->assertCount(2, $onSmall['props']['vlans']);
        $this->assertCount(30, $onLarge['props']['vlans']);
        $this->assertCount(1, $onSmall['props']['deviations']);
        $this->assertCount(15, $onLarge['props']['deviations']);
        $this->assertSame('subnet_differs_from_rule', $onLarge['props']['deviations'][0]['deviations'][0]['kind']);
        $this->assertSame('site_block_missing', $onSmall['props']['siteDeviations'][0]['kind']);
        $this->assertSame('site_block_missing', $onLarge['props']['siteDeviations'][0]['kind']);
        $this->assertNotNull($onLarge['props']['rule']);

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        $this->assertGreaterThan(5, $onLarge['queries']);
        $this->assertLessThan(25, $onLarge['queries']);

        // A number the rule does not reach costs no more.
        $large->update(['number' => 300]);

        $beyond = $this->load($reader, 'sites.show', ['site' => $large->id]);

        $this->assertSame('site_number_outside_rule', $beyond['props']['siteDeviations'][0]['kind']);
        $this->assertSame([], $beyond['props']['deviations']);
        $this->assertSame($onLarge['queries'], $beyond['queries']);
    }

    public function test_the_page_of_the_addressing_rule_costs_the_same_whatever_the_number_of_sites(): void
    {
        $reader = $this->reader();

        AddressingRule::factory()->withBreakdown()->create();

        // Sites of the four kinds the table tells apart: with their block
        // declared, without it, numbered beyond the rule, without a number.
        $populate = function (int $base, int $count): void {
            for ($index = 1; $index <= $count; $index++) {
                $number = match (true) {
                    $index % 7 === 0 => null,
                    $index % 5 === 0 => 1000 + $base + $index,
                    default => $base + $index,
                };

                $site = Site::factory()->create(['name' => "Site {$base}-{$index}", 'number' => $number]);

                if ($number !== null && $number <= 255 && $index % 2 === 0) {
                    AddressBlock::factory()->cidr("10.{$number}.0.0/16")->forSite($site)->create();
                }
            }
        };

        $populate(0, 3);

        $onSmall = $this->load($reader, 'addressing-rule.show');

        $this->assertCount(3, $onSmall['props']['application']);

        $populate(10, 60);

        $onLarge = $this->load($reader, 'addressing-rule.show');

        $this->assertCount(63, $onLarge['props']['application']);

        // The four states are there.
        $states = array_count_values(array_column($onLarge['props']['application'], 'state'));

        ksort($states);

        $this->assertSame(['declared', 'missing', 'outside', 'unnumbered'], array_keys($states));
        $this->assertSame(63, array_sum($states));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        // The rule with its block and its ranges, the blocks a rule can
        // start from, the sites with their blocks, and the account.
        $this->assertGreaterThanOrEqual(5, $onLarge['queries']);
        $this->assertLessThan(12, $onLarge['queries']);
    }

    /**
     * The places and the devices of a site: buildings, each with floors and
     * rooms, a device in every room and devices without a room, each one
     * with addresses, some of them on the network of the neighbour.
     */
    private function furnishSite(Site $site, Site $neighbour, int $index, int $volume): void
    {
        $own = Subnet::factory()->cidr("10.{$index}.0.0/20")->create(['site_id' => $site->id]);
        $foreign = Subnet::factory()->cidr("10.{$index}.128.0/20")->create(['site_id' => $neighbour->id]);

        $host = 0;

        // An interface on its own site, and one on the network of the
        // neighbour, for each device.
        $connect = function (Device $device) use ($own, $foreign, $index, &$host): void {
            $host++;
            $third = intdiv($host, 250);
            $fourth = $host % 250 + 1;

            IpAddress::factory()->create(['subnet_id' => $own->id, 'address' => "10.{$index}.{$third}.{$fourth}", 'device_id' => $device->id, 'hostname' => $device->name]);
            IpAddress::factory()->create(['subnet_id' => $foreign->id, 'address' => "10.{$index}.".(128 + $third).".{$fourth}", 'device_id' => $device->id]);
            // And an address that belongs to no device yet.
            IpAddress::factory()->create(['subnet_id' => $own->id, 'address' => "10.{$index}.".(8 + $third).".{$fourth}", 'hostname' => "libre-{$index}-{$host}", 'device_type' => 'server']);
        };

        for ($number = 1; $number <= $volume; $number++) {
            $building = Building::factory()->create(['site_id' => $site->id, 'name' => "Bâtiment {$number}"]);

            foreach ([0, 1] as $level) {
                $floor = Floor::factory()->create(['building_id' => $building->id, 'level' => $level, 'name' => "Niveau {$level}"]);
                $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Local', 'is_technical' => $level === 0]);

                $connect(Device::factory()->create(['site_id' => $site->id, 'room_id' => $room->id, 'name' => "eq-{$index}-{$number}-{$level}"]));
            }

            $connect(Device::factory()->create(['site_id' => $site->id, 'name' => "eq-{$index}-{$number}-sans-local"]));
        }
    }

    public function test_the_page_of_a_site_costs_the_same_whatever_its_places_and_its_devices(): void
    {
        $reader = $this->reader();
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        $small = Site::factory()->create(['name' => 'Petit site']);
        $large = Site::factory()->create(['name' => 'Grand site']);

        $this->furnishSite($small, $neighbour, 1, 2);
        $this->furnishSite($large, $neighbour, 2, 30);

        $onSmall = $this->load($reader, 'sites.show', ['site' => $small->id]);
        $onLarge = $this->load($reader, 'sites.show', ['site' => $large->id]);

        // The two pages do not list the same amount of things.
        $this->assertCount(2, $onSmall['props']['places']);
        $this->assertCount(30, $onLarge['props']['places']);
        $this->assertCount(6, $onSmall['props']['devices']);
        $this->assertCount(90, $onLarge['props']['devices']);

        // Every floor, every room and its number of devices, every place
        // and every number of addresses are there.
        $this->assertSame(60, array_sum(array_map(fn (array $building): int => count($building['floors']), $onLarge['props']['places'])));
        $this->assertSame(60, array_sum(array_map(
            fn (array $building): int => array_sum(array_map(fn (array $floor): int => array_sum(array_column($floor['rooms'], 'devices_count')), $building['floors'])),
            $onLarge['props']['places'],
        )));
        $this->assertSame(60, count(array_filter($onLarge['props']['devices'], fn (array $device): bool => $device['room'] !== null)));
        $this->assertSame(180, array_sum(array_column($onLarge['props']['devices'], 'addresses_count')));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        $this->assertGreaterThan(5, $onLarge['queries']);
        $this->assertLessThan(25, $onLarge['queries']);

        // A site without any place nor device costs no more.
        $empty = $this->load($reader, 'sites.show', ['site' => $neighbour->id]);

        $this->assertSame([], $empty['props']['places']);
        $this->assertLessThanOrEqual($onLarge['queries'], $empty['queries']);
    }

    public function test_the_list_of_devices_costs_the_same_whatever_the_number_of_devices(): void
    {
        $reader = $this->reader();
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        $this->furnishSite(Site::factory()->create(['name' => 'Petit site']), $neighbour, 1, 2);

        $onSmall = $this->load($reader, 'devices.index');

        $this->assertCount(6, $onSmall['props']['devices']);

        $large = Site::factory()->create(['name' => 'Grand site']);

        $this->furnishSite($large, $neighbour, 2, 40);

        $onLarge = $this->load($reader, 'devices.index');

        $this->assertCount(126, $onLarge['props']['devices']);
        $this->assertSame(84, count(array_filter($onLarge['props']['devices'], fn (array $device): bool => $device['room'] !== null)));
        $this->assertSame(252, array_sum(array_column($onLarge['props']['devices'], 'addresses_count')));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        // The devices, their sites, their rooms with floors and
        // buildings, the sites of the filter, and the account.
        $this->assertGreaterThanOrEqual(5, $onLarge['queries']);
        $this->assertLessThan(12, $onLarge['queries']);

        // Filtered and searched, the list costs one query more, whatever
        // it lists: the one that checks the site of the filter exists.
        $filtered = $this->load($reader, 'devices.index', ['site' => $large->id, 'type' => 'server', 'status' => 'active', 'search' => 'eq-2-']);

        $this->assertCount(120, $filtered['props']['devices']);
        $this->assertSame($onLarge['queries'] + 1, $filtered['queries']);

        $narrowed = $this->load($reader, 'devices.index', ['site' => $large->id, 'search' => 'eq-2-1-']);

        $this->assertCount(3, $narrowed['props']['devices']);
        $this->assertSame($filtered['queries'], $narrowed['queries']);
    }

    public function test_the_page_of_a_device_costs_the_same_whatever_the_number_of_its_addresses(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create(['name' => 'Siège']);
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        // Subnets of the site and of the neighbour, each carried by a VLAN,
        // and subnets of no site.
        $subnets = [];

        foreach (range(1, 20) as $number) {
            $owner = $number % 2 === 0 ? $neighbour : $site;
            $vlan = Vlan::factory()->create(['site_id' => $owner->id, 'vid' => $number, 'name' => "VLAN {$number}"]);

            $subnets[] = Subnet::factory()->cidr("10.50.{$number}.0/24")->create(['vlan_id' => $vlan->id]);
            $subnets[] = Subnet::factory()->cidr("172.20.{$number}.0/24")->create();
        }

        $room = Room::factory()->inSite($site)->create();
        $small = Device::factory()->create(['site_id' => $site->id, 'room_id' => $room->id, 'name' => 'petit']);
        $large = Device::factory()->create(['site_id' => $site->id, 'room_id' => $room->id, 'name' => 'grand']);

        // Two addresses for one, three in every subnet for the other.
        foreach ($subnets as $place => $subnet) {
            $base = substr($subnet->network, 0, -1);

            if ($place < 2) {
                IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => $base.'5', 'device_id' => $small->id]);
            }

            foreach ([10, 11, 12] as $host) {
                IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => $base.$host, 'device_id' => $large->id]);
            }
        }

        $onSmall = $this->load($reader, 'devices.show', ['device' => $small->id]);
        $onLarge = $this->load($reader, 'devices.show', ['device' => $large->id]);

        $this->assertCount(2, $onSmall['props']['addresses']);
        $this->assertCount(120, $onLarge['props']['addresses']);
        // The addresses on the network of the neighbour are deviations;
        // those on a subnet of no site are not.
        $this->assertSame(30, count(array_filter($onLarge['props']['addresses'], fn (array $address): bool => $address['deviations'] !== [])));
        $this->assertSame(60, count(array_filter($onLarge['props']['addresses'], fn (array $address): bool => $address['vlan'] !== null)));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        $this->assertGreaterThan(5, $onLarge['queries']);
        // The page also asks for what its windows choose among: the
        // templates, the rooms and the VLANs of the site; and it reads the
        // jack cords of its ports and the cabled paths of its site.
        $this->assertLessThan(30, $onLarge['queries']);

        // So does the table of the addresses of a subnet, with the device
        // of each one.
        $subnet = $subnets[1];

        $before = $this->load($reader, 'subnets.show', ['subnet' => $subnet->id]);

        foreach (range(20, 99) as $host) {
            $device = Device::factory()->create(['site_id' => $host % 2 === 0 ? $site->id : $neighbour->id]);

            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => substr($subnet->network, 0, -1).$host, 'device_id' => $device->id]);
        }

        $after = $this->load($reader, 'subnets.show', ['subnet' => $subnet->id]);

        $this->assertCount(4, $before['props']['addresses']);
        $this->assertCount(84, $after['props']['addresses']);
        $this->assertCount(82, $after['props']['devices']);
        $this->assertSame($before['queries'], $after['queries']);
    }

    /**
     * A stack of so many members of 52 ports each, made from one template
     * and kept in rooms of its site: on each member, forty access ports
     * with a voice VLAN, of which four are disabled, eight trunks, and
     * four fibre ports of which two are part of an aggregate that spans
     * every member.
     *
     * @param  array<int, Vlan>  $vlans  The VLANs of the site, by number.
     */
    private function stack(Site $site, string $name, int $members, array $vlans, DeviceTemplate $template): Device
    {
        $room = Room::factory()->inSite($site)->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'room_id' => $room->id, 'name' => $name, 'type' => 'switch']);

        $aggregate = PortAggregate::factory()->create(['device_id' => $device->id, 'name' => 'Po1', 'mode' => 'trunk']);
        // And an aggregate that is declared, without a port nor a mode yet.
        PortAggregate::factory()->create(['device_id' => $device->id, 'name' => 'Po2']);

        PortVlan::factory()->create(['port_aggregate_id' => $aggregate->id, 'vlan_id' => $vlans[99]->id, 'tagged' => false]);

        foreach ([10, 20, 30] as $vid) {
            PortVlan::factory()->create(['port_aggregate_id' => $aggregate->id, 'vlan_id' => $vlans[$vid]->id, 'tagged' => true]);
        }

        for ($position = 1; $position <= $members; $position++) {
            $member = DeviceMember::factory()->create([
                'device_id' => $device->id,
                'position' => $position,
                'role' => match ($position) {
                    1 => 'primary',
                    2 => 'secondary',
                    default => 'member',
                },
                'device_template_id' => $template->id,
                'serial_number' => "SN-{$name}-{$position}",
                // Every other member is kept apart from the device.
                'room_id' => $position % 2 === 0 ? Room::factory()->inSite($site)->create()->id : null,
            ]);

            foreach (PortFactory::series($member, 48) as $place => $port) {
                if ($place < 40) {
                    $port->update(['mode' => 'access', 'enabled' => $place >= 4, 'description' => "Poste {$position}-{$place}"]);

                    PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlans[10]->id, 'tagged' => false]);
                    PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlans[30]->id, 'tagged' => true]);

                    continue;
                }

                $port->update(['mode' => 'trunk']);

                PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlans[99]->id, 'tagged' => false]);

                foreach ([10, 20, 30] as $vid) {
                    PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlans[$vid]->id, 'tagged' => true]);
                }
            }

            foreach (PortFactory::series($member, 4, 1) as $place => $port) {
                if ($place < 2) {
                    $port->update(['port_aggregate_id' => $aggregate->id]);
                }
            }
        }

        return $device;
    }

    public function test_the_page_of_a_device_and_the_page_of_a_vlan_cost_the_same_whatever_the_number_of_ports(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create(['name' => 'Siège']);
        $template = DeviceTemplate::factory()->switch(48, 4)->create(['name' => 'Switch 48 ports']);
        DeviceTemplate::factory()->switch(24, 4)->create(['name' => 'Switch 24 ports']);

        $vlans = [];

        foreach ([10, 20, 30, 99] as $vid) {
            $vlans[$vid] = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid, 'name' => "VLAN {$vid}"]);
        }

        // A stack of 2 × 52 ports, then one of 4 × 52 ports.
        $small = $this->stack($site, 'pile-de-deux', 2, $vlans, $template);

        $onSmall = $this->load($reader, 'devices.show', ['device' => $small->id]);
        $vlanBefore = $this->load($reader, 'vlans.show', ['vlan' => $vlans[10]->id]);

        $large = $this->stack($site, 'pile-de-quatre', 4, $vlans, $template);

        $onLarge = $this->load($reader, 'devices.show', ['device' => $large->id]);
        $vlanAfter = $this->load($reader, 'vlans.show', ['vlan' => $vlans[10]->id]);

        // The volume was really there: every member, every port with what
        // it carries, and the aggregates.
        $this->assertCount(2, $onSmall['props']['members']);
        $this->assertCount(104, $onSmall['props']['ports']);
        $this->assertCount(4, $onLarge['props']['members']);
        $this->assertCount(208, $onLarge['props']['ports']);
        $this->assertCount(2, $onLarge['props']['aggregates']);
        $this->assertSame([52, 52, 52, 52], array_column($onLarge['props']['members'], 'ports_count'));
        $this->assertSame(2, count(array_filter($onLarge['props']['members'], fn (array $member): bool => $member['room'] !== null)));

        $summaries = array_count_values(array_map(fn (array $port): string => (string) $port['summary'], $onLarge['props']['ports']));

        $this->assertSame(160, $summaries['Accès 10, voix 30']);
        $this->assertSame(32, $summaries['Trunk, natif 99 : 10, 20, 30']);
        $this->assertSame(8, count(array_filter($onLarge['props']['ports'], fn (array $port): bool => $port['aggregate'] !== null)));
        $this->assertSame(16, count(array_filter($onLarge['props']['ports'], fn (array $port): bool => ! $port['enabled'])));
        $this->assertSame('Trunk, natif 99 : 10, 20, 30', $onLarge['props']['aggregates'][0]['summary']);

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        $this->assertGreaterThan(10, $onLarge['queries']);
        // The cabling of each port is read with the ports, whatever their number.
        $this->assertLessThan(50, $onLarge['queries']);

        // The page of the VLAN: the ports of the first stack, then those
        // of both, and the aggregate of each.
        $carriers = fn (array $page): array => array_map(fn (array $group): int => count($group['carriers']), $page['props']['carriers']);

        // On each member, forty access ports and eight trunks carry the
        // VLAN; so does the aggregate of the stack.
        $this->assertSame([2 * 48 + 1], $carriers($vlanBefore));
        $this->assertSame([2 * 48 + 1, 4 * 48 + 1], $carriers($vlanAfter));

        $this->assertSame($vlanBefore['queries'], $vlanAfter['queries']);
        $this->assertGreaterThan(5, $vlanAfter['queries']);
        $this->assertLessThan(20, $vlanAfter['queries']);

        // A device without a port, and a VLAN no port carries, cost no
        // more.
        $bare = Device::factory()->create(['site_id' => $site->id, 'name' => 'nu']);
        $unused = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 50, 'name' => 'VLAN 50']);

        $this->assertLessThanOrEqual($onLarge['queries'], $this->load($reader, 'devices.show', ['device' => $bare->id])['queries']);
        $this->assertLessThanOrEqual($vlanAfter['queries'], $this->load($reader, 'vlans.show', ['vlan' => $unused->id])['queries']);

        // Six members of 52 ports in all.
        $this->assertSame(312, Port::query()->count());
    }

    /**
     * The ports of a device, by name.
     *
     * @return Collection<string, Port>
     */
    private function portsOf(Device $device): Collection
    {
        return Port::query()->where('device_id', $device->id)->get()->keyBy('name');
    }

    /**
     * Four VLANs of a site, by number: those the ports of a stack carry.
     *
     * @return array<int, Vlan>
     */
    private function vlansOf(Site $site): array
    {
        $vlans = [];

        foreach ([10, 20, 30, 99] as $vid) {
            $vlans[$vid] = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid, 'name' => "VLAN {$vid}"]);
        }

        return $vlans;
    }

    public function test_the_list_of_links_costs_the_same_whatever_the_number_of_links(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create(['name' => 'Siège']);
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $template = DeviceTemplate::factory()->switch(48, 4)->create(['name' => 'Switch 48 ports']);

        $ports = $this->portsOf($this->stack($site, 'pile', 2, $vlans = $this->vlansOf($site), $template));
        $near = $this->portsOf($this->stack($site, 'pile-voisine', 2, $vlans, $template));
        $away = $this->portsOf($this->stack($workshop, 'pile-atelier', 2, $this->vlansOf($workshop), $template));

        // Three links first: a port of its own, a port of an aggregate
        // that faces an aggregate, and a port of another site.
        $first = ['1/0/1' => $near, '1/1/1' => $near, '2/0/1' => $away];

        foreach ($first as $name => $opposite) {
            LinkFactory::between($ports[$name], $opposite[$name]);
        }

        $onFew = $this->load($reader, 'links.index');

        // Then the 104 ports of a stack linked, half of them to another
        // site, and the ports left of the two other stacks to each other.
        foreach ($ports as $name => $port) {
            if (! isset($first[$name])) {
                LinkFactory::between($port, (str_starts_with($name, '1/') ? $near : $away)[$name]);
            }
        }

        foreach ($near as $name => $port) {
            if (str_starts_with($name, '2/')) {
                LinkFactory::between($port, $away[str_replace('2/', '1/', $name)]);
            }
        }

        $onAll = $this->load($reader, 'links.index');
        $filtered = $this->load($reader, 'links.index', ['site' => $workshop->id, 'status' => 'active', 'deviations' => 1]);

        // The volume was really there: 156 links, of which six are three
        // aggregated links of two.
        $this->assertSame(3, $onFew['props']['total']);
        $this->assertSame(156, $onAll['props']['total']);
        $this->assertSame(156, array_sum(array_map(fn (array $line): int => count($line['links']), $onAll['props']['links'])));
        $this->assertSame(3, count(array_filter($onAll['props']['links'], fn (array $line): bool => $line['aggregated'])));
        $this->assertCount(153, $onAll['props']['links']);

        // So were the deviations: the four first ports of each member are
        // shut down, on both ends of twelve active links. The links between
        // the two sites merge their VLANs of the same numbers: the 104 of
        // them, but the four between ports without a mode, two aggregated
        // links counting as two lines. The filters keep those that have an
        // end at the workshop.
        $kinds = fn (array $line): array => array_column($line['deviations'], 'kind');

        $this->assertSame(12, count(array_filter($onAll['props']['links'], fn (array $line): bool => in_array('link_on_disabled_port', $kinds($line), true))));
        $this->assertSame(98, count(array_filter($onAll['props']['links'], fn (array $line): bool => in_array('link_merges_vlans', $kinds($line), true))));
        $this->assertSame(102, count(array_filter($onAll['props']['links'], fn (array $line): bool => $line['deviations'] !== [])));
        $this->assertCount(98, $filtered['props']['links']);

        $this->assertSame($onFew['queries'], $onAll['queries']);
        // Filtered, one query more: the one that checks that the site
        // asked for exists.
        $this->assertSame($onAll['queries'] + 1, $filtered['queries']);
        $this->assertGreaterThan(5, $onAll['queries']);
        $this->assertLessThan(25, $onAll['queries']);

        // Without a link, the page costs less.
        Link::query()->delete();

        $this->assertLessThan($onAll['queries'], $this->load($reader, 'links.index')['queries']);
    }

    public function test_the_page_of_a_device_costs_the_same_whatever_the_number_of_its_links(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create(['name' => 'Siège']);
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $template = DeviceTemplate::factory()->switch(48, 4)->create(['name' => 'Switch 48 ports']);

        // Three stacks of 2 × 52 ports: the one whose page is read, one of
        // its site and one of another site.
        $device = $this->stack($site, 'pile', 2, $vlans = $this->vlansOf($site), $template);
        $ports = $this->portsOf($device);
        $near = $this->portsOf($this->stack($site, 'pile-voisine', 2, $vlans, $template));
        $away = $this->portsOf($this->stack($workshop, 'pile-atelier', 2, $workshopVlans = $this->vlansOf($workshop), $template));

        // Without a link, then with three of them: a port of its own, a
        // port of an aggregate that faces an aggregate, and a port of
        // another site.
        $without = $this->load($reader, 'devices.show', ['device' => $device->id]);

        $first = ['1/0/1' => $near, '1/1/1' => $near, '2/0/1' => $away];

        foreach ($first as $name => $opposite) {
            LinkFactory::between($ports[$name], $opposite[$name]);
        }

        $onFew = $this->load($reader, 'devices.show', ['device' => $device->id]);

        // Then every port of the stack linked: its member 1 to the stack
        // next to it, its member 2 to the one of the workshop.
        foreach ($ports as $name => $port) {
            if (! isset($first[$name])) {
                LinkFactory::between($port, (str_starts_with($name, '1/') ? $near : $away)[$name]);
            }
        }

        $onAll = $this->load($reader, 'devices.show', ['device' => $device->id]);

        // The volume was really there: 2 × 52 ports, all of them linked.
        $this->assertCount(104, $onAll['props']['ports']);
        $this->assertSame(104, count(array_filter($onAll['props']['ports'], fn (array $port): bool => $port['link'] !== null)));
        $this->assertSame(3, count(array_filter($onFew['props']['ports'], fn (array $port): bool => $port['link'] !== null)));
        $this->assertSame(104, array_sum(array_map(fn (array $line): int => count($line['links']), $onAll['props']['links'])));

        // The four ports of its aggregate face an aggregate of each of the
        // two other stacks: two aggregated links of two links each, and a
        // hundred links of their own.
        $aggregated = array_values(array_filter($onAll['props']['links'], fn (array $line): bool => $line['aggregated']));

        $this->assertCount(102, $onAll['props']['links']);
        $this->assertSame([2, 2], array_map(fn (array $line): int => count($line['links']), $aggregated));
        $this->assertSame(['pile-voisine', 'pile-atelier'], array_map(fn (array $line): string => $line['links'][0]['far']['device']['name'], $aggregated));

        // And so were the deviations: the aggregate leads to two devices,
        // the four first ports of each member are shut down on both ends of
        // their active link, and the links of the member 2 merge the VLANs
        // of the two sites, but those of its two ports without a mode.
        $kinds = fn (array $line): array => array_column($line['deviations'], 'kind');

        $this->assertSame(
            [['kind' => 'aggregate_several_devices', 'reason' => 'Po1 mène à pile-atelier et pile-voisine']],
            $onAll['props']['aggregates'][0]['deviations'],
        );
        $this->assertSame(8, count(array_filter($onAll['props']['links'], fn (array $line): bool => in_array('link_on_disabled_port', $kinds($line), true))));
        $this->assertSame(49, count(array_filter($onAll['props']['links'], fn (array $line): bool => in_array('link_merges_vlans', $kinds($line), true))));
        $this->assertCount(9 + 45, $onAll['props']['linkDeviations']);

        $this->assertSame($onFew['queries'], $onAll['queries']);
        $this->assertGreaterThan($without['queries'], $onAll['queries']);
        $this->assertLessThan(60, $onAll['queries']);

        // The page of a device that faces it costs the same.
        $this->assertSame($onAll['queries'], $this->load($reader, 'devices.show', ['device' => $away->firstOrFail()->device_id])['queries']);

        // So does the stack once two of its ports carry the VLAN 10 of the
        // workshop, which it merges with its own.
        foreach (['2/1/3', '2/1/4'] as $name) {
            $ports[$name]->update(['mode' => 'trunk']);

            PortVlan::factory()->create(['port_id' => $ports[$name]->id, 'vlan_id' => $workshopVlans[10]->id, 'tagged' => true]);
        }

        $merged = $this->load($reader, 'devices.show', ['device' => $device->id]);

        $this->assertSame(
            [['kind' => 'switch_merges_vlans', 'reason' => 'VLAN 10 des sites Siège et Atelier : pile les fusionne']],
            $merged['props']['linkDeviations'][0]['deviations'],
        );
        $this->assertSame($onAll['queries'], $merged['queries']);
    }

    public function test_the_page_that_turns_host_names_into_devices_costs_the_same_whatever_the_number_of_names(): void
    {
        $editor = $this->editor();
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        $small = Site::factory()->create(['name' => 'Petit site']);
        $large = Site::factory()->create(['name' => 'Grand site']);

        $this->furnishSite($small, $neighbour, 1, 2);
        $this->furnishSite($large, $neighbour, 2, 30);

        $onSmall = $this->load($editor, 'sites.hostname-devices.create', ['site' => $small->id]);
        $onLarge = $this->load($editor, 'sites.hostname-devices.create', ['site' => $large->id]);

        // One line per address that belongs to no device.
        $this->assertCount(6, $onSmall['props']['candidates']);
        $this->assertCount(90, $onLarge['props']['candidates']);
        $this->assertSame('server', $onLarge['props']['candidates'][0]['type']);

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        // The addresses with their subnet, the devices of the site, the
        // site and the account.
        $this->assertGreaterThanOrEqual(4, $onLarge['queries']);
        $this->assertLessThan(10, $onLarge['queries']);
    }

    /**
     * Ports of a panel cabled one after the other: a jack of the floor on
     * each, a cord from each to the next port of the switches, and an
     * address of the subnet plugged into every other jack.
     *
     * @param  list<Port>  $ports  The ports of the switches, in their order; those patched are taken away.
     */
    private function cable(PatchPanel $panel, Floor $floor, array &$ports, Subnet $subnet, int $from, int $to): void
    {
        $panelPorts = PatchPanelPort::query()->where('patch_panel_id', $panel->id)->whereBetween('number', [$from, $to])->orderBy('number')->get();

        foreach ($panelPorts as $panelPort) {
            $jack = Jack::factory()->create(['floor_id' => $floor->id, 'label' => $panel->name.'.'.$panelPort->number, 'patch_panel_port_id' => $panelPort->id]);

            PatchCordFactory::between($panelPort, array_shift($ports) ?? $this->fail('Not enough ports to patch.'));

            if ($panelPort->number % 2 === 0) {
                $count = IpAddress::query()->count();

                IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.'.intdiv($count, 250).'.'.($count % 250 + 1), 'jack_id' => $jack->id]);
            }
        }
    }

    /**
     * A site whose cabling holds every kind of path: patched to an access
     * port, to a trunk, to a port of an aggregate, to a linked port; a jack
     * cabled and not patched, a jack on its own, each with an active
     * address. What the volume then adds is more of the same.
     *
     * @return array{panel: PatchPanel, floors: list<Floor>, ports: list<Port>, subnet: Subnet, site: Site}
     */
    private function cabledSite(int $stacks): array
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $template = DeviceTemplate::factory()->switch(48, 4)->create(['name' => 'Switch 48 ports']);
        $vlans = $this->vlansOf($site);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Bâtiment principal']);
        $floors = [];

        foreach (range(0, 3) as $level) {
            $floors[] = Floor::factory()->create(['building_id' => $building->id, 'level' => $level, 'name' => "Étage {$level}"]);
        }

        $rack = Room::factory()->technical()->create(['floor_id' => $floors[0]->id, 'name' => 'Local technique']);
        $panel = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-A', 'port_count' => 96]);
        $subnet = Subnet::factory()->cidr('10.10.0.0/16')->create(['site_id' => $site->id, 'vlan_id' => $vlans[10]->id]);

        $ports = [];

        for ($stack = 1; $stack <= $stacks; $stack++) {
            $ports = [...$ports, ...array_values($this->portsOf($this->stack($site, "pile-{$stack}", 2, $vlans, $template))->sortBy('id')->all())];
        }

        // A linked port, a trunk, a port of an aggregate and a port of a member
        // kept in a room of its own first.
        $first = array_values(array_filter($ports, fn (Port $port): bool => in_array($port->name, ['1/0/2', '1/0/41', '1/1/1', '2/0/1'], true)));
        $ports = [...$first, ...array_values(array_filter($ports, fn (Port $port): bool => ! in_array($port, $first, true)))];

        $core = Device::factory()->create(['site_id' => $site->id, 'name' => 'sw-coeur-01']);
        LinkFactory::between($first[0], PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $core->id]), 1)[0]);

        $this->cable($panel, $floors[1], $ports, $subnet, 1, 4);

        // A jack cabled and not patched, and a jack on its own.
        $cabled = Jack::factory()->create(['floor_id' => $floors[1]->id, 'label' => 'X.1', 'patch_panel_port_id' => PatchPanelPort::query()->where('patch_panel_id', $panel->id)->where('number', 96)->value('id')]);
        $alone = Jack::factory()->create(['floor_id' => $floors[2]->id, 'label' => 'X.2']);

        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.255.1', 'jack_id' => $cabled->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.255.2', 'jack_id' => $alone->id]);

        return ['panel' => $panel, 'floors' => $floors, 'ports' => $ports, 'subnet' => $subnet, 'site' => $site];
    }

    public function test_the_list_of_jacks_costs_the_same_whatever_the_number_of_jacks(): void
    {
        $reader = $this->reader();

        ['panel' => $panel, 'floors' => $floors, 'ports' => $ports, 'subnet' => $subnet] = $this->cabledSite(5);

        $onFew = $this->load($reader, 'jacks.index');

        // Then five hundred patched jacks: the rest of the first panel, and
        // five more panels, on the floors of the building.
        $this->cable($panel, $floors[1], $ports, $subnet, 5, 95);

        foreach (range(1, 5) as $index) {
            $more = PatchPanel::factory()->create(['room_id' => $panel->room_id, 'name' => "PB-{$index}", 'port_count' => 96]);

            $this->cable($more, $floors[$index % 4], $ports, $subnet, 1, $index === 5 ? 21 : 96);
        }

        $onAll = $this->load($reader, 'jacks.index');

        // The volume was really there.
        $this->assertSame(6, $onFew['props']['total']);
        $this->assertSame(502, $onAll['props']['total']);
        $this->assertSame(500, count(array_filter($onAll['props']['jacks'], fn (array $jack): bool => $jack['path']['device'] !== null)));
        // An address on every other patched jack, and on the two that are not.
        $this->assertSame(249 + 2, count(array_filter($onAll['props']['jacks'], fn (array $jack): bool => $jack['addresses'] !== [])));
        // So were the deviations: the linked port, the two jacks that are
        // not patched, and the addresses whose VLAN a trunk does not carry.
        $this->assertNotSame([], array_filter($onAll['props']['jacks'], fn (array $jack): bool => $jack['deviations'] !== []));

        $this->assertSame($onFew['queries'], $onAll['queries']);
        $this->assertGreaterThan(10, $onAll['queries']);
        // The cabled paths of the jacks are read in one go too.
        $this->assertLessThan(50, $onAll['queries']);
    }

    public function test_the_page_of_a_panel_costs_the_same_whatever_the_number_of_its_cords(): void
    {
        $reader = $this->reader();

        ['panel' => $panel, 'floors' => $floors, 'ports' => $ports, 'subnet' => $subnet] = $this->cabledSite(1);

        $onFew = $this->load($reader, 'patch-panels.show', ['patchPanel' => $panel]);

        // Then every port of the panel patched, the last one too, whose
        // jack was not.
        $this->cable($panel, $floors[1], $ports, $subnet, 5, 95);

        PatchCordFactory::between(PatchPanelPort::query()->where('patch_panel_id', $panel->id)->where('number', 96)->sole(), array_shift($ports) ?? $this->fail('Not enough ports to patch.'));

        $onAll = $this->load($reader, 'patch-panels.show', ['patchPanel' => $panel]);

        // The volume was really there: 96 ports patched.
        $this->assertCount(96, $onAll['props']['ports']);
        $this->assertSame(96, count(array_filter($onAll['props']['ports'], fn (array $port): bool => $port['cord'] !== null)));
        $this->assertSame(96, count(array_filter($onAll['props']['ports'], fn (array $port): bool => $port['jack'] !== null)));
        $this->assertSame(96, $onAll['props']['panel']['occupied']);

        $this->assertSame($onFew['queries'], $onAll['queries']);
        $this->assertGreaterThan(10, $onAll['queries']);
        // The cabled paths of the site of the panel are read in one go too.
        $this->assertLessThan(65, $onAll['queries']);
    }

    public function test_the_table_of_the_catalogue_costs_the_same_whatever_the_catalogue_holds(): void
    {
        $reader = $this->reader();
        $neighbour = Site::factory()->create(['name' => 'Voisin']);

        $this->fillSite(Site::factory()->create(['name' => 'Petit site']), $neighbour, 1, 2);

        $onSmall = $this->load($reader, 'catalog-vlans.index');

        $this->assertCount(2, $onSmall['props']['entries']);
        $this->assertCount(2, $onSmall['props']['sites']);

        // More entries, more sites, more VLANs attached, more deviations.
        $this->fillSite(Site::factory()->create(['name' => 'Grand site']), $neighbour, 2, 30);

        foreach (range(3, 12) as $index) {
            $site = Site::factory()->create(['name' => "Site {$index}"]);

            foreach (CatalogVlan::query()->limit(20)->get() as $entry) {
                Vlan::factory()->create(['site_id' => $site->id, 'catalog_vlan_id' => $entry->id, 'vid' => $entry->vid + $index, 'name' => $entry->name]);
            }
        }

        $onLarge = $this->load($reader, 'catalog-vlans.index');

        $this->assertCount(32, $onLarge['props']['entries']);
        $this->assertCount(13, $onLarge['props']['sites']);
        $this->assertSame(32 + 200, array_sum(array_map(fn (array $entry): int => count($entry['vlans']), $onLarge['props']['entries'])));

        $this->assertSame($onSmall['queries'], $onLarge['queries']);
        // The entries, their VLANs and the sites, and the account.
        $this->assertGreaterThanOrEqual(3, $onLarge['queries']);
        $this->assertLessThan(10, $onLarge['queries']);
    }
}
