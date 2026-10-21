<?php

// torsade/tests/Feature/Console/DemoCommandTest.php

namespace Tests\Feature\Console;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\DiagramView;
use App\Enums\GatewayPosition;
use App\Enums\IpAssignment;
use App\Enums\RangeKind;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Activity;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\AddressingRuleRange;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\DiagramPosition;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Jack;
use App\Models\JackCord;
use App\Models\Link;
use App\Models\PatchCord;
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
use App\Services\Network\CabledPaths;
use App\Services\Network\CablingDeviations;
use App\Services\Network\Deviations;
use App\Services\Network\DiagramLayouts;
use App\Services\Network\LinkDeviations;
use App\Services\Network\Links;
use App\Services\Network\LogicalDiagram;
use App\Services\Network\PhysicalDiagram;
use App\Support\AddressingScheme;
use App\Support\Ipv4;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoCommandTest extends TestCase
{
    use RefreshDatabase;

    private function assertNothingWasCreated(): void
    {
        $this->assertDatabaseCount('catalog_vlans', 0);
        $this->assertDatabaseCount('address_blocks', 0);
        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('ip_ranges', 0);
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_it_fills_an_empty_installation(): void
    {
        $exitCode = Artisan::call('torsade:demo');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Données de démonstration créées.', $output);

        // Three sites, a catalogue, four blocks, an addressing rule, about
        // ten VLANs with their subnets, a hundred addresses.
        $this->assertSame(3, Site::query()->count());
        $this->assertSame(6, CatalogVlan::query()->count());
        $this->assertSame(4, AddressBlock::query()->count());
        $this->assertSame(13, Vlan::query()->count());
        $this->assertSame(17, Subnet::query()->count());
        $this->assertSame(8, IpRange::query()->count());
        $this->assertSame(107, IpAddress::query()->count());
        // Two buildings, their floors and their rooms, and the devices.
        $this->assertSame(2, Building::query()->count());
        $this->assertSame(5, Floor::query()->count());
        $this->assertSame(12, Room::query()->count());
        $this->assertSame(34, Device::query()->count());

        // The command says what it created.
        foreach (['Entrées du catalogue', "Blocs d'adressage", "Règle d'adressage", 'Plages', 'Bâtiments', 'Étages', 'Locaux', 'Équipements', 'Membres', 'Ports', 'Agrégats', 'Liaisons'] as $line) {
            $this->assertStringContainsString($line, $output);
        }

        // Every VLAN has its subnet, and four subnets have no VLAN.
        $this->assertSame(13, Subnet::query()->whereNotNull('vlan_id')->count());
        $this->assertSame(0, Vlan::query()->whereDoesntHave('subnet')->count());

        // The same VLAN numbers are used on both sites.
        $this->assertSame(2, Vlan::query()->where('vid', 10)->count());
    }

    public function test_the_addresses_are_varied(): void
    {
        Artisan::call('torsade:demo');

        $addresses = IpAddress::query()->get();

        foreach ([Status::Planned, Status::Active, Status::Retired] as $status) {
            $this->assertTrue($addresses->contains('status', $status), "No address is {$status->value}.");
        }

        foreach (IpAssignment::cases() as $assignment) {
            $this->assertTrue($addresses->contains('assignment', $assignment), "No address is {$assignment->value}.");
        }

        // At least nine of the twelve kinds of devices.
        $this->assertGreaterThanOrEqual(9, $addresses->pluck('device_type')->filter()->unique()->count());
        $this->assertTrue($addresses->contains('device_type', DeviceType::Server));
        $this->assertTrue($addresses->contains('device_type', DeviceType::IpPhone));

        $this->assertGreaterThan(80, $addresses->whereNotNull('mac_address')->count());
        $this->assertSame(107, $addresses->whereNotNull('hostname')->count());

        foreach ([Status::Planned, Status::Active, Status::Retired] as $status) {
            $this->assertTrue(Vlan::query()->where('status', $status)->exists());
        }
    }

    public function test_the_data_is_fictitious_and_uses_private_or_documentation_addresses_only(): void
    {
        Artisan::call('torsade:demo');

        $private = [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16]];
        // The three ranges set aside for documentation: no network uses them.
        $documentation = [['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24]];

        $isIn = fn (array $blocks, string $network, int $prefix): bool => array_any(
            $blocks,
            fn (array $block): bool => Ipv4::prefixContains($block[0], $block[1], $network, $prefix),
        );

        foreach (Subnet::query()->get() as $subnet) {
            // A public address is only made up for what belongs to someone
            // else, and then taken from the documentation ranges.
            $this->assertTrue(
                $subnet->kind === SubnetKind::External
                    ? $isIn($documentation, $subnet->network, $subnet->prefix_length)
                    : $isIn($private, $subnet->network, $subnet->prefix_length),
                "[{$subnet->cidr()}] is neither a private subnet nor a documentation one.",
            );
        }

        foreach (AddressBlock::query()->get() as $block) {
            $this->assertTrue($isIn($private, $block->network, $block->prefix_length), $block->cidr());
        }

        $this->assertSame(['203.0.113.8/29'], Subnet::query()->where('kind', SubnetKind::External)->get()->map->cidr()->all());

        // MAC addresses are in the locally administered range: no manufacturer.
        foreach (IpAddress::query()->whereNotNull('mac_address')->pluck('mac_address') as $mac) {
            $this->assertStringStartsWith('02:00:', $mac);
        }

        // The sites say what they are.
        foreach (Site::query()->get() as $site) {
            $this->assertStringContainsString('société fictive', (string) $site->description);
        }
    }

    public function test_the_data_obeys_the_rules_of_the_addressing_plan(): void
    {
        Artisan::call('torsade:demo');

        $subnets = Subnet::query()->with(['ipRanges', 'ipAddresses'])->get();

        foreach ($subnets as $subnet) {
            foreach ($subnets as $other) {
                if (! $subnet->is($other)) {
                    $this->assertFalse(Ipv4::overlaps($subnet->network, $subnet->prefix_length, $other->network, $other->prefix_length));
                }
            }

            foreach ($subnet->ipAddresses as $address) {
                $this->assertTrue(Ipv4::isUsable($subnet->network, $subnet->prefix_length, $address->address));

                $inRange = $subnet->ipRanges->contains(
                    fn (IpRange $range): bool => $range->kind === RangeKind::Dhcp
                        && Ipv4::inRange($address->address, $range->start_address, $range->end_address),
                );

                if ($address->assignment === IpAssignment::Static) {
                    $this->assertFalse($inRange, "[{$address->address}] is static inside a DHCP range.");
                }
            }
        }
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $exitCode = Artisan::call('torsade:demo');

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('ne peuvent pas être créées en production', Artisan::output());
        $this->assertDatabaseCount('sites', 0);
        $this->assertNothingWasCreated();
    }

    public function test_it_refuses_to_run_when_network_data_exists(): void
    {
        Site::factory()->create(['name' => 'Head office']);

        $exitCode = Artisan::call('torsade:demo');

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Des données réseau existent déjà', Artisan::output());
        $this->assertDatabaseCount('sites', 1);
        $this->assertNothingWasCreated();
    }

    public function test_it_refuses_to_run_twice(): void
    {
        $this->assertSame(0, Artisan::call('torsade:demo'));
        $this->assertNotSame(0, Artisan::call('torsade:demo'));

        $this->assertSame(3, Site::query()->count());
        $this->assertSame(107, IpAddress::query()->count());
        $this->assertSame(34, Device::query()->count());
    }

    public function test_a_building_or_a_device_alone_is_enough_to_refuse(): void
    {
        $site = Site::factory()->create();
        $building = Building::factory()->create(['site_id' => $site->id]);

        $this->assertNotSame(0, Artisan::call('torsade:demo'));
        $this->assertDatabaseCount('sites', 1);

        $building->delete();
        Device::factory()->create(['site_id' => $site->id]);

        $this->assertNotSame(0, Artisan::call('torsade:demo'));
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_a_catalogue_entry_or_a_block_alone_is_enough_to_refuse(): void
    {
        CatalogVlan::factory()->create();

        $this->assertNotSame(0, Artisan::call('torsade:demo'));
        $this->assertDatabaseCount('sites', 0);

        CatalogVlan::query()->delete();
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();

        $this->assertNotSame(0, Artisan::call('torsade:demo'));
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('catalog_vlans', 0);
    }

    // --- The structure of the addressing ----------------------------------------

    public function test_each_site_has_a_code_and_a_block_inside_the_block_of_the_organisation(): void
    {
        Artisan::call('torsade:demo');

        $this->assertSame(['ATE', 'DEP', 'SIE'], Site::query()->orderBy('code')->pluck('code')->all());

        $organisation = AddressBlock::query()->whereNull('site_id')->sole();

        $this->assertSame('10.0.0.0/8', $organisation->cidr());

        $ofSites = AddressBlock::query()->whereNotNull('site_id')->with('site')->get();

        $this->assertSame(
            ['Atelier' => '10.20.0.0/16', 'Dépôt' => '10.30.0.0/16', 'Siège' => '10.10.0.0/16'],
            $ofSites->mapWithKeys(fn (AddressBlock $block): array => [$block->site->name => $block->cidr()])->sortKeys()->all(),
        );

        foreach ($ofSites as $block) {
            $this->assertTrue(Ipv4::prefixContains($organisation->network, $organisation->prefix_length, $block->network, $block->prefix_length));
        }
    }

    public function test_the_sites_are_numbered_as_the_addressing_rule_expects_their_blocks(): void
    {
        Artisan::call('torsade:demo');

        $this->assertSame(
            ['Siège' => 10, 'Atelier' => 20, 'Dépôt' => 30],
            Site::query()->orderBy('number')->pluck('number', 'name')->all(),
        );

        // The rule: 10.<site>.<VLAN>.0/24, the gateway first, and the
        // usual ranges.
        $rule = AddressingRule::query()->with(['aggregateBlock', 'ranges'])->sole();

        $this->assertSame('10.0.0.0/8', $rule->aggregateBlock->cidr());
        $this->assertSame([16, 24, GatewayPosition::First], [$rule->site_prefix_length, $rule->vlan_prefix_length, $rule->gateway_position]);
        $this->assertSame(
            ['reserved 2-20 Infrastructure', 'static 21-99 Adresses fixes', 'dhcp 100-200'],
            $rule->ranges->map(fn (AddressingRuleRange $range): string => $range->label())->all(),
        );

        // Each site has declared the block its number gives it: nothing
        // differs from the rule there.
        $deviations = app(Deviations::class);
        $scheme = $deviations->scheme();

        $this->assertNotNull($scheme);

        foreach (Site::query()->with('addressBlocks')->get() as $site) {
            $this->assertSame([], $deviations->ofSite($site, $scheme), $site->name);
            $this->assertSame(
                AddressingScheme::cidr((array) $scheme->siteBlock($site->number)),
                $site->addressBlocks->sole()->cidr(),
                $site->name,
            );
        }
    }

    public function test_a_numbered_site_without_any_vlan_waits_for_the_standard_to_be_applied(): void
    {
        Artisan::call('torsade:demo');

        $site = Site::query()->where('name', 'Dépôt')->sole();

        $this->assertSame(30, $site->number);
        $this->assertSame(0, $site->vlans()->count());
        $this->assertSame(0, $site->subnets()->count());
        $this->assertStringContainsString('Appliquer la norme', (string) $site->description);

        // Its status says it is planned: its description no longer has to.
        $this->assertSame(Status::Planned, $site->status);
        $this->assertStringNotContainsStringIgnoringCase('prévu', (string) $site->description);
        $this->assertSame(
            ['Atelier' => 'active', 'Dépôt' => 'planned', 'Siège' => 'active'],
            Site::query()->orderBy('name')->get()->mapWithKeys(fn (Site $one): array => [$one->name => $one->status->value])->all(),
        );
        // Nothing is placed there yet.
        $this->assertSame([0, 0], [$site->buildings()->count(), $site->devices()->count()]);

        // It is being planned: so is its block.
        $block = $site->addressBlocks()->sole();

        $this->assertSame(['10.30.0.0/16', Status::Planned], [$block->cidr(), $block->status]);

        // Every entry of the catalogue is there to apply, with the subnet
        // the rule expects for it.
        $this->actingAs(User::factory()->editor()->create())
            ->get(route('sites.standard.create', $site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 6)
                ->where('site.number', 30)
                ->where('rule.network', '10.0.0.0'));
    }

    public function test_the_catalogue_has_five_entries_at_least_followed_by_vlans_of_both_sites(): void
    {
        Artisan::call('torsade:demo');

        $entries = CatalogVlan::query()->with('vlans.site')->orderBy('vid')->get();

        $this->assertGreaterThanOrEqual(5, $entries->count());
        $this->assertSame([10, 20, 40, 50, 60, 99], $entries->pluck('vid')->all());

        // Every entry is followed somewhere, and both sites follow some.
        foreach ($entries as $entry) {
            $this->assertGreaterThanOrEqual(1, $entry->vlans->count(), $entry->label());
        }

        $this->assertSame(
            ['Atelier' => 4, 'Siège' => 5],
            Vlan::query()->whereNotNull('catalog_vlan_id')->with('site')->get()->countBy(fn (Vlan $vlan): string => $vlan->site->name)->sortKeys()->all(),
        );

        // An entry followed on both sites.
        $this->assertSame(['Atelier', 'Siège'], $entries->firstWhere('vid', 10)->vlans->pluck('site.name')->sort()->values()->all());

        // The telephony VLANs of both sites are left to be attached by hand.
        $this->assertSame(2, Vlan::query()->where('vid', 30)->whereNull('catalog_vlan_id')->count());
        $this->assertFalse(CatalogVlan::query()->where('vid', 30)->exists());
    }

    public function test_there_is_a_subnet_of_each_kind_and_a_range_of_each_kind(): void
    {
        Artisan::call('torsade:demo');

        foreach (SubnetKind::cases() as $kind) {
            $this->assertTrue(Subnet::query()->where('kind', $kind)->exists(), "No {$kind->value} subnet.");
        }

        foreach (RangeKind::cases() as $kind) {
            $this->assertTrue(IpRange::query()->where('kind', $kind)->exists(), "No {$kind->value} range.");
        }

        $this->assertSame(
            ['dhcp' => 5, 'reserved' => 1, 'static' => 2],
            IpRange::query()->get()->countBy(fn (IpRange $range): string => $range->kind->value)->sortKeys()->all(),
        );

        // Each kind obeys what it accepts.
        foreach (Subnet::query()->with('ipRanges')->get() as $subnet) {
            $this->assertTrue($subnet->kind->allowsPrefix($subnet->prefix_length), $subnet->cidr());
            $this->assertTrue($subnet->kind->allowsVlan() || $subnet->vlan_id === null, $subnet->cidr());
            $this->assertTrue($subnet->kind->allowsGateway() || $subnet->gateway === null, $subnet->cidr());
            $this->assertTrue($subnet->kind->allowsRanges() || $subnet->ipRanges->isEmpty(), $subnet->cidr());
        }

        // A subnet carried by a VLAN is on the site of that VLAN.
        foreach (Subnet::query()->whereNotNull('vlan_id')->with('vlan')->get() as $subnet) {
            $this->assertSame($subnet->vlan?->site_id, $subnet->site_id, $subnet->cidr());
        }
    }

    public function test_the_announced_deviations_are_there_and_each_one_is_explained_in_a_description(): void
    {
        Artisan::call('torsade:demo');

        $deviations = app(Deviations::class);
        $siteBlocks = $deviations->siteBlocks();

        // A VLAN whose number is not the one of its entry.
        $found = [];

        foreach (Vlan::query()->with(['catalogVlan', 'site'])->get() as $vlan) {
            foreach ($deviations->ofVlan($vlan) as $deviation) {
                $found[] = [$vlan->site->name.' / '.$vlan->label(), $deviation['reason']];

                $this->assertStringContainsString('écart', (string) $vlan->description, $vlan->label());
            }
        }

        $this->assertSame([['Atelier / 150 — Vidéosurveillance', 'VID 150, catalogue : 50']], $found);

        // A segment outside of the blocks of its site, and two subnets
        // that are not the ones the addressing rule expects.
        $found = [];

        foreach (Subnet::query()->with(['vlan', 'site'])->get() as $subnet) {
            foreach ($deviations->ofSubnet($subnet, $siteBlocks, $deviations->scheme()) as $deviation) {
                $this->assertSame(SubnetKind::Segment, $subnet->kind);

                $found[$subnet->cidr()][] = $deviation['reason'];

                $this->assertStringContainsString('écart', (string) $subnet->description, $subnet->cidr());
            }
        }

        ksort($found);

        $this->assertSame(
            [
                '10.20.50.0/24' => ['Attendu par la règle : 10.20.150.0/24'],
                '192.168.1.0/24' => ['Hors des blocs du site (10.10.0.0/16)', 'Attendu par la règle : 10.10.5.0/24'],
            ],
            $found,
        );

        // The public addresses of the carrier are outside of the block of
        // the head office too, and that is no deviation: they are no segment.
        $carrier = Subnet::query()->get()->firstOrFail(fn (Subnet $subnet): bool => $subnet->cidr() === '203.0.113.8/29');

        $this->assertSame(SubnetKind::External, $carrier->kind);
        $this->assertSame('Siège', $carrier->site?->name);
        $this->assertStringNotContainsString('écart', (string) $carrier->description);
    }

    public function test_the_two_sites_have_a_building_with_floors_and_rooms_one_of_them_technical(): void
    {
        Artisan::call('torsade:demo');

        $places = fn (string $site): array => Building::query()
            ->whereRelation('site', 'name', $site)
            ->with(['floors' => fn ($query) => $query->orderBy('level'), 'floors.rooms' => fn ($query) => $query->orderBy('name')])
            ->get()
            ->mapWithKeys(fn (Building $building): array => [
                $building->name.' ('.$building->code.')' => $building->floors
                    ->mapWithKeys(fn (Floor $floor): array => [
                        $floor->level.' '.$floor->name => $floor->rooms->map(fn (Room $room): string => $room->name.($room->is_technical ? ' [T]' : ''))->all(),
                    ])
                    ->all(),
            ])
            ->all();

        $this->assertSame([
            'Bâtiment principal (A)' => [
                '0 Rez-de-chaussée' => ['Accueil', 'Local technique [T]'],
                // Each floor has a technical room of its own.
                '1 1er étage' => ['Bureaux', 'Placard technique [T]', 'Salle de réunion'],
                '2 2e étage' => ['Bureaux', 'Direction', 'Placard technique [T]'],
            ],
        ], $places('Siège'));

        $this->assertSame([
            'Halle de production (P)' => [
                '0 Rez-de-chaussée' => ['Hall de production', 'Local technique [T]', "Quai d'expédition"],
                '1 Mezzanine' => ['Bureaux'],
            ],
        ], $places('Atelier'));

        // A technical room on the ground floor of each site, and one on each
        // floor of the head office.
        $this->assertSame(4, Room::query()->where('is_technical', true)->count());
    }

    public function test_the_devices_are_kept_in_rooms_of_their_site_and_have_the_addresses_that_bear_their_name(): void
    {
        Artisan::call('torsade:demo');

        $devices = Device::query()->with(['site', 'room.floor.building', 'ipAddresses'])->get();

        // Firewalls, switches, servers, storage, access points, printers.
        $types = $devices->countBy(fn (Device $device): string => $device->type->value)->all();

        ksort($types);

        $this->assertSame(['access_point' => 7, 'firewall' => 2, 'nas' => 2, 'other' => 2, 'printer' => 4, 'server' => 8, 'switch' => 9], $types);
        $this->assertSame(['Atelier' => 11, 'Siège' => 23], $devices->countBy(fn (Device $device): string => $device->site->name)->sortKeys()->all());

        foreach ($devices as $device) {
            // A room is always one of the site of the device.
            if ($device->room !== null) {
                $this->assertSame($device->site_id, $device->room->siteId(), $device->name);
            }

            foreach ($device->ipAddresses as $address) {
                // An interface bears the name of its device, alone or
                // followed by what tells it from the others.
                $this->assertStringStartsWith($device->name, (string) $address->hostname, $device->name);
            }
        }

        // One device has no room, one is only planned, and the switches are
        // not all in a technical room.
        $this->assertSame(['onduleur-atelier'], $devices->whereNull('room_id')->pluck('name')->all());
        $this->assertSame(['nas-02'], $devices->where('status', Status::Planned)->pluck('name')->values()->all());
        $this->assertSame(
            ['sw-quai', 'sw-salle-reunion'],
            $devices->filter(fn (Device $device): bool => $device->type === DeviceType::Switch && $device->room?->is_technical === false)->pluck('name')->sort()->values()->all(),
        );

        // The firewall of the head office has an interface in each network
        // it routes.
        $firewall = $devices->firstWhere('name', 'fw-siege');

        $this->assertSame('Bâtiment principal › Rez-de-chaussée › Local technique', $firewall?->room?->path());
        $this->assertSame(
            ['10.10.99.254' => 'fw-siege', '10.10.255.1' => 'fw-siege-lo0', '172.16.255.0' => 'fw-siege-vpn', '203.0.113.10' => 'fw-siege-wan'],
            $firewall?->ipAddresses->sortBy(fn (IpAddress $address): int => Ipv4::toInt($address->address))
                ->mapWithKeys(fn (IpAddress $address): array => [$address->address => $address->hostname])->all(),
        );

        // Every device has its address, but the one declared without it and
        // the switch of the meeting room, which has none.
        $this->assertSame(['sw-etage-2', 'sw-salle-reunion'], $devices->filter(fn (Device $device): bool => $device->ipAddresses->isEmpty())->pluck('name')->sort()->values()->all());
        $this->assertSame(37, IpAddress::query()->whereNotNull('device_id')->count());

        // Nothing here names a real manufacturer.
        $this->assertSame(['Trois Brins Matériel'], $devices->pluck('manufacturer')->filter()->unique()->values()->all());
    }

    public function test_host_names_are_left_without_a_device_to_try_the_creation_from_host_names(): void
    {
        Artisan::call('torsade:demo');

        $editor = User::factory()->editor()->create();
        $office = Site::query()->where('name', 'Siège')->sole();
        $workshop = Site::query()->where('name', 'Atelier')->sole();

        $candidates = fn (Site $site): array => (array) $this->actingAs($editor)
            ->get(route('sites.hostname-devices.create', $site))
            ->assertOk()
            ->inertiaProps('candidates');

        $atOffice = array_column($candidates($office), null, 'hostname');
        $atWorkshop = array_column($candidates($workshop), null, 'hostname');

        // Far more than two names on each site.
        $this->assertGreaterThan(40, count($atOffice));
        $this->assertGreaterThan(20, count($atWorkshop));

        // A server and a recorder to create, each with the type its address
        // has.
        $this->assertSame(['server', null], [$atOffice['srv-messagerie-01']['type'], $atOffice['srv-messagerie-01']['device']]);
        $this->assertSame(['camera', null], [$atOffice['cam-entree']['type'], $atOffice['cam-entree']['device']]);
        $this->assertSame('other', $atWorkshop['automate-ligne-1']['type']);
        // What is only planned is proposed as such, the rest in service.
        $this->assertSame('planned', $atWorkshop['automate-ligne-3']['status']);
        $this->assertSame('active', $atWorkshop['automate-ligne-1']['status']);
        $this->assertSame('retired', $atOffice['srv-intranet-01']['status']);

        // The switch declared without its address is waiting for it.
        $this->assertSame('sw-etage-2', $atOffice['sw-etage-2']['device']['name']);
        $this->assertSame(['10.10.99.14'], $atOffice['sw-etage-2']['addresses']);

        // What already belongs to a device is not proposed again.
        $this->assertArrayNotHasKey('fw-siege', $atOffice);
        $this->assertArrayNotHasKey('srv-sauvegarde-01', $atWorkshop);

        // And it works: everything of the workshop becomes devices.
        $this->actingAs($editor)
            ->post(route('sites.hostname-devices.store', $workshop), [
                'lines' => array_values(array_map(
                    fn (array $candidate): array => ['hostname' => $candidate['hostname'], 'type' => $candidate['type'], 'status' => $candidate['status']],
                    $atWorkshop,
                )),
            ])
            ->assertRedirect(route('sites.show', $workshop))
            ->assertSessionHasNoErrors();

        $this->assertSame(11 + count($atWorkshop), Device::query()->where('site_id', $workshop->id)->count());
        $this->assertSame([], $candidates($workshop));
    }

    public function test_the_announced_deviation_of_a_device_of_another_site_is_there_and_explained(): void
    {
        Artisan::call('torsade:demo');

        $deviations = app(Deviations::class);
        $found = [];

        foreach (IpAddress::query()->whereNotNull('device_id')->with(['subnet.site', 'device.site'])->get() as $address) {
            foreach ($deviations->ofAddress($address) as $deviation) {
                $found[$address->address] = [$address->device?->name, $deviation['kind'], $deviation['reason']];

                // The address says why.
                $this->assertStringContainsString('écart « équipement d\'un autre site »', (string) $address->description);
            }
        }

        // One, and only one.
        $this->assertSame(
            ['10.20.60.50' => ['srv-sauvegarde-01', 'address_on_device_of_another_site', 'Sous-réseau du site Atelier, équipement du site Siège']],
            $found,
        );

        // The interfaces of the firewalls on the tunnel, which belongs to no
        // site, differ from nothing.
        $tunnel = IpAddress::query()->where('hostname', 'fw-siege-vpn')->with(['subnet.site', 'device.site'])->sole();

        $this->assertNull($tunnel->subnet->site);
        $this->assertSame('fw-siege', $tunnel->device?->name);
        $this->assertSame([], $deviations->ofAddress($tunnel));

        // It shows on the page of the device and in the table of the subnet.
        $reader = User::factory()->reader()->create();
        $server = Device::query()->where('name', 'srv-sauvegarde-01')->sole();

        $this->actingAs($reader)->get(route('devices.show', $server))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('device.site.name', 'Siège')
                ->has('addresses', 2)
                ->where('addresses.0.deviations', [])
                ->where('addresses.1.address', '10.20.60.50')
                ->where('addresses.1.deviations.0.reason', 'Sous-réseau du site Atelier, équipement du site Siège'));
    }

    public function test_a_single_subnet_without_site_is_enough_to_refuse(): void
    {
        Subnet::factory()->cidr('192.168.100.0/24')->create();

        $this->assertNotSame(0, Artisan::call('torsade:demo'));
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('subnets', 1);
    }

    public function test_everything_is_logged_with_the_console_as_author(): void
    {
        Artisan::call('torsade:demo');

        $count = fn (ActivityEvent $event): int => Activity::query()->where('event', $event->value)->count();

        // One entry per object created.
        $this->assertSame(3, $count(ActivityEvent::SiteCreated));
        $this->assertSame(6, $count(ActivityEvent::CatalogVlanCreated));
        $this->assertSame(4, $count(ActivityEvent::AddressBlockCreated));
        $this->assertSame(1, $count(ActivityEvent::AddressingRuleCreated));
        $this->assertSame(13, $count(ActivityEvent::VlanCreated));
        $this->assertSame(17, $count(ActivityEvent::SubnetCreated));
        $this->assertSame(8, $count(ActivityEvent::IpRangeCreated));
        $this->assertSame(107, $count(ActivityEvent::IpAddressCreated));
        $this->assertSame(2, $count(ActivityEvent::BuildingCreated));
        $this->assertSame(5, $count(ActivityEvent::FloorCreated));
        $this->assertSame(12, $count(ActivityEvent::RoomCreated));
        $this->assertSame(34, $count(ActivityEvent::DeviceCreated));
        $this->assertSame(5, $count(ActivityEvent::DeviceTemplateCreated));
        // One entry per member, which tells the ports it got from its
        // template, or the port of an access point: none per port.
        $this->assertSame(15, $count(ActivityEvent::DeviceMemberCreated));
        $this->assertSame(0, $count(ActivityEvent::PortCreated));
        $this->assertSame(
            Port::query()->count(),
            Activity::query()->where('event', ActivityEvent::DeviceMemberCreated->value)->get()
                ->sum(fn (Activity $entry): int => (int) (($entry->attribute_changes?->toArray() ?? [])['attributes']['ports_count'] ?? 0)),
        );
        $this->assertSame(3, $count(ActivityEvent::PortAggregateCreated));
        // One entry per port each time it changed: 96 got a mode, 6 joined
        // an aggregate, 46 were described.
        $this->assertSame(148, $count(ActivityEvent::PortUpdated));
        // One entry per link, the two of the aggregated link included.
        $this->assertSame(15, $count(ActivityEvent::LinkCreated));
        $this->assertSame(Link::query()->count(), $count(ActivityEvent::LinkCreated));
        // One entry per address attached to its device.
        $this->assertSame(37, $count(ActivityEvent::IpAddressAttached));
        $this->assertSame(IpAddress::query()->whereNotNull('device_id')->count(), $count(ActivityEvent::IpAddressAttached));
        $this->assertSame(0, $count(ActivityEvent::IpAddressUpdated));

        foreach (Activity::query()->get() as $entry) {
            $this->assertNull($entry->causer_id);
            $this->assertSame('console', $entry->getProperty('source'));
        }

        $command = Activity::query()->where('event', ActivityEvent::CommandExecuted->value)->sole();

        $this->assertSame('torsade:demo', $command->getProperty('command'));
        $this->assertSame('success', $command->getProperty('outcome'));
    }

    public function test_the_switches_and_the_firewall_get_their_members_and_their_ports_from_the_generic_templates(): void
    {
        Artisan::call('torsade:demo');

        $this->assertSame(
            ['Pare-feu 8 ports', 'Serveur 2 ports', 'Switch 24 ports PoE+ et 4 SFP+', 'Switch 48 ports PoE+ et 4 SFP+', 'Switch 8 ports'],
            DeviceTemplate::query()->orderBy('name')->pluck('name')->all(),
        );

        // The devices are those the demonstration already had: they get
        // their members, and none is created again.
        $this->assertSame(34, Device::query()->count());
        $this->assertSame(
            ['ap-atelier-1', 'ap-atelier-2', 'ap-etage-1', 'ap-etage-2', 'ap-salle-reunion', 'fw-siege', 'sw-atelier-01', 'sw-coeur-01', 'sw-coeur-02', 'sw-etage-1', 'sw-etage-2', 'sw-quai', 'sw-salle-reunion', 'sw-serveurs'],
            Device::query()->has('members')->orderBy('name')->pluck('name')->all(),
        );

        // What each of them is made of: its members, by number, with the
        // role, the template and the first and last ports of each.
        $made = fn (string $name): array => Device::query()->where('name', $name)->sole()->members()->with(['template', 'ports'])->get()
            ->map(fn (DeviceMember $member): array => [
                $member->position, $member->role->value, $member->template?->name, $member->ports->count(), $member->ports->first()?->name, $member->ports->last()?->name,
            ])
            ->all();

        // At the head office, a stack of two members: a primary and a
        // secondary, whose ports bear the number of their member.
        $this->assertSame([
            [1, 'primary', 'Switch 24 ports PoE+ et 4 SFP+', 28, '1/0/1', '1/1/4'],
            [2, 'secondary', 'Switch 24 ports PoE+ et 4 SFP+', 28, '2/0/1', '2/1/4'],
        ], $made('sw-serveurs'));

        // Two access switches of one member each.
        $this->assertSame([[1, 'primary', 'Switch 24 ports PoE+ et 4 SFP+', 28, '1/0/1', '1/1/4']], $made('sw-etage-1'));
        $this->assertSame([[1, 'primary', 'Switch 24 ports PoE+ et 4 SFP+', 28, '1/0/1', '1/1/4']], $made('sw-etage-2'));

        // The two core switches, of one member of 48 ports and 4 fibre
        // ports each.
        $this->assertSame([[1, 'primary', 'Switch 48 ports PoE+ et 4 SFP+', 52, '1/0/1', '1/1/4']], $made('sw-coeur-01'));
        $this->assertSame([[1, 'primary', 'Switch 48 ports PoE+ et 4 SFP+', 52, '1/0/1', '1/1/4']], $made('sw-coeur-02'));

        // The firewall with its ports, and two switches of the workshop.
        $this->assertSame([[1, 'primary', 'Pare-feu 8 ports', 8, 'port1', 'port8']], $made('fw-siege'));
        $this->assertSame([[1, 'primary', 'Switch 24 ports PoE+ et 4 SFP+', 28, '1/0/1', '1/1/4']], $made('sw-atelier-01'));
        $this->assertSame([[1, 'primary', 'Switch 8 ports', 8, '1', '8']], $made('sw-quai'));
        // The switch of the meeting room, and an access point: its uplink
        // only, added as a series of one port.
        $this->assertSame([[1, 'primary', 'Switch 8 ports', 8, '1', '8']], $made('sw-salle-reunion'));
        $this->assertSame([[1, 'primary', null, 1, 'eth0', 'eth0']], $made('ap-etage-1'));

        $this->assertSame(15, DeviceMember::query()->count());
        $this->assertSame(273, Port::query()->count());

        // Each member has its serial number, an invented one; a device
        // made of members has none of its own.
        $this->assertSame(0, DeviceMember::query()->whereNull('serial_number')->count());
        $this->assertSame(15, DeviceMember::query()->where('serial_number', 'like', 'DEMO-%')->distinct()->count('serial_number'));
        $this->assertSame(0, Device::query()->has('members')->whereNotNull('serial_number')->count());
        $this->assertGreaterThan(10, Device::query()->doesntHave('members')->whereNotNull('serial_number')->count());
    }

    public function test_the_ports_are_configured_in_access_and_in_trunk_mode_with_vlans_of_their_site_but_one(): void
    {
        Artisan::call('torsade:demo');

        $summaries = fn (string $name): array => Device::query()->where('name', $name)->sole()->ports()->with('portVlans.vlan')->orderBy('position')->get()
            ->mapWithKeys(fn (Port $port): array => [$port->name => $port->vlanSummary()])
            ->all();

        $floor = $summaries('sw-etage-1');

        // A range of access ports with a voice VLAN: a telephone on each
        // desk, a workstation behind it.
        foreach (range(1, 16) as $number) {
            $this->assertSame('Accès 10, voix 30', $floor["1/0/{$number}"]);
        }

        // Access ports without a voice VLAN, trunks for the access points
        // and for the uplink, and ports that are not configured.
        $this->assertSame('Accès 10', $floor['1/0/17']);
        $this->assertSame('Trunk, natif 99 : 10, 40', $floor['1/0/21']);
        $this->assertSame('Trunk, natif 99 : 10, 30, 40', $floor['1/1/1']);
        $this->assertNull($floor['1/0/23']);
        $this->assertNull($floor['1/1/4']);

        // The firewall carries every network it routes, tagged, towards
        // each of the two core switches.
        $this->assertSame('Trunk : 10, 20, 30, 40, 50, 99', $summaries('fw-siege')['port2']);
        $this->assertSame('Trunk : 10, 20, 30, 40, 50, 99', $summaries('fw-siege')['port4']);
        $this->assertSame('Trunk : 10, 20, 30, 40, 50, 99', $summaries('sw-coeur-01')['1/0/1']);
        $this->assertSame('Trunk, natif 99 : 10, 30, 40', $summaries('sw-coeur-01')['1/1/3']);
        $this->assertNull($summaries('sw-coeur-01')['1/0/2']);
        // The workshop has its own VLANs.
        $this->assertSame('Accès 60', $summaries('sw-quai')['1']);
        $this->assertSame('Trunk, natif 99 : 10, 60, 150', $summaries('sw-quai')['8']);

        $this->assertSame(69, Port::query()->where('mode', 'access')->count());
        $this->assertSame(27, Port::query()->where('mode', 'trunk')->count());
        $this->assertSame(34, Port::query()->where('mode', 'access')->whereHas('portVlans', fn ($query) => $query->where('tagged', true))->count());

        // Every VLAN carried belongs to the site of the device, but the
        // VLAN 60 of the workshop at the end of the fibre under study, on a
        // core switch of the head office.
        $lines = PortVlan::query()->with(['vlan.site', 'port.device', 'aggregate.device'])->get();

        $this->assertSame(220, $lines->count());
        $this->assertSame(
            ['sw-coeur-02 › 1/1/3 : Atelier 60'],
            $lines
                ->filter(fn (PortVlan $line): bool => ($line->port ?? $line->aggregate)?->device->site_id !== $line->vlan->site_id)
                ->map(fn (PortVlan $line): string => $line->port?->device->name.' › '.$line->port?->name.' : '.$line->vlan->site->name.' '.$line->vlan->vid)
                ->values()
                ->all(),
        );
        $this->assertSame('Trunk : 10 · ATE 60', $summaries('sw-coeur-02')['1/1/3']);
        $this->assertSame('Trunk : 10, 60', $summaries('sw-atelier-01')['1/1/1']);

        // The retired VLAN of the head office is still the native VLAN of
        // one port of a core switch: its link shows it as a deviation.
        $this->assertSame(
            ['sw-coeur-02 › 1/1/4'],
            PortVlan::query()->whereHas('vlan', fn ($query) => $query->where('status', Status::Retired))->with('port.device')->get()
                ->map(fn (PortVlan $line): string => $line->port?->device->name.' › '.$line->port?->name)
                ->all(),
        );
    }

    public function test_a_few_ports_are_disabled_and_say_why_in_their_description(): void
    {
        Artisan::call('torsade:demo');

        $disabled = Port::query()->where('enabled', false)->with('device')->get();

        $this->assertCount(8, $disabled);
        $this->assertSame(
            ['fw-siege', 'sw-etage-1', 'sw-etage-2', 'sw-quai', 'sw-serveurs'],
            $disabled->pluck('device.name')->unique()->sort()->values()->all(),
        );

        foreach ($disabled as $port) {
            $this->assertStringStartsWith('Désactivé : ', (string) $port->description);
            // A port that is shut down carries nothing.
            $this->assertNull($port->mode);
        }

        // The ports that are in use say what is plugged into them.
        $this->assertSame('hyp-01', Port::query()->where('name', '1/0/9')->whereHas('device', fn ($query) => $query->where('name', 'sw-serveurs'))->sole()->description);
        $this->assertSame(38, Port::query()->where('enabled', true)->whereNotNull('description')->count());
    }

    public function test_an_lacp_aggregate_spans_the_two_members_of_the_stack(): void
    {
        Artisan::call('torsade:demo');

        $stack = Device::query()->where('name', 'sw-serveurs')->sole();

        $this->assertSame(['Po1', 'Po2'], $stack->aggregates()->pluck('name')->all());
        // And the aggregate of the core switch its uplink faces.
        $this->assertSame(3, PortAggregate::query()->count());

        $uplink = $stack->aggregates()->where('name', 'Po1')->with(['device', 'portVlans.vlan', 'ports.member'])->sole();

        $this->assertSame('sw-serveurs', $uplink->device->name);
        $this->assertSame('lacp', $uplink->protocol->value);
        // One port on each member.
        $this->assertSame(
            [[1, '1/1/1'], [2, '2/1/1']],
            $uplink->ports->map(fn (Port $port): array => [$port->member->position, $port->name])->sortBy(0)->values()->all(),
        );
        // The mode and the VLANs are those of the aggregate: its ports
        // have none of their own.
        $this->assertSame('Trunk, natif 99 : 10, 20, 30, 40, 50', $uplink->vlanSummary());
        $this->assertSame(0, Port::query()->whereNotNull('port_aggregate_id')->whereNotNull('mode')->count());
        $this->assertSame(0, PortVlan::query()->whereIn('port_id', Port::query()->whereNotNull('port_aggregate_id')->select('id'))->count());

        $storage = $stack->aggregates()->where('name', 'Po2')->with('portVlans.vlan')->sole();

        $this->assertSame('Accès 20', $storage->vlanSummary());
        $this->assertSame(['1/0/13', '2/0/13'], $storage->ports()->orderBy('name')->pluck('name')->all());
    }

    public function test_a_device_template_alone_is_enough_to_refuse(): void
    {
        DeviceTemplate::factory()->create();

        $this->assertSame(1, Artisan::call('torsade:demo'));

        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('device_templates', 1);
    }

    public function test_the_members_the_ports_and_the_aggregates_can_be_browsed(): void
    {
        Artisan::call('torsade:demo');

        $reader = User::factory()->reader()->create();
        $stack = Device::query()->where('name', 'sw-serveurs')->sole();

        $this->actingAs($reader)->get(route('device-templates.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('templates', 5)
                ->where('missingGenerics', 0)
                // The two members of the stack, the two access switches
                // and a switch of the workshop.
                ->where('templates.2.name', 'Switch 24 ports PoE+ et 4 SFP+')
                ->where('templates.2.members_count', 5)
                // The two core switches.
                ->where('templates.3.name', 'Switch 48 ports PoE+ et 4 SFP+')
                ->where('templates.3.members_count', 2));

        $this->actingAs($reader)->get(route('devices.show', $stack))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('device.serial_number', null)
                ->has('members', 2)
                ->where('members.0.role_label', 'Principal')
                ->where('members.1.role_label', 'Secondaire')
                ->has('ports', 56)
                ->has('aggregates', 2)
                ->where('aggregates.0.summary', 'Trunk, natif 99 : 10, 20, 30, 40, 50')
                // The VLANs of the head office first, then those of the
                // workshop, to choose among.
                ->has('vlans', 2)
                ->where('vlans.0.site.name', 'Siège')
                ->has('vlans.0.vlans', 7)
                ->where('vlans.1.site.name', 'Atelier'));

        // A device without a member has no port.
        $this->actingAs($reader)->get(route('devices.show', Device::query()->where('name', 'sw-atelier-02')->sole()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('members', [])->where('ports', [])->where('aggregates', [])->where('links', []));

        // The page of a VLAN lists the ports that carry it, device by
        // device: here, on the six devices of the head office that have
        // ports.
        $offices = Vlan::query()->where('vid', 10)->whereHas('site', fn ($query) => $query->where('name', 'Siège'))->sole();

        $this->actingAs($reader)->get(route('vlans.show', $offices))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('carriers', 6)
                ->where('carriers.0.device.name', 'fw-siege')
                ->where('carriers.0.carriers', [
                    ['kind' => 'port', 'name' => 'port2', 'member' => 1, 'role' => 'tagged', 'role_label' => 'Étiqueté'],
                    ['kind' => 'port', 'name' => 'port4', 'member' => 1, 'role' => 'tagged', 'role_label' => 'Étiqueté'],
                ])
                // Three trunks of the core switch, and its aggregate.
                ->where('carriers.1.device.name', 'sw-coeur-01')
                ->has('carriers.1.carriers', 4)
                ->where('carriers.2.device.name', 'sw-coeur-02')
                // And the fibre under study towards the workshop.
                ->has('carriers.2.carriers', 5)
                ->where('carriers.3.device.name', 'sw-etage-1')
                ->has('carriers.3.carriers', 24)
                ->where('carriers.3.carriers.0', ['kind' => 'port', 'name' => '1/0/1', 'member' => 1, 'role' => 'untagged', 'role_label' => 'Non étiqueté'])
                ->where('carriers.5.device.name', 'sw-serveurs')
                // Eight trunks of the hypervisors, and the aggregate of
                // the uplink.
                ->has('carriers.5.carriers', 9)
                ->where('carriers.5.carriers.8', ['kind' => 'aggregate', 'name' => 'Po1', 'member' => null, 'role' => 'tagged', 'role_label' => 'Étiqueté']));

        // The voice VLAN, on the access ports that have a telephone.
        $voice = Vlan::query()->where('vid', 30)->whereHas('site', fn ($query) => $query->where('name', 'Siège'))->sole();

        $this->actingAs($reader)->get(route('vlans.show', $voice))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('carriers.3.device.name', 'sw-etage-1')
                ->where('carriers.3.carriers.0.role', 'voice')
                ->where('carriers.3.carriers.0.role_label', 'Voix'));
    }

    public function test_the_devices_are_linked_to_each_other_the_stack_by_an_aggregated_link(): void
    {
        $this->assertSame(0, Artisan::call('torsade:demo'));

        $links = Link::query()->with(LinkDeviations::RELATIONS)->orderBy('id')->get();

        // What is plugged into what, with the medium and the status of
        // each link.
        $this->assertSame(
            [
                // The firewall to each of the two core switches, and the
                // two core switches to each other.
                ['fw-siege › port2', 'sw-coeur-01 › 1/0/1', 'copper', 'active'],
                ['fw-siege › port4', 'sw-coeur-02 › 1/0/1', 'copper', 'active'],
                ['sw-coeur-01 › 1/1/4', 'sw-coeur-02 › 1/1/4', 'direct_attach', 'active'],
                // The stack, from each of its two members.
                ['sw-serveurs › 1/1/1', 'sw-coeur-01 › 1/1/1', 'direct_attach', 'active'],
                ['sw-serveurs › 2/1/1', 'sw-coeur-01 › 1/1/2', 'direct_attach', 'active'],
                // The uplinks of the floors, one of them planned.
                ['sw-etage-1 › 1/1/1', 'sw-coeur-01 › 1/1/3', 'fiber_multimode', 'active'],
                ['sw-etage-2 › 1/1/1', 'sw-coeur-02 › 1/1/1', 'fiber_multimode', 'active'],
                ['sw-etage-1 › 1/1/2', 'sw-coeur-02 › 1/1/2', 'fiber_multimode', 'planned'],
                // The access points and the switch of the meeting room,
                // through their jacks.
                ['sw-etage-1 › 1/0/21', 'ap-etage-1 › eth0', 'copper', 'active'],
                ['sw-etage-1 › 1/0/18', 'sw-salle-reunion › 8', 'copper', 'active'],
                ['sw-etage-1 › 1/0/22', 'ap-salle-reunion › eth0', 'copper', 'active'],
                ['sw-etage-2 › 1/0/21', 'ap-etage-2 › eth0', 'copper', 'active'],
                // The uplink of the quay, at the workshop, and the former
                // link of one of its access points.
                ['sw-quai › 8', 'sw-atelier-01 › 1/0/24', 'copper', 'active'],
                ['sw-atelier-01 › 1/0/10', 'ap-atelier-2 › eth0', 'copper', 'retired'],
                // A fibre between the two sites, under study.
                ['sw-coeur-02 › 1/1/3', 'sw-atelier-01 › 1/1/1', 'fiber_singlemode', 'planned'],
            ],
            $links->map(fn (Link $link): array => [
                Links::named($link->portAt(1)), Links::named($link->portAt(2)), $link->medium?->value, $link->status->value,
            ])->all(),
        );

        // The two links of the stack join an aggregate of the stack, on
        // its two members, and an aggregate of the core switch.
        $aggregated = $links->filter(fn (Link $link): bool => $link->portAt(1)->aggregate !== null);

        $this->assertSame(
            [['sw-serveurs', 'Po1', 1, 'sw-coeur-01', 'Po1'], ['sw-serveurs', 'Po1', 2, 'sw-coeur-01', 'Po1']],
            $aggregated->map(fn (Link $link): array => [
                $link->portAt(1)->device->name, $link->portAt(1)->aggregate?->name, $link->portAt(1)->member->position,
                $link->portAt(2)->device->name, $link->portAt(2)->aggregate?->name,
            ])->values()->all(),
        );

        // Each link has its port at both ends described as leading to the
        // other device.
        foreach ($links as $link) {
            $this->assertStringContainsString($link->portAt(2)->device->name, (string) ($link->portAt(1)->aggregate?->description ?? $link->portAt(1)->description));
            $this->assertStringContainsString($link->portAt(1)->device->name, (string) ($link->portAt(2)->aggregate?->description ?? $link->portAt(2)->description));
        }

        // A link joins two sites only once: the fibre under study. The two
        // sites are otherwise routed, by their firewalls.
        $this->assertSame(
            ['sw-coeur-02 › 1/1/3'],
            $links
                ->filter(fn (Link $link): bool => $link->portAt(1)->device->site_id !== $link->portAt(2)->device->site_id)
                ->map(fn (Link $link): string => Links::named($link->portAt(1)))
                ->values()
                ->all(),
        );
    }

    public function test_six_links_differ_at_their_ends_or_from_their_cabling_on_purpose_and_say_so_in_their_description(): void
    {
        Artisan::call('torsade:demo');

        $links = Link::query()->with(LinkDeviations::RELATIONS)->orderBy('id')->get();
        $deviations = app(LinkDeviations::class);
        $cabling = app(CabledPaths::class)->of();

        $differing = array_filter($deviations->ofLinks($links, cabling: $cabling));

        // Six deviations of six different kinds: a native VLAN that is
        // not the same at both ends, a VLAN that one end does not tag, the
        // VLAN 10 of each site at the ends of the fibre under study; and
        // what the cabling tells: a port linked to an access point whose
        // cord is not declared, an access point plugged into a jack patched
        // to another port than its link says, the cords of a retired link.
        // The VLAN 60 of the workshop, carried at both ends, is no
        // deviation.
        $this->assertSame(
            [
                'sw-coeur-01 › 1/1/4' => [['kind' => 'link_native_vlans', 'reason' => "Natif 99 d'un côté, 5 de l'autre"]],
                'sw-etage-2 › 1/1/1' => [['kind' => 'link_tagged_vlans', 'reason' => 'VLAN 40 seulement sur sw-etage-2']],
                'sw-etage-1 › 1/0/22' => [[
                    'kind' => 'linked_without_plug',
                    'reason' => "sw-etage-1 1/0/22 est relié à ap-salle-reunion eth0 et brassé vers B.14, où rien n'est branché",
                    'action' => [
                        'type' => 'plug',
                        'jack' => Jack::query()->where('label', 'B.14')->value('id'),
                        'port' => Port::query()->where('name', 'eth0')->whereHas('device', fn ($query) => $query->where('name', 'ap-salle-reunion'))->value('id'),
                    ],
                ]],
                'sw-etage-2 › 1/0/21' => [['kind' => 'link_contradicted_by_cabling', 'reason' => 'Le câblage de ap-etage-2 eth0 mène à sw-etage-2 1/0/16 ; la liaison, à sw-etage-2 1/0/21']],
                'sw-atelier-01 › 1/0/10' => [['kind' => 'cabling_of_retired_link', 'reason' => 'sw-atelier-01 1/0/10 et ap-atelier-2 eth0 sont encore câblés par PB-A 10 › H.04 ; leur liaison est retirée']],
                'sw-coeur-02 › 1/1/3' => [['kind' => 'link_merges_vlans', 'reason' => "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux"]],
            ],
            collect($differing)->mapWithKeys(fn (array $found, int $id): array => [Links::named($links->firstOrFail('id', $id)->portAt(1)) => $found])->all(),
        );

        // The cabling confirms the links of an access point and of the
        // switch of the meeting room.
        $this->assertSame(
            ['sw-etage-1 › 1/0/18' => 'PB-B 15 › B.15', 'sw-etage-1 › 1/0/21' => 'PB-B 13 › B.13'],
            collect($cabling->paths())
                ->mapWithKeys(fn (array $path, int $id): array => [Links::named($links->firstOrFail('id', $id)->portAt(1)) => $path['panel_port']['name'].' › '.$path['jack']['label']])
                ->sortKeys()
                ->all(),
        );

        // Each of them is explained in the description of its link, and no
        // other link claims to be a deviation.
        foreach ($links as $link) {
            $this->assertSame(
                isset($differing[$link->id]),
                str_contains((string) $link->description, 'écart conservé pour la démonstration'),
                Links::named($link->portAt(1)),
            );
        }

        $this->assertStringContainsString('VLAN 40', (string) $links->firstOrFail(fn (Link $link): bool => $link->portAt(1)->device->name === 'sw-etage-2')->description);
        $this->assertStringContainsString('VLAN 5', (string) $links->firstOrFail(fn (Link $link): bool => Links::named($link->portAt(1)) === 'sw-coeur-01 › 1/1/4')->description);

        $fibre = $links->firstOrFail(fn (Link $link): bool => Links::named($link->portAt(1)) === 'sw-coeur-02 › 1/1/3');

        $this->assertStringStartsWith('Fibre directe siège – atelier, à l\'étude.', (string) $fibre->description);
        $this->assertStringContainsString('VLAN 60', (string) $fibre->description);
        $this->assertStringContainsString('VLAN 10', (string) $fibre->description);

        // No aggregate has anything to tell: the one of the stack faces
        // one aggregate of one device.
        $this->assertSame([], array_filter($deviations->ofAggregates($links)));

        // Two links are planned: the second uplink of the first floor,
        // which says what is left to do, and the fibre under study.
        $planned = $links->filter(fn (Link $link): bool => $link->status === Status::Planned)->values();

        $this->assertCount(2, $planned);
        $this->assertSame('Second lien montant du 1er étage : fibre à tirer.', $planned[0]->description);
        $this->assertSame([], $deviations->ofLinks($links, cabling: $cabling)[$planned[0]->id]);
        $this->assertSame($fibre->id, $planned[1]->id);
    }

    public function test_the_links_can_be_browsed(): void
    {
        Artisan::call('torsade:demo');

        $reader = User::factory()->reader()->create();

        // The list of every link: fifteen links, the two of the stack as one
        // aggregated link.
        $this->actingAs($reader)->get(route('links.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 15)
                ->has('links', 14)
                ->where('links.13.aggregated', true)
                ->has('links.13.links', 2)
                ->where('links.13.links.0.near.aggregate.name', 'Po1')
                ->where('links.13.links.0.far.device.name', 'sw-coeur-01')
                // The path of a link the cabling confirms.
                ->where('links.5.links.0.near.port.name', '1/0/18')
                ->where('links.5.links.0.path.panel_port.name', 'PB-B 15')
                ->where('links.5.links.0.path.jack.label', 'B.15'));

        // The six deviations, alone on demand.
        $this->actingAs($reader)->get(route('links.index', ['deviations' => 1]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('links', 6)
                ->where('links.0.deviations.0.kind', 'cabling_of_retired_link')
                ->where('links.1.deviations.0.reason', "Natif 99 d'un côté, 5 de l'autre")
                ->where('links.2.deviations.0.reason', "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux")
                ->where('links.3.deviations.0.kind', 'linked_without_plug')
                ->where('links.4.deviations.0.kind', 'link_contradicted_by_cabling')
                ->where('links.5.deviations.0.reason', 'VLAN 40 seulement sur sw-etage-2'));

        $this->actingAs($reader)->get(route('links.index', ['status' => 'planned']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('links', 2)
                ->where('links.0.links.0.near.device.name', 'sw-coeur-02')
                ->where('links.1.links.0.near.device.name', 'sw-etage-1'));

        // The workshop has three links, the head office the twelve that are
        // not only at the workshop: the fibre between the two sites is on
        // both.
        foreach (['Atelier' => 3, 'Siège' => 12, 'Dépôt' => 0] as $name => $count) {
            $this->actingAs($reader)->get(route('links.index', ['site' => Site::query()->where('name', $name)->sole()->id]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('links', $count));
        }

        // The page of the stack: its aggregated link, and what each of its
        // two ports faces.
        $this->actingAs($reader)->get(route('devices.show', Device::query()->where('name', 'sw-serveurs')->sole()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('links', 1)
                ->where('links.0.aggregated', true)
                ->has('links.0.links', 2)
                ->where('linkDeviations', []));

        // The page of a core switch tells the deviation of each of its
        // links that has one.
        $this->actingAs($reader)->get(route('devices.show', Device::query()->where('name', 'sw-coeur-02')->sole()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('links', 5)
                ->has('linkDeviations', 3)
                ->where('linkDeviations.0.label', '1/1/1 ↔ sw-etage-2 › 1/1/1')
                ->where('linkDeviations.0.deviations.0.reason', 'VLAN 40 seulement sur sw-etage-2')
                ->where('linkDeviations.1.label', '1/1/3 ↔ sw-atelier-01 › 1/1/1')
                ->where('linkDeviations.1.deviations', [['kind' => 'link_merges_vlans', 'reason' => "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux"]])
                ->where('linkDeviations.2.label', '1/1/4 ↔ sw-coeur-01 › 1/1/4')
                ->where('linkDeviations.2.deviations.0.reason', "Natif 5 d'un côté, 99 de l'autre"));

        // A switch of the head office carries the VLAN 60 of the workshop,
        // and merges no VLAN: its own VLAN 10 is not on the same switch as
        // the one of the workshop.
        $workshop = Vlan::query()->where('vid', 60)->whereHas('site', fn ($query) => $query->where('name', 'Atelier'))->sole();

        $this->actingAs($reader)->get(route('vlans.show', $workshop))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('carriers.0.device.name', 'sw-atelier-01')
                ->where('carriers.1.device.name', 'sw-coeur-02')
                ->where('carriers.1.carriers', [['kind' => 'port', 'name' => '1/1/3', 'member' => 1, 'role' => 'tagged', 'role_label' => 'Étiqueté']]));
    }

    public function test_the_physical_diagram_of_the_head_office_reads_from_its_firewall_down(): void
    {
        Artisan::call('torsade:demo');

        $diagram = app(PhysicalDiagram::class)->of(Site::query()->where('name', 'Siège')->sole());
        $devices = collect($diagram['devices'])->keyBy('name');
        $keys = $devices->map(fn (array $device): string => $device['key']);

        // The firewall is at the top: every link of it leaves from it.
        $this->assertTrue($devices->has('fw-siege'));
        $this->assertNotContains($keys['fw-siege'], array_column($diagram['links'], 'target'));
        $this->assertContains($keys['fw-siege'], array_column($diagram['links'], 'source'));

        // The two core switches and the switches of the floors.
        foreach (['sw-coeur-01', 'sw-coeur-02', 'sw-etage-1', 'sw-etage-2'] as $name) {
            $this->assertTrue($devices->has($name), $name);
            $this->assertNull($devices[$name]['site']);
        }

        // The two links of the stack, one link drawn between the two
        // aggregates.
        $stack = array_values(array_filter(
            $diagram['links'],
            fn (array $link): bool => in_array($keys['sw-serveurs'], [$link['source'], $link['target']], true),
        ));

        $this->assertCount(1, $stack);
        $this->assertSame(2, $stack[0]['links_count']);
        $this->assertSame('Po1 ↔ Po1 · 2 liens', $stack[0]['label']);
        $this->assertSame(['sw-coeur-01 › Po1', 'sw-serveurs › Po1'], $stack[0]['ends']);

        // The fibre under study, towards a device of the workshop at the
        // edge, with the deviation it carries.
        $this->assertSame('Atelier', $devices['sw-atelier-01']['site']['name'] ?? null);

        $fibre = array_values(array_filter($diagram['links'], fn (array $link): bool => $link['target'] === $keys['sw-atelier-01']));

        $this->assertCount(1, $fibre);
        $this->assertSame(['sw-coeur-02 › 1/1/3', 'sw-atelier-01 › 1/1/1'], $fibre[0]['ends']);
        $this->assertTrue($fibre[0]['planned']);
        $this->assertTrue($fibre[0]['fibre']);
        $this->assertSame('link_merges_vlans', $fibre[0]['deviations'][0]['kind'] ?? null);

        // And the other deviations of its links.
        $this->assertGreaterThanOrEqual(3, count(array_filter($diagram['links'], fn (array $link): bool => $link['deviations'] !== [])));
    }

    public function test_the_diagram_of_the_workshop_is_placed_by_hand_but_for_one_device_and_the_head_office_is_laid_out_on_its_own(): void
    {
        $exitCode = Artisan::call('torsade:demo');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Éléments placés sur un schéma', Artisan::output());

        $workshop = Site::query()->where('name', 'Atelier')->sole();
        $office = Site::query()->where('name', 'Siège')->sole();
        $diagram = app(PhysicalDiagram::class)->of($workshop);
        $layout = app(DiagramLayouts::class)->ofNodes($workshop, DiagramView::Physical, DiagramLayouts::physicalNodes($diagram));
        $placed = collect($layout['positions'])->pluck('node')->all();
        $names = collect([...$diagram['devices'], ...$diagram['panels']])->mapWithKeys(fn (array $node): array => [$node['key'] => $node['name']]);

        // Every device drawn but one, the core switch of the head office
        // at the edge included, and the panel of the technical room, each
        // on the grid of 8.
        $this->assertSame(
            ['ap-atelier-1', 'ap-atelier-2', 'fw-atelier', 'PB-A', 'sw-atelier-01', 'sw-atelier-02', 'sw-coeur-02', 'sw-quai'],
            collect($placed)->map(fn (string $key): string => $names[$key])->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        );
        $this->assertSame(['ap-quai'], $names->except($placed)->values()->all());

        foreach ($layout['positions'] as $position) {
            $this->assertSame(0, $position['x'] % 8);
            $this->assertSame(0, $position['y'] % 8);
        }

        // The head office keeps the automatic layout.
        $this->assertSame(0, DiagramPosition::query()->where('site_id', $office->id)->count());

        // Saved as an editor saves it: one entry of the log, on the site.
        $entry = Activity::query()->where('event', ActivityEvent::SiteDiagramSaved->value)->sole();

        $this->assertSame($workshop->id, $entry->subject_id);
        $this->assertSame(
            ['diagram' => 'physical', 'nodes_count' => 8, 'devices_count' => 7, 'panels_count' => 1],
            ($entry->attribute_changes?->toArray() ?? [])['attributes'],
        );
    }

    public function test_the_logical_diagram_of_the_head_office_reads_from_the_internet_down_to_its_switches(): void
    {
        Artisan::call('torsade:demo');

        $office = Site::query()->where('name', 'Siège')->sole();
        $diagram = app(LogicalDiagram::class)->of($office);
        $subnets = collect($diagram['subnets'])->keyBy('cidr');
        $devices = collect($diagram['devices'])->keyBy('name');
        $connectionsOf = fn (string $name): array => array_values(array_filter($diagram['connections'], fn (array $connection): bool => $connection['device'] === $devices[$name]['key']));

        // The internet, linked to the access of the carrier.
        $this->assertSame('internet', $diagram['internet']['key'] ?? null);
        $this->assertSame(
            [['internet', $subnets['203.0.113.8/29']['key']]],
            array_map(fn (array $connection): array => [$connection['source'], $connection['target']], array_values(array_filter($diagram['connections'], fn (array $connection): bool => $connection['kind'] === 'internet'))),
        );

        // The firewall, at the rank of the firewalls: under the carrier,
        // above every segment, the gateway of the VLAN 99 marked.
        $firewall = $devices['fw-siege'];

        $this->assertSame(LogicalDiagram::RANK_ROUTERS, $firewall['rank']);

        foreach ($connectionsOf('fw-siege') as $connection) {
            $subnet = $subnets->firstWhere('key', $connection['subnet']);

            $this->assertSame($subnet['kind'] === 'segment' ? $firewall['key'] : $subnet['key'], $connection['source'], $subnet['cidr']);
        }

        $this->assertContains(
            ['address' => '10.10.99.254', 'gateway' => true],
            array_merge(...array_column($connectionsOf('fw-siege'), 'addresses')),
        );

        // The VPN tunnel, without a site, with the firewall of the workshop
        // at the edge.
        $tunnel = $subnets['172.16.255.0/31'];

        $this->assertSame('tunnel', $tunnel['kind']);
        $this->assertSame(['id' => Site::query()->where('name', 'Atelier')->value('id'), 'name' => 'Atelier'], $devices['fw-atelier']['site'] ?? null);
        $this->assertSame([$tunnel['key']], array_column($connectionsOf('fw-atelier'), 'subnet'));
        $this->assertContains($tunnel['key'], array_column($connectionsOf('fw-siege'), 'subnet'));

        // Every switch of the head office.
        $switches = Device::query()->where('site_id', $office->id)->where('type', DeviceType::Switch)->orderBy('name')->pluck('name')->all();

        $this->assertSame(['sw-coeur-01', 'sw-coeur-02', 'sw-etage-1', 'sw-etage-2', 'sw-salle-reunion', 'sw-serveurs'], $switches);

        foreach ($switches as $name) {
            $this->assertTrue($devices->has($name), $name);
            $this->assertNull($devices[$name]['site'], $name);
        }
    }

    public function test_the_logical_diagram_of_the_workshop_has_the_backup_server_of_the_head_office_at_the_edge(): void
    {
        Artisan::call('torsade:demo');

        $diagram = app(LogicalDiagram::class)->of(Site::query()->where('name', 'Atelier')->sole());
        $production = collect($diagram['subnets'])->firstWhere('cidr', '10.20.60.0/24');
        $server = collect($diagram['devices'])->firstWhere('name', 'srv-sauvegarde-01');

        $this->assertSame(['vid' => 60, 'name' => 'Production'], array_intersect_key($production['vlan'] ?? [], ['vid' => true, 'name' => true]));
        $this->assertSame('Siège', $server['site']['name'] ?? null);
        $this->assertSame(
            [[$production['key'], [['address' => '10.20.60.50', 'gateway' => false]]]],
            array_map(
                fn (array $connection): array => [$connection['subnet'], $connection['addresses']],
                array_values(array_filter($diagram['connections'], fn (array $connection): bool => $connection['device'] === $server['key'])),
            ),
        );
    }

    public function test_the_rooms_keep_patch_panels_the_floors_have_jacks_and_the_panels_are_patched_to_the_switches(): void
    {
        $exitCode = Artisan::call('torsade:demo');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        foreach (['Panneaux de brassage', 'Prises', 'Cordons de brassage', 'Cordons de prise'] as $line) {
            $this->assertStringContainsString($line, $output);
        }

        $this->assertSame(5, PatchPanel::query()->count());
        $this->assertSame(108, PatchPanelPort::query()->count());
        $this->assertSame(46, Jack::query()->count());
        $this->assertSame(40, PatchCord::query()->count());
        $this->assertSame(5, JackCord::query()->count());
        $this->assertSame(26, IpAddress::query()->whereNotNull('jack_id')->count());

        // A panel in each technical room, those of the floors of the head
        // office with the switch of the floor.
        foreach (Room::query()->where('is_technical', true)->get() as $room) {
            $this->assertTrue(PatchPanel::query()->where('room_id', $room->id)->exists(), $room->name);
        }

        foreach (['PB-B' => 'sw-etage-1', 'PB-C' => 'sw-etage-2'] as $name => $switch) {
            $panel = PatchPanel::query()->where('name', $name)->with('room')->sole();

            $this->assertSame('Placard technique', $panel->room->name);
            $this->assertTrue($panel->room->is_technical);
            $this->assertSame($panel->room_id, Device::query()->where('name', $switch)->value('room_id'));
        }

        // At the head office, a fibre panel without a jack, patched to the
        // uplinks of the core switches, which are linked to the floors.
        $fibre = PatchPanel::query()->where('name', 'PB-FO')->sole();
        $risers = PatchCord::query()->whereHas('panelPort', fn ($query) => $query->where('patch_panel_id', $fibre->id))->with('port.linkEnd')->get();

        $this->assertSame('lc', $fibre->connector->value);
        $this->assertFalse(Jack::query()->whereHas('panelPort', fn ($query) => $query->where('patch_panel_id', $fibre->id))->exists());
        $this->assertCount(3, $risers);

        foreach ($risers as $riser) {
            $this->assertNotNull($riser->port->linkEnd);
        }

        // The jacks are labelled in series on their floor and cabled to a
        // panel, but two of them; some of them are not patched, and some
        // ports of the panels are free.
        $this->assertSame(['B.01', 'B.02', 'B.10', 'B.16'], Jack::query()->whereIn('label', ['B.01', 'B.02', 'B.10', 'B.16'])->orderBy('label')->pluck('label')->all());
        $this->assertSame(2, Jack::query()->whereNull('patch_panel_port_id')->count());
        $this->assertGreaterThan(0, Jack::query()->whereNotNull('patch_panel_port_id')->whereDoesntHave('panelPort.cord')->count());
        $this->assertGreaterThan(0, PatchPanelPort::query()->whereDoesntHave('jack')->whereDoesntHave('cord')->count());

        // The cords go to the switches of the floors and of the workshop,
        // and to the core switches for the risers.
        $this->assertSame(
            ['sw-atelier-01' => 10, 'sw-coeur-01' => 1, 'sw-coeur-02' => 2, 'sw-etage-1' => 17, 'sw-etage-2' => 10],
            PatchCord::query()->with('port.device')->get()->countBy(fn (PatchCord $cord): string => $cord->port->device->name)->sortKeys()->all(),
        );

        // Access points and the switch of the meeting room are plugged into
        // jacks, on the side of the desk, each by its uplink.
        $this->assertSame(
            ['B.13' => 'ap-etage-1 › eth0', 'B.15' => 'sw-salle-reunion › 8', 'C.10' => 'ap-etage-2 › eth0', 'H.03' => 'ap-atelier-1 › eth0', 'H.04' => 'ap-atelier-2 › eth0'],
            JackCord::query()->with(['jack', 'port.device'])->get()
                ->mapWithKeys(fn (JackCord $cord): array => [$cord->jack->label => Links::named($cord->port)])
                ->sortKeys()
                ->all(),
        );
    }

    public function test_the_deviations_of_the_cabling_are_there_on_purpose_and_each_one_is_explained(): void
    {
        Artisan::call('torsade:demo');

        $cabling = new CablingDeviations;
        $paths = app(CabledPaths::class)->of();
        $found = [];

        // A deviation of a jack is explained by the jack, or by the address
        // plugged into it.
        foreach (Jack::query()->with(CablingDeviations::JACK)->orderBy('id')->get() as $jack) {
            foreach ([...$cabling->ofJack($jack), ...$paths->ofJack($jack)] as $deviation) {
                $found[] = [$deviation['kind'], 'jack '.$jack->label, $jack->description.' '.$jack->ipAddresses->pluck('description')->implode(' ')];
            }
        }

        foreach (PatchPanelPort::query()->with(['panel.room.floor.building', ...CablingDeviations::PANEL_PORT])->get() as $panelPort) {
            foreach ($cabling->ofPanelPort($panelPort) as $deviation) {
                if ($deviation['kind'] === 'cord_across_rooms') {
                    $found[] = [$deviation['kind'], $panelPort->name(), (string) $panelPort->cord?->description];
                }
            }
        }

        // Each explained in a description: an active address on a jack
        // that is not patched, an address whose VLAN the port does not
        // carry, active or only planned, a linked port patched to a jack
        // where nothing is plugged, two ports cabled without a link, a cord
        // between two rooms.
        $this->assertSame(
            ['address_on_unpatched_jack', 'address_vlan_not_carried', 'linked_without_plug', 'cabling_without_link', 'cord_across_rooms'],
            array_values(array_unique(array_column($found, 0))),
        );
        $this->assertSame(['jack R.05', 'jack B.10', 'jack B.14', 'jack C.09', 'jack H.03', 'PB-A 24'], array_column($found, 1));

        // The camera on the jack B.10 is only planned.
        $camera = IpAddress::query()->where('hostname', 'cam-couloir-1')->with('jack')->sole();

        $this->assertSame([Status::Planned, 'B.10'], [$camera->status, $camera->jack?->label]);

        // The cord between two rooms, named by what tells them apart.
        $rescue = PatchCord::query()->where('color', 'Rouge')->sole();
        $panelPort = PatchPanelPort::query()->with(['panel.room.floor.building', ...CablingDeviations::PANEL_PORT])->findOrFail($rescue->patch_panel_port_id);

        $this->assertSame(
            [['kind' => 'cord_across_rooms', 'reason' => 'Panneau dans Rez-de-chaussée › Local technique, sw-etage-1 dans 1er étage › Placard technique']],
            $cabling->ofPanelPort($panelPort),
        );

        foreach ($found as [, , $description]) {
            $this->assertStringContainsString('conservé pour la démonstration', $description);
        }

        // Each address plugged into a jack is told in the log; so is each
        // cord, and each series of jacks once, on its floor.
        $count = fn (ActivityEvent $event): int => Activity::query()->where('event', $event->value)->count();

        $this->assertSame(5, $count(ActivityEvent::PatchPanelCreated));
        $this->assertSame(6, $count(ActivityEvent::FloorJacksAdded));
        $this->assertSame(0, $count(ActivityEvent::JackCreated));
        $this->assertSame(4, $count(ActivityEvent::JackUpdated));
        $this->assertSame(40, $count(ActivityEvent::PatchCordCreated));
        $this->assertSame(5, $count(ActivityEvent::JackCordCreated));
        $this->assertSame(26, $count(ActivityEvent::IpAddressPlugged));
        $this->assertSame(0, $count(ActivityEvent::IpAddressUpdated));
    }

    public function test_a_refusal_is_logged_too(): void
    {
        Site::factory()->create();

        Artisan::call('torsade:demo');

        $command = Activity::query()->where('event', ActivityEvent::CommandExecuted->value)->sole();

        $this->assertSame('torsade:demo', $command->getProperty('command'));
        $this->assertSame('refused', $command->getProperty('outcome'));
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_the_default_seeder_does_not_create_demonstration_data(): void
    {
        $this->seed();

        $this->assertDatabaseCount('sites', 0);
        $this->assertNothingWasCreated();
    }

    public function test_the_demonstration_data_can_be_browsed(): void
    {
        Artisan::call('torsade:demo');

        $reader = User::factory()->reader()->create();
        $offices = Subnet::query()->get()->firstOrFail(fn (Subnet $subnet): bool => $subnet->cidr() === '10.10.10.0/24');
        $management = Subnet::query()->get()->firstOrFail(fn (Subnet $subnet): bool => $subnet->cidr() === '10.10.99.0/24');

        $this->actingAs($reader)->get(route('sites.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sites', 3));
        $this->actingAs($reader)->get(route('vlans.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('vlans', 13));
        $this->actingAs($reader)->get(route('subnets.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('subnets', 17));
        $this->actingAs($reader)->get(route('catalog-vlans.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries', 6)->has('sites', 3));
        $this->actingAs($reader)->get(route('address-blocks.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('blocks', 4)->where('outside', []));
        $this->actingAs($reader)->get(route('addressing-rule.show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rule.block', '10.0.0.0/8')->has('application', 3));
        $this->actingAs($reader)->get(route('devices.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('devices', 34));

        // The page of each site shows its places and its devices.
        foreach (['Siège' => [1, 23], 'Atelier' => [1, 11], 'Dépôt' => [0, 0]] as $name => [$buildings, $devices]) {
            $this->actingAs($reader)->get(route('sites.show', Site::query()->where('name', $name)->sole()))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('places', $buildings)->has('devices', $devices));
        }

        // The page of each site lists its deviations: the old network of
        // the head office, the cameras of the workshop numbered 150 and
        // addressed as 50, nothing on the site that is being planned.
        foreach (['Siège' => 1, 'Atelier' => 2, 'Dépôt' => 0] as $name => $expected) {
            $this->actingAs($reader)->get(route('sites.show', Site::query()->where('name', $name)->sole()))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('deviations', $expected)->where('siteDeviations', []));
        }

        $this->actingAs($reader)->get(route('subnets.show', $offices))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('addresses', 20)
                ->has('ipRanges', 1)
                ->where('subnet.gateway', '10.10.10.254')
                ->where('subnet.vlan.name', 'Bureautique')
                ->where('subnet.vlan.site', 'Siège')
                // 254 usable, minus the gateway, the 100 addresses of the
                // range and the 6 static addresses outside it.
                ->where('freeCount', 147));

        // The firewall is documented on the address of the gateway. The
        // subnet is cut into ranges: the next address comes from the
        // static one.
        $this->actingAs($reader)->get(route('subnets.show', $management))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.10.address', '10.10.99.254')
                ->where('addresses.10.is_gateway', true)
                ->has('ipRanges', 2)
                ->where('nextFreeAddress', ['address' => '10.10.99.1', 'kind' => 'static', 'range' => 'Équipements réseau']));
    }
}
