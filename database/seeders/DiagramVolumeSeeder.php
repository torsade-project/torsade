<?php

// torsade/database/seeders/DiagramVolumeSeeder.php

namespace Database\Seeders;

use App\Enums\DeviceType;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\JackCord;
use App\Models\Link;
use App\Models\PatchCord;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use Database\Factories\JackCordFactory;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A site of a hundred and fifty switches joined by three hundred links, for
 * the browser test that times the layout of a large diagram; when
 * DIAGRAM_VOLUME is "cabled", a site of three hundred end devices cabled
 * through the patch panels to twenty-five switches, half plugged into
 * their jacks, half by their addresses; then, when it is "remove", these
 * sites taken away again, so that the tests that follow find the
 * demonstration network as it was.
 *
 * It only runs in the testing environment. The default seeder never calls
 * it.
 */
class DiagramVolumeSeeder extends Seeder
{
    public const string SITE = 'Volume du schéma';

    public const int DEVICES = 150;

    public const string CABLED_SITE = 'Volume du câblage';

    public const int END_DEVICES = 300;

    /** Each switch of the cabled site, its ports patched to as many jacks. */
    private const int SWITCH_PORTS = 12;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The data of the volume of a diagram can only be created in the testing environment.');
        }

        if (getenv('DIAGRAM_VOLUME') === 'remove') {
            $this->remove(self::SITE);
            $this->remove(self::CABLED_SITE);

            return;
        }

        if (getenv('DIAGRAM_VOLUME') === 'cabled') {
            $this->cabled();

            return;
        }

        $site = Site::factory()->create(['name' => self::SITE, 'code' => 'VOL']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Bâtiment']);
        $rooms = [];

        foreach (range(0, 2) as $level) {
            $floor = Floor::factory()->create(['building_id' => $building->id, 'level' => $level, 'name' => 'Niveau '.$level]);
            $rooms[] = Room::factory()->technical()->create(['floor_id' => $floor->id, 'name' => 'Baie '.$level]);
        }

        $names = [];
        $ports = [];

        foreach (range(1, self::DEVICES) as $number) {
            $name = sprintf('sw-volume-%03d', $number);
            $device = Device::factory()->create([
                'site_id' => $site->id,
                'room_id' => $rooms[$number % 3]->id,
                'name' => $name,
                'type' => $number <= 2 ? DeviceType::Firewall : DeviceType::Switch,
            ]);

            foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id, 'position' => 1]), 4) as $port) {
                $ports[$name][$port->name] = $port;
            }

            $names[] = $name;
        }

        // Each device to the next two: three hundred links.
        foreach ($names as $index => $name) {
            LinkFactory::between($ports[$name]['1/0/1'], $ports[$names[($index + 1) % self::DEVICES]]['1/0/2']);
            LinkFactory::between($ports[$name]['1/0/3'], $ports[$names[($index + 2) % self::DEVICES]]['1/0/4']);
        }
    }

    /**
     * The site of the end devices: one floor, a rack of four panels and
     * twenty-five switches, its jacks each patched to a port of a switch,
     * and an end device behind each jack.
     */
    private function cabled(): void
    {
        $site = Site::factory()->create(['name' => self::CABLED_SITE, 'code' => 'VCA']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Bâtiment']);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $rack = Room::factory()->technical()->create(['floor_id' => $floor->id, 'name' => 'Baie']);
        $offices = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Bureaux']);
        $subnet = Subnet::factory()->cidr('10.99.0.0/16')->create(['site_id' => $site->id]);
        $perPanel = intdiv(self::END_DEVICES, 4);
        $panels = [];

        foreach (range(1, 4) as $number) {
            $panels[] = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-'.$number, 'port_count' => $perPanel]);
        }

        $switchPorts = [];

        foreach (range(1, intdiv(self::END_DEVICES, self::SWITCH_PORTS)) as $number) {
            $switch = Device::factory()->create(['site_id' => $site->id, 'room_id' => $rack->id, 'name' => sprintf('sw-cablage-%02d', $number), 'type' => DeviceType::Switch]);

            array_push($switchPorts, ...PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $switch->id]), self::SWITCH_PORTS));
        }

        foreach (range(1, self::END_DEVICES) as $number) {
            $panelPort = PatchPanelPort::query()
                ->where('patch_panel_id', $panels[intdiv($number - 1, $perPanel)]->id)
                ->where('number', ($number - 1) % $perPanel + 1)
                ->sole();
            $jack = Jack::factory()->create(['floor_id' => $floor->id, 'label' => sprintf('V.%03d', $number), 'patch_panel_port_id' => $panelPort->id]);

            PatchCordFactory::between($panelPort, $switchPorts[$number - 1]);

            if ($number % 2 === 0) {
                $workstation = Device::factory()->create(['site_id' => $site->id, 'room_id' => $offices->id, 'name' => sprintf('pc-%03d', $number), 'type' => DeviceType::Workstation]);

                JackCordFactory::between($jack, PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $workstation->id]), 1)[0]);
            } else {
                $printer = Device::factory()->create(['site_id' => $site->id, 'room_id' => $offices->id, 'name' => sprintf('imp-%03d', $number), 'type' => DeviceType::Printer]);

                IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.99.'.intdiv($number, 250).'.'.($number % 250 + 1), 'jack_id' => $jack->id, 'device_id' => $printer->id]);
            }
        }
    }

    /**
     * A site and everything on it: its cords and its addresses first, then
     * its links, which keep their ports, its jacks and its panels, its
     * devices, its places and the site itself.
     */
    private function remove(string $name): void
    {
        $site = Site::query()->where('name', $name)->first();

        if ($site === null) {
            return;
        }

        $devices = Device::query()->select('id')->where('site_id', $site->id);
        $ports = fn (Builder $query) => $query->whereIn('device_id', $devices);
        $floors = Floor::query()->select('floors.id')->whereHas('building', fn (Builder $query) => $query->where('site_id', $site->id));

        JackCord::query()->whereHas('port', $ports)->delete();
        PatchCord::query()->whereHas('port', $ports)->delete();
        IpAddress::query()->whereHas('subnet', fn (Builder $query) => $query->where('site_id', $site->id))->delete();
        Subnet::query()->where('site_id', $site->id)->delete();
        Link::query()->whereHas('ends.port', $ports)->delete();
        Jack::query()->whereIn('floor_id', $floors)->delete();
        PatchPanel::query()->whereHas('room', fn (Builder $query) => $query->whereIn('floor_id', $floors))->delete();
        Device::query()->where('site_id', $site->id)->delete();

        foreach (Building::query()->where('site_id', $site->id)->get() as $building) {
            foreach ($building->floors()->get() as $floor) {
                $floor->rooms()->delete();
                $floor->delete();
            }

            $building->delete();
        }

        $site->delete();
    }
}
