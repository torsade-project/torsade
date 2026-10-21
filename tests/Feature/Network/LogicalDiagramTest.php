<?php

// torsade/tests/Feature/Network/LogicalDiagramTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\DiagramNode;
use App\Enums\DiagramView;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DiagramPosition;
use App\Models\IpAddress;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\LogicalDiagram;
use Database\Factories\PortFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * The logical diagram of a site, worked out case by case: which subnets
 * are drawn and what their bars tell, the internet, which devices are
 * drawn and how each is plugged into a subnet, from top to bottom, in a
 * stable order, with the deviations of subnets, of VLANs and of devices.
 */
class LogicalDiagramTest extends NetworkTestCase
{
    private Site $office;

    private Site $workshop;

    /**
     * The ports of each device, by the name of the device, then of the port.
     *
     * @var array<string, array<string, Port>>
     */
    private array $ports = [];

    /** @var array<string, Device> */
    private array $devices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
        $this->workshop = Site::factory()->create(['name' => 'Atelier', 'code' => 'ATE']);
    }

    /**
     * A device of one member of six ports.
     */
    private function device(string $name, DeviceType $type, ?Site $site = null, Status $status = Status::Active): Device
    {
        $device = Device::factory()->create([
            'site_id' => ($site ?? $this->office)->id,
            'name' => $name,
            'type' => $type,
            'status' => $status,
        ]);

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id, 'position' => 1]), 6) as $port) {
            $this->ports[$name][$port->name] = $port;
        }

        return $this->devices[$name] = $device;
    }

    private function vlan(int $vid, string $name, ?Site $site = null): Vlan
    {
        return Vlan::factory()->create(['site_id' => ($site ?? $this->office)->id, 'vid' => $vid, 'name' => $name]);
    }

    /**
     * A subnet of the site given, or of none.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function on(?Site $site, string $cidr, SubnetKind $kind = SubnetKind::Segment, array $attributes = []): Subnet
    {
        return $this->subnet($cidr, ['site_id' => $site?->id, 'kind' => $kind, ...$attributes]);
    }

    private function address(Subnet $subnet, string $address, ?string $device = null, Status $status = Status::Active): IpAddress
    {
        return IpAddress::factory()->create([
            'subnet_id' => $subnet->id,
            'address' => $address,
            'device_id' => $device === null ? null : $this->devices[$device]->id,
            'status' => $status,
        ]);
    }

    /**
     * A VLAN carried by ports of a device, in the mode given.
     *
     * @param  list<string>  $ports
     */
    private function carry(string $device, array $ports, Vlan $vlan, PortMode $mode, bool $tagged): void
    {
        foreach ($ports as $name) {
            $port = $this->ports[$device][$name];

            $port->update(['mode' => $mode]);
            PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlan->id, 'tagged' => $tagged]);
        }
    }

    /**
     * An aggregate of ports of a device, which carries a VLAN in the mode
     * given.
     *
     * @param  list<string>  $ports
     */
    private function aggregateCarrying(string $device, array $ports, Vlan $vlan, PortMode $mode, bool $tagged): PortAggregate
    {
        $aggregate = PortAggregate::factory()->create(['device_id' => $this->devices[$device]->id, 'name' => 'Po1', 'mode' => $mode]);

        foreach ($ports as $name) {
            $this->ports[$device][$name]->update(['port_aggregate_id' => $aggregate->id]);
        }

        PortVlan::factory()->create(['port_aggregate_id' => $aggregate->id, 'vlan_id' => $vlan->id, 'tagged' => $tagged]);

        return $aggregate;
    }

    /**
     * @return array<string, mixed>
     */
    private function diagram(?Site $site = null): array
    {
        return app(LogicalDiagram::class)->of($site ?? $this->office);
    }

    /**
     * The bars drawn, by CIDR.
     *
     * @param  array<string, mixed>  $diagram
     * @return array<string, array<string, mixed>>
     */
    private function subnetsOf(array $diagram): array
    {
        return collect($diagram['subnets'])->keyBy('cidr')->all();
    }

    /**
     * The devices drawn, by name.
     *
     * @param  array<string, mixed>  $diagram
     * @return array<string, array<string, mixed>>
     */
    private function devicesOf(array $diagram): array
    {
        return collect($diagram['devices'])->keyBy('name')->all();
    }

    /**
     * The connection of a device to a subnet, if there is one.
     *
     * @param  array<string, mixed>  $diagram
     * @return array<string, mixed>|null
     */
    private function connection(array $diagram, string $device, Subnet $subnet): ?array
    {
        $found = array_values(array_filter(
            $diagram['connections'],
            fn (array $connection): bool => $connection['device'] === 'device-'.$this->devices[$device]->id && $connection['subnet'] === 'subnet-'.$subnet->id,
        ));

        $this->assertLessThanOrEqual(1, count($found), 'One connection at most between a device and a subnet.');

        return $found[0] ?? null;
    }

    // --- Subnets ----------------------------------------------------------

    public function test_the_subnets_of_the_site_of_every_kind_are_drawn_and_no_subnet_retired_or_of_another_site(): void
    {
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('pc-accueil', DeviceType::Workstation);
        $this->device('fw-atelier', DeviceType::Firewall, $this->workshop);

        $this->on($this->office, '10.10.10.0/24', attributes: ['vlan_id' => $this->vlan(10, 'Bureautique')->id]);
        $this->on($this->office, '10.255.1.0/31', SubnetKind::PointToPoint);
        $this->on($this->office, '10.10.255.1/32', SubnetKind::Loopback);
        $this->on($this->office, '172.16.0.0/30', SubnetKind::Tunnel);
        $this->on($this->office, '203.0.113.8/29', SubnetKind::External);

        // Retired, or of another site, even where a device of the site has
        // an address.
        $this->on($this->office, '10.10.20.0/24', attributes: ['status' => Status::Retired]);
        $this->address($this->on($this->workshop, '10.20.10.0/24'), '10.20.10.5', 'fw-siege');

        // Without a site: drawn where a device of the site that is drawn
        // has an address, not where one that is not drawn has one, nor
        // where only a device of another site has one.
        $this->address($tunnel = $this->on(null, '172.16.255.0/31', SubnetKind::Tunnel), '172.16.255.0', 'fw-siege');
        $this->address($tunnel, '172.16.255.1', 'fw-atelier');
        $this->address($this->on(null, '10.255.0.0/31', SubnetKind::PointToPoint), '10.255.0.0', 'pc-accueil');
        $this->address($this->on(null, '10.255.0.2/31', SubnetKind::PointToPoint), '10.255.0.2', 'fw-atelier');
        $this->address($retired = $this->on(null, '10.255.0.4/31', SubnetKind::PointToPoint, ['status' => Status::Retired]), '10.255.0.4', 'fw-siege');

        $diagram = $this->diagram();

        // In the order of their addresses.
        $this->assertSame(
            ['10.10.10.0/24', '10.10.255.1/32', '10.255.1.0/31', '172.16.0.0/30', '172.16.255.0/31', '203.0.113.8/29'],
            array_column($diagram['subnets'], 'cidr'),
        );
        $this->assertSame(
            ['segment', 'loopback', 'point_to_point', 'tunnel', 'tunnel', 'external'],
            array_column($diagram['subnets'], 'kind'),
        );
        $this->assertSame(['Segment', 'Loopback', 'Point à point', 'Tunnel', 'Tunnel', 'Externe'], array_column($diagram['subnets'], 'kind_label'));
        $this->assertSame(6, $diagram['summary']['subnets']);

        // The firewall of the workshop, at the edge, by the tunnel.
        $this->assertSame(['fw-atelier', 'fw-siege'], array_keys($this->devicesOf($diagram)));
        $this->assertNotNull($this->connection($diagram, 'fw-atelier', $tunnel));
        $this->assertNull($this->connection($diagram, 'fw-siege', $retired));
    }

    public function test_a_bar_tells_its_subnet_its_vlan_its_gateway_and_how_many_other_addresses(): void
    {
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('pc-accueil', DeviceType::Workstation);

        $vlan = $this->vlan(10, 'Bureautique');
        $offices = $this->on($this->office, '10.10.10.0/24', attributes: ['vlan_id' => $vlan->id, 'name' => 'Postes', 'gateway' => '10.10.10.1']);

        // The addresses of the devices drawn are on their connections.
        $this->address($offices, '10.10.10.1', 'fw-siege');
        $this->address($offices, '10.10.10.2', 'sw-coeur');

        // Thirty-seven addresses of no device, and the one of a
        // workstation, which is not drawn; a retired one is not counted.
        foreach (range(11, 47) as $host) {
            $this->address($offices, '10.10.10.'.$host);
        }

        $this->address($offices, '10.10.10.50', 'pc-accueil');
        $this->address($offices, '10.10.10.60', status: Status::Retired);

        $one = $this->on($this->office, '10.10.30.0/24', attributes: ['status' => Status::Planned]);
        $this->address($one, '10.10.30.10');

        $none = $this->on($this->office, '10.10.40.0/24');

        $bars = $this->subnetsOf($this->diagram());

        $this->assertSame([
            'key' => 'subnet-'.$offices->id,
            'id' => $offices->id,
            'cidr' => '10.10.10.0/24',
            'name' => 'Postes',
            'kind' => 'segment',
            'kind_label' => 'Segment',
            'rank' => LogicalDiagram::RANK_SEGMENTS,
            'planned' => false,
            'vlan' => ['id' => $vlan->id, 'vid' => 10, 'name' => 'Bureautique'],
            'gateway' => '10.10.10.1',
            'others' => 38,
            'others_label' => '38 autres adresses',
            'deviations' => [],
        ], $bars['10.10.10.0/24']);

        // One other address is said in the singular, and none is not said.
        $this->assertSame([1, '1 autre adresse', true, null, null], [
            $bars['10.10.30.0/24']['others'], $bars['10.10.30.0/24']['others_label'], $bars['10.10.30.0/24']['planned'], $bars['10.10.30.0/24']['vlan'], $bars['10.10.30.0/24']['gateway'],
        ]);
        $this->assertSame([0, null], [$bars['10.10.40.0/24']['others'], $bars['10.10.40.0/24']['others_label']]);
        $this->assertSame(0, $none->ipAddresses()->count());
    }

    // --- The internet -----------------------------------------------------

    public function test_the_internet_is_drawn_with_an_external_subnet_only_and_linked_to_each(): void
    {
        $this->on($this->office, '10.10.10.0/24');
        $this->on($this->office, '172.16.0.0/30', SubnetKind::Tunnel);

        $diagram = $this->diagram();

        $this->assertNull($diagram['internet']);
        $this->assertSame([], array_values(array_filter($diagram['connections'], fn (array $connection): bool => $connection['kind'] === 'internet')));

        $first = $this->on($this->office, '203.0.113.8/29', SubnetKind::External);
        $second = $this->on($this->office, '198.51.100.0/29', SubnetKind::External);

        $diagram = $this->diagram();

        $this->assertSame(['key' => 'internet', 'rank' => LogicalDiagram::RANK_INTERNET], $diagram['internet']);
        $this->assertSame([
            ['key' => 'internet-'.$second->id, 'kind' => 'internet', 'source' => 'internet', 'target' => 'subnet-'.$second->id, 'subnet' => 'subnet-'.$second->id, 'device' => null, 'addresses' => [], 'carried' => null],
            ['key' => 'internet-'.$first->id, 'kind' => 'internet', 'source' => 'internet', 'target' => 'subnet-'.$first->id, 'subnet' => 'subnet-'.$first->id, 'device' => null, 'addresses' => [], 'carried' => null],
        ], array_values(array_filter($diagram['connections'], fn (array $connection): bool => $connection['kind'] === 'internet')));
        // The connections the summary counts are those of the devices.
        $this->assertSame(0, $diagram['summary']['connections']);
    }

    // --- Devices ----------------------------------------------------------

    public function test_every_firewall_router_and_switch_of_the_site_is_drawn_even_with_no_address_nor_vlan(): void
    {
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('rtr-siege', DeviceType::Router);
        $this->device('sw-vide', DeviceType::Switch, status: Status::Planned);
        $this->device('sw-ancien', DeviceType::Switch, status: Status::Retired);
        $this->device('ap-accueil', DeviceType::AccessPoint);
        $this->device('srv-seul', DeviceType::Server);
        $this->device('sw-atelier', DeviceType::Switch, $this->workshop);

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertSame(['fw-siege', 'rtr-siege', 'sw-vide'], array_keys($devices));
        $this->assertSame([], $diagram['connections']);

        $this->assertSame([
            'key' => 'device-'.$this->devices['sw-vide']->id,
            'id' => $this->devices['sw-vide']->id,
            'name' => 'sw-vide',
            'type' => 'switch',
            'type_label' => 'Switch',
            'rank' => LogicalDiagram::RANK_DEVICES,
            'planned' => true,
            'site' => null,
            'members' => 1,
            'deviations' => [],
        ], $devices['sw-vide']);
        $this->assertSame([LogicalDiagram::RANK_ROUTERS, LogicalDiagram::RANK_ROUTERS], [$devices['fw-siege']['rank'], $devices['rtr-siege']['rank']]);
    }

    public function test_another_device_of_the_site_is_drawn_with_two_subnets_or_a_vlan_carried_and_a_workstation_with_one_address_is_not(): void
    {
        $this->device('srv-sauvegarde', DeviceType::Server);
        $this->device('srv-erp', DeviceType::Server);
        $this->device('hyp-01', DeviceType::Server);
        $this->device('pc-accueil', DeviceType::Workstation);

        $vlan = $this->vlan(20, 'Serveurs');
        $servers = $this->on($this->office, '10.10.20.0/24', attributes: ['vlan_id' => $vlan->id]);
        $backup = $this->on($this->office, '10.10.21.0/24');
        $tunnel = $this->on(null, '172.16.255.0/31', SubnetKind::Tunnel);

        // Two subnets of the site.
        $this->address($servers, '10.10.20.10', 'srv-sauvegarde');
        $this->address($backup, '10.10.21.10', 'srv-sauvegarde');

        // One subnet of the site and one without a site: the server is
        // drawn by the two of them, and brings the one without a site.
        $this->address($servers, '10.10.20.11', 'srv-erp');
        $this->address($tunnel, '172.16.255.0', 'srv-erp');

        // The VLAN of a subnet drawn, carried by a port.
        $this->carry('hyp-01', ['1/0/1'], $vlan, PortMode::Trunk, true);

        // One address alone.
        $this->address($servers, '10.10.20.50', 'pc-accueil');

        $diagram = $this->diagram();

        $this->assertSame(['hyp-01', 'srv-erp', 'srv-sauvegarde'], array_keys($this->devicesOf($diagram)));
        $this->assertContains('172.16.255.0/31', array_column($diagram['subnets'], 'cidr'));

        $this->assertNotNull($this->connection($diagram, 'srv-sauvegarde', $servers));
        $this->assertNotNull($this->connection($diagram, 'srv-sauvegarde', $backup));
        $this->assertNotNull($this->connection($diagram, 'srv-erp', $tunnel));
        $this->assertSame('Étiqueté sur 1 port', $this->connection($diagram, 'hyp-01', $servers)['carried'] ?? null);
        $this->assertSame(1, $this->subnetsOf($diagram)['10.10.20.0/24']['others']);

        // Its other address on the network of another site, the server is
        // left with one subnet drawn: it is not drawn, and the subnet
        // without a site it brought goes with it.
        IpAddress::query()->where('subnet_id', $tunnel->id)->delete();
        $this->address($this->on($this->workshop, '10.20.20.0/24'), '10.20.20.11', 'srv-erp');

        $diagram = $this->diagram();

        $this->assertSame(['hyp-01', 'srv-sauvegarde'], array_keys($this->devicesOf($diagram)));
        $this->assertNotContains('172.16.255.0/31', array_column($diagram['subnets'], 'cidr'));
    }

    public function test_a_device_of_another_site_is_drawn_at_the_edge_by_an_address_then_by_a_vlan_carried(): void
    {
        $this->device('srv-sauvegarde', DeviceType::Server, $this->workshop);
        $this->device('fw-central', DeviceType::Firewall, $this->workshop);
        $this->device('sw-atelier', DeviceType::Switch, $this->workshop);
        $this->device('srv-ancien', DeviceType::Server, $this->workshop, Status::Retired);

        $vlan = $this->vlan(10, 'Bureautique');
        $offices = $this->on($this->office, '10.10.10.0/24', attributes: ['vlan_id' => $vlan->id]);

        $this->address($offices, '10.10.10.20', 'srv-sauvegarde');
        $this->address($offices, '10.10.10.21', 'srv-ancien');

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertSame(['srv-sauvegarde'], array_keys($devices));
        $this->assertSame(['id' => $this->workshop->id, 'name' => 'Atelier'], $devices['srv-sauvegarde']['site']);
        $this->assertSame([['address' => '10.10.10.20', 'gateway' => false]], $this->connection($diagram, 'srv-sauvegarde', $offices)['addresses'] ?? null);
        // The address of the device retired is one of the others.
        $this->assertSame(1, $this->subnetsOf($diagram)['10.10.10.0/24']['others']);

        // A central firewall that carries the VLAN on an aggregate.
        $this->aggregateCarrying('fw-central', ['1/0/1', '1/0/2'], $vlan, PortMode::Trunk, true);

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertSame(['fw-central', 'srv-sauvegarde'], array_keys($devices));
        $this->assertSame(['id' => $this->workshop->id, 'name' => 'Atelier'], $devices['fw-central']['site']);
        $this->assertSame([[], 'Étiqueté sur 2 ports'], [
            $this->connection($diagram, 'fw-central', $offices)['addresses'] ?? null,
            $this->connection($diagram, 'fw-central', $offices)['carried'] ?? null,
        ]);
    }

    // --- Connections ------------------------------------------------------

    public function test_a_device_is_plugged_once_by_its_addresses_by_its_vlan_or_both_with_its_gateway_marked(): void
    {
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('srv-erp', DeviceType::Server);

        $vlan = $this->vlan(10, 'Bureautique');
        $offices = $this->on($this->office, '10.10.10.0/24', attributes: ['vlan_id' => $vlan->id, 'gateway' => '10.10.10.1']);
        $other = $this->on($this->office, '10.10.11.0/24');

        // Both: two addresses, the gateway among them, and the VLAN on a
        // port and on an aggregate of two ports.
        $this->address($offices, '10.10.10.3', 'fw-siege');
        $this->address($offices, '10.10.10.1', 'fw-siege');
        $this->carry('fw-siege', ['1/0/1'], $vlan, PortMode::Access, false);
        $this->aggregateCarrying('fw-siege', ['1/0/5', '1/0/6'], $vlan, PortMode::Trunk, true);

        // The VLAN alone, on three ports in access and one as native.
        $this->carry('sw-coeur', ['1/0/1', '1/0/2', '1/0/3'], $vlan, PortMode::Access, false);
        $this->carry('sw-coeur', ['1/0/4'], $vlan, PortMode::Trunk, false);
        $this->carry('sw-coeur', ['1/0/5'], $this->vlan(30, 'Téléphonie'), PortMode::Access, false);

        // Addresses alone.
        $this->address($offices, '10.10.10.20', 'srv-erp');
        $this->address($other, '10.10.11.20', 'srv-erp');

        $diagram = $this->diagram();

        $this->assertSame([
            'key' => 'connection-'.$this->devices['fw-siege']->id.'-'.$offices->id,
            'kind' => 'device',
            'source' => 'device-'.$this->devices['fw-siege']->id,
            'target' => 'subnet-'.$offices->id,
            'subnet' => 'subnet-'.$offices->id,
            'device' => 'device-'.$this->devices['fw-siege']->id,
            'addresses' => [['address' => '10.10.10.1', 'gateway' => true], ['address' => '10.10.10.3', 'gateway' => false]],
            'carried' => 'Accès sur 1 port, étiqueté sur 2 ports',
        ], $this->connection($diagram, 'fw-siege', $offices));

        $switch = $this->connection($diagram, 'sw-coeur', $offices);

        $this->assertSame([[], 'Accès sur 3 ports, natif sur 1 port'], [$switch['addresses'] ?? null, $switch['carried'] ?? null]);
        $this->assertNull($this->connection($diagram, 'sw-coeur', $other));

        $server = $this->connection($diagram, 'srv-erp', $offices);

        $this->assertSame([[['address' => '10.10.10.20', 'gateway' => false]], null], [$server['addresses'] ?? null, $server['carried'] ?? null]);

        // One connection each, in the order of the subnets, then of the
        // devices.
        $this->assertSame(4, $diagram['summary']['connections']);
        $this->assertSame(
            [['10.10.10.0/24', 'fw-siege'], ['10.10.10.0/24', 'srv-erp'], ['10.10.10.0/24', 'sw-coeur'], ['10.10.11.0/24', 'srv-erp']],
            array_map(fn (array $connection): array => [
                collect($diagram['subnets'])->firstWhere('key', $connection['subnet'])['cidr'],
                collect($diagram['devices'])->firstWhere('key', $connection['device'])['name'],
            ], $diagram['connections']),
        );
    }

    public function test_connections_are_read_from_top_to_bottom_by_rank(): void
    {
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('rtr-siege', DeviceType::Router);
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('srv-erp', DeviceType::Server);

        $external = $this->on($this->office, '203.0.113.8/29', SubnetKind::External);
        $loopback = $this->on($this->office, '10.10.255.1/32', SubnetKind::Loopback);
        $segment = $this->on($this->office, '10.10.10.0/24');

        $this->address($external, '203.0.113.10', 'fw-siege');
        $this->address($external, '203.0.113.11', 'sw-coeur');
        $this->address($loopback, '10.10.255.1', 'rtr-siege');
        $this->address($segment, '10.10.10.1', 'fw-siege');
        $this->address($segment, '10.10.10.2', 'sw-coeur');
        $this->address($segment, '10.10.10.20', 'srv-erp');
        $this->address($external, '203.0.113.12', 'srv-erp');

        $diagram = $this->diagram();

        $ranks = array_column([...$diagram['subnets'], ...$diagram['devices']], 'rank', 'key');

        $this->assertSame(LogicalDiagram::RANK_EDGE_SUBNETS, $ranks['subnet-'.$external->id]);
        $this->assertSame(LogicalDiagram::RANK_EDGE_SUBNETS, $ranks['subnet-'.$loopback->id]);
        $this->assertSame(LogicalDiagram::RANK_SEGMENTS, $ranks['subnet-'.$segment->id]);
        $this->assertSame(LogicalDiagram::RANK_ROUTERS, $ranks['device-'.$this->devices['fw-siege']->id]);
        $this->assertSame(LogicalDiagram::RANK_DEVICES, $ranks['device-'.$this->devices['srv-erp']->id]);

        // Each connection goes down a rank or more.
        $ranks['internet'] = LogicalDiagram::RANK_INTERNET;

        foreach ($diagram['connections'] as $connection) {
            $this->assertLessThan($ranks[$connection['target']], $ranks[$connection['source']], $connection['key']);
        }

        $this->assertSame('subnet-'.$external->id, $this->connection($diagram, 'fw-siege', $external)['source'] ?? null);
        $this->assertSame('device-'.$this->devices['fw-siege']->id, $this->connection($diagram, 'fw-siege', $segment)['source'] ?? null);
        $this->assertSame('subnet-'.$segment->id, $this->connection($diagram, 'sw-coeur', $segment)['source'] ?? null);
        $this->assertSame('subnet-'.$external->id, $this->connection($diagram, 'sw-coeur', $external)['source'] ?? null);
        $this->assertSame('subnet-'.$loopback->id, $this->connection($diagram, 'rtr-siege', $loopback)['source'] ?? null);
    }

    public function test_the_same_data_always_gives_the_same_diagram_in_the_order_of_the_addresses_and_of_the_names(): void
    {
        $this->device('sw-10', DeviceType::Switch);
        $this->device('sw-2', DeviceType::Switch);
        $this->device('FW-b', DeviceType::Firewall);
        $this->device('fw-a', DeviceType::Firewall);

        $this->on($this->office, '10.10.10.0/24');
        $this->on($this->office, '10.10.9.0/24');
        $this->on($this->office, '10.10.10.128/25', attributes: ['status' => Status::Planned]);
        $this->on($this->office, '9.255.0.0/16');

        $diagram = $this->diagram();

        $this->assertSame(['9.255.0.0/16', '10.10.9.0/24', '10.10.10.0/24', '10.10.10.128/25'], array_column($diagram['subnets'], 'cidr'));
        $this->assertSame(['fw-a', 'FW-b', 'sw-2', 'sw-10'], array_column($diagram['devices'], 'name'));
        $this->assertSame($diagram, $this->diagram());
    }

    // --- Deviations -------------------------------------------------------

    public function test_the_deviations_of_a_subnet_of_its_vlan_and_of_a_device_are_on_its_bar_and_on_the_device(): void
    {
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('srv-atelier', DeviceType::Server, $this->workshop);

        AddressBlock::factory()->create(['site_id' => $this->office->id, 'network' => '10.10.0.0', 'prefix_length' => 16]);

        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'BUREAUTIQUE']);
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'catalog_vlan_id' => $entry->id, 'vid' => 110, 'name' => 'Bureautique']);
        $outside = $this->on($this->office, '10.99.1.0/24', attributes: ['vlan_id' => $vlan->id]);

        // The switch has an address on the network of the workshop, and
        // merges two VLANs 10.
        $this->address($this->on($this->workshop, '10.20.10.0/24'), '10.20.10.2', 'sw-coeur');
        $this->carry('sw-coeur', ['1/0/1'], $this->vlan(10, 'Bureautique siège'), PortMode::Trunk, true);
        $this->carry('sw-coeur', ['1/0/2'], $this->vlan(10, 'Bureautique atelier', $this->workshop), PortMode::Trunk, true);

        // A device of another site at the edge: its deviations are told on
        // its own site.
        $this->address($outside, '10.99.1.20', 'srv-atelier');

        $diagram = $this->diagram();

        $this->assertSame(
            ['subnet_outside_site_blocks', 'vlan_vid'],
            array_column($this->subnetsOf($diagram)['10.99.1.0/24']['deviations'], 'kind'),
        );
        $this->assertSame('VID 110, catalogue : 10', $this->subnetsOf($diagram)['10.99.1.0/24']['deviations'][1]['reason']);

        $devices = $this->devicesOf($diagram);

        $this->assertSame(
            ['address_on_device_of_another_site', 'switch_merges_vlans'],
            array_column($devices['sw-coeur']['deviations'], 'kind'),
        );
        $this->assertSame('10.20.10.2 : Sous-réseau du site Atelier, équipement du site Siège', $devices['sw-coeur']['deviations'][0]['reason']);
        $this->assertSame([], $devices['srv-atelier']['deviations']);
        $this->assertSame(4, $diagram['summary']['deviations']);
    }

    // --- Volume -----------------------------------------------------------

    public function test_the_page_costs_the_same_whatever_the_number_of_subnets_devices_and_addresses(): void
    {
        $reader = $this->reader();
        $queries = 0;

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        // The page, as a reader opens it.
        $cost = function () use (&$queries, $reader): int {
            $queries = 0;
            $this->actingAs($reader)->get(route('diagrams.logical', ['site' => $this->office->id]))->assertOk();

            return $queries;
        };

        // A little of everything the diagram reads: a subnet of the site
        // carried by a VLAN, an external one, one without a site; a
        // firewall, a switch that carries the VLAN on a port and on an
        // aggregate, a server on two subnets, a device of another site by
        // an address and another by the VLAN it carries.
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('srv-erp', DeviceType::Server);
        $this->device('srv-atelier', DeviceType::Server, $this->workshop);
        $this->device('fw-atelier', DeviceType::Firewall, $this->workshop);

        $vlan = $this->vlan(10, 'Bureautique');
        $offices = $this->on($this->office, '10.10.10.0/24', attributes: ['vlan_id' => $vlan->id, 'gateway' => '10.10.10.1']);
        $external = $this->on($this->office, '203.0.113.8/29', SubnetKind::External);
        $tunnel = $this->on(null, '172.16.255.0/31', SubnetKind::Tunnel);

        $this->address($offices, '10.10.10.1', 'fw-siege');
        $this->address($external, '203.0.113.10', 'fw-siege');
        $this->address($tunnel, '172.16.255.0', 'fw-siege');
        $this->address($offices, '10.10.10.20', 'srv-erp');
        $this->address($external, '203.0.113.11', 'srv-erp');
        $this->address($offices, '10.10.10.30', 'srv-atelier');
        $this->address($offices, '10.10.10.100');
        $this->carry('sw-coeur', ['1/0/1'], $vlan, PortMode::Access, false);
        $this->aggregateCarrying('sw-coeur', ['1/0/5', '1/0/6'], $vlan, PortMode::Trunk, true);
        $this->carry('fw-atelier', ['1/0/1'], $vlan, PortMode::Trunk, true);

        $few = $cost();

        // Placed by hand, the internet: the layout is read with the page.
        DiagramPosition::query()->create(['site_id' => $this->office->id, 'view' => DiagramView::Logical, 'node' => DiagramNode::Internet, 'x' => 0, 'y' => 0]);

        $this->assertSame($few, $cost());

        // Then fifty subnets, a hundred and fifty devices and two thousand
        // addresses: each subnet carried by a VLAN, a switch for each,
        // which carries it, and two servers on two subnets each.
        $stamps = ['created_at' => now(), 'updated_at' => now()];
        $addresses = [];

        foreach (range(1, 47) as $number) {
            $vlan = $this->vlan(100 + $number, 'Réseau '.$number);
            $subnet = $this->on($this->office, '10.30.'.$number.'.0/24', attributes: ['vlan_id' => $vlan->id, 'gateway' => '10.30.'.$number.'.1']);

            $this->device('sw-volume-'.$number, DeviceType::Switch);
            $this->carry('sw-volume-'.$number, ['1/0/1', '1/0/2'], $vlan, PortMode::Access, false);

            foreach (range(10, 51) as $host) {
                $addresses[] = ['subnet_id' => $subnet->id, 'address' => inet_pton('10.30.'.$number.'.'.$host), 'device_id' => null, 'assignment' => 'static', 'status' => 'active', ...$stamps];
            }
        }

        foreach (range(1, 98) as $number) {
            $server = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'srv-volume-'.$number, 'type' => DeviceType::Server]);

            foreach ([1, 2] as $side) {
                $addresses[] = ['subnet_id' => Subnet::query()->where('network', inet_pton('10.30.'.(($number + $side) % 47 + 1).'.0'))->value('id'), 'address' => inet_pton('10.30.'.(($number + $side) % 47 + 1).'.'.(100 + $number)), 'device_id' => $server->id, 'assignment' => 'static', 'status' => 'active', ...$stamps];
            }
        }

        foreach (array_chunk($addresses, 500) as $chunk) {
            DB::table('ip_addresses')->insert($chunk);
        }

        $diagram = $this->diagram();

        $this->assertSame(50, $diagram['summary']['subnets']);
        $this->assertSame(150, $diagram['summary']['devices']);
        $this->assertGreaterThanOrEqual(2000, IpAddress::query()->count());

        $this->assertSame($few, $cost());

        // All of them placed by hand: as many queries as with one.
        DiagramPosition::query()->insert(array_map(
            fn (array $node, int $index): array => [
                'site_id' => $this->office->id, 'view' => 'logical', 'node' => $node['kind'],
                'device_id' => $node['kind'] === 'device' ? $node['id'] : null,
                'subnet_id' => $node['kind'] === 'subnet' ? $node['id'] : null,
                'x' => 8 * $index, 'y' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                ...array_map(fn (array $subnet): array => ['kind' => 'subnet', 'id' => $subnet['id']], $diagram['subnets']),
                ...array_map(fn (array $device): array => ['kind' => 'device', 'id' => $device['id']], $diagram['devices']),
            ],
            range(1, 200),
        ));

        $this->assertSame(201, DiagramPosition::query()->count());
        $this->assertSame($few, $cost());
    }
}
