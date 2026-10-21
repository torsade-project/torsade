<?php

// torsade/tests/Concerns/BuildsRouteUris.php

namespace Tests\Concerns;

use App\Models\AddressBlock;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\JackCordFactory;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Illuminate\Routing\Route as RoutingRoute;

/**
 * For the tests that walk through every registered route: a real address
 * for each of them, with an existing object behind every route parameter.
 */
trait BuildsRouteUris
{
    /**
     * @var array<string, string>|null
     */
    private ?array $routeParameters = null;

    /**
     * @param  User  $account  The account behind the "{user}" parameter.
     */
    protected function uriOf(RoutingRoute $route, User $account): string
    {
        $this->routeParameters ??= $this->createRouteParameters();

        $parameters = ['{user}' => (string) $account->id, ...$this->routeParameters];

        return '/'.ltrim(str_replace(array_keys($parameters), array_values($parameters), $route->uri()), '/');
    }

    /**
     * One object of each kind of the network documentation.
     *
     * @return array<string, string>
     */
    private function createRouteParameters(): array
    {
        $site = Site::factory()->create();
        $entry = CatalogVlan::factory()->create();
        $block = AddressBlock::factory()->cidr('192.0.0.0/8')->create();
        $vlan = Vlan::factory()->create(['site_id' => $site->id]);
        $subnet = Subnet::factory()->cidr('192.0.2.0/24')->create(['vlan_id' => $vlan->id]);
        $range = IpRange::factory()->between('192.0.2.100', '192.0.2.199')->create(['subnet_id' => $subnet->id]);
        $building = Building::factory()->create(['site_id' => $site->id]);
        $floor = Floor::factory()->create(['building_id' => $building->id]);
        $room = Room::factory()->create(['floor_id' => $floor->id]);
        $device = Device::factory()->create(['site_id' => $site->id, 'room_id' => $room->id]);
        // An interface of the device: the route that detaches it finds it.
        $address = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.0.2.10', 'device_id' => $device->id]);

        $template = DeviceTemplate::factory()->switch()->create();
        $member = DeviceMember::factory()->primary()->create(['device_id' => $device->id, 'device_template_id' => $template->id]);
        $port = Port::factory()->create(['device_member_id' => $member->id, 'name' => '1/0/1', 'position' => 1]);
        $aggregate = PortAggregate::factory()->create(['device_id' => $device->id, 'name' => 'Po1']);

        // A link between two other devices: what stands behind "{device}",
        // "{deviceMember}" and "{port}" is linked to nothing, and can still
        // be deleted.
        $link = LinkFactory::between(...array_map(
            fn (string $name): Port => Port::factory()->create([
                'device_member_id' => DeviceMember::factory()->primary()->create([
                    'device_id' => Device::factory()->create(['site_id' => $site->id, 'name' => $name])->id,
                ])->id,
                'name' => '1/0/1',
                'position' => 1,
            ]),
            ['sw-linked-a', 'sw-linked-b'],
        ));

        // A patch panel in a room of its own: the room behind "{room}"
        // holds nothing, and can still be deleted.
        $panel = PatchPanel::factory()->create(['room_id' => Room::factory()->technical()->create(['floor_id' => $floor->id])->id]);

        // A jack cabled to the first port of the panel: the routes that
        // change it or detach it from there find it.
        $jack = Jack::factory()->create(['floor_id' => $floor->id, 'patch_panel_port_id' => $panel->ports()->where('number', 1)->value('id')]);

        // A cord from the second port of the panel to a device of its own:
        // the device behind "{device}" is patched to nothing either.
        $cord = PatchCordFactory::between(
            $panel->ports()->where('number', 2)->sole(),
            Port::factory()->create([
                'device_member_id' => DeviceMember::factory()->primary()->create([
                    'device_id' => Device::factory()->create(['site_id' => $site->id, 'name' => 'sw-patched'])->id,
                ])->id,
                'name' => '1/0/1',
                'position' => 1,
            ]),
        );

        // A device of its own plugged into a second jack, cabled to the
        // third port of the panel: the routes that change the cord or take
        // it away find it, and the jack behind "{jack}" has none.
        $jackCord = JackCordFactory::between(
            Jack::factory()->create(['floor_id' => $floor->id, 'patch_panel_port_id' => $panel->ports()->where('number', 3)->value('id')]),
            Port::factory()->create([
                'device_member_id' => DeviceMember::factory()->primary()->create([
                    'device_id' => Device::factory()->create(['site_id' => $site->id, 'name' => 'ap-plugged'])->id,
                ])->id,
                'name' => '1',
                'position' => 1,
            ]),
        );

        return [
            '{site}' => (string) $site->id,
            '{catalogVlan}' => (string) $entry->id,
            '{addressBlock}' => (string) $block->id,
            '{vlan}' => (string) $vlan->id,
            '{subnet}' => (string) $subnet->id,
            '{ipRange}' => (string) $range->id,
            '{ipAddress}' => (string) $address->id,
            '{building}' => (string) $building->id,
            '{floor}' => (string) $floor->id,
            '{room}' => (string) $room->id,
            '{device}' => (string) $device->id,
            '{deviceTemplate}' => (string) $template->id,
            '{deviceMember}' => (string) $member->id,
            '{port}' => (string) $port->id,
            '{portAggregate}' => (string) $aggregate->id,
            '{link}' => (string) $link->id,
            '{patchPanel}' => (string) $panel->id,
            '{patchPanelPort}' => (string) $jack->patch_panel_port_id,
            '{jack}' => (string) $jack->id,
            '{patchCord}' => (string) $cord->id,
            '{jackCord}' => (string) $jackCord->id,
        ];
    }
}
