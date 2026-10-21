<?php

// torsade/tests/Feature/Network/LinkDeviationTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Link;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\Vlan;
use App\Services\Network\LinkDeviations;
use Database\Factories\LinkFactory;
use Database\Factories\PortFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The eight ways the two ends of a link can disagree, worked out case by
 * case on the effective configuration of each end: the one of its port, or
 * of the aggregate of its port. Each one comes with its reason in words,
 * is stored nowhere and refuses nothing.
 */
class LinkDeviationTest extends NetworkTestCase
{
    private LinkDeviations $deviations;

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

        $this->deviations = app(LinkDeviations::class);
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->workshopSite = Site::factory()->create(['name' => 'Atelier']);

        foreach ([1, 10, 20, 30, 40, 99] as $vid) {
            $this->vlans[$this->office->id][$vid] = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => $vid, 'name' => 'VLAN '.$vid]);
        }

        // The same numbers on another site are other VLANs.
        foreach ([10, 30, 99] as $vid) {
            $this->vlans[$this->workshopSite->id][$vid] = Vlan::factory()->create(['site_id' => $this->workshopSite->id, 'vid' => $vid, 'name' => 'Atelier '.$vid]);
        }

        foreach ([
            ['sw-coeur-01', $this->office, DeviceType::Switch, [8]],
            ['sw-coeur-02', $this->office, DeviceType::Switch, [4]],
            ['sw-etage-1', $this->office, DeviceType::Switch, [8]],
            // A stack of two members.
            ['sw-serveurs', $this->office, DeviceType::Switch, [4, 4]],
            ['fw-siege', $this->office, DeviceType::Firewall, [2]],
            ['sw-atelier', $this->workshopSite, DeviceType::Switch, [4]],
        ] as [$name, $site, $type, $members]) {
            $device = Device::factory()->create(['site_id' => $site->id, 'name' => $name, 'type' => $type]);

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
     * Give a mode and VLANs, by their numbers, to a port or to an
     * aggregate: the VLANs are those of the site of its device.
     *
     * @param  list<int>  $tagged
     */
    private function carry(Port|PortAggregate $holder, ?PortMode $mode, ?int $untagged = null, array $tagged = []): void
    {
        $holder->portVlans()->delete();
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

    /**
     * The VLAN of a site with a number, created when a test needs one more.
     */
    private function vlan(int $site, int $vid): Vlan
    {
        return $this->vlans[$site][$vid] ??= Vlan::factory()->create(['site_id' => $site, 'vid' => $vid, 'name' => 'VLAN '.$vid]);
    }

    /**
     * An aggregate of a device, over some of its ports.
     *
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
     * A link between two ports, each given as "device name".
     *
     * @param  array{0: string, 1: string}  $first
     * @param  array{0: string, 1: string}  $second
     */
    private function link(array $first, array $second, Status $status = Status::Active): Link
    {
        return LinkFactory::between($this->port(...$first), $this->port(...$second), ['status' => $status]);
    }

    /**
     * Every link, read the way a page reads them.
     *
     * @return Collection<int, Link>
     */
    private function everyLink(): Collection
    {
        return Link::query()->with(LinkDeviations::RELATIONS)->orderBy('id')->get();
    }

    /**
     * The deviations of a link among all the links, as [kind, reason],
     * seen from the page of a device or from nowhere in particular.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function of(Link $link, ?string $from = null): array
    {
        $found = $this->deviations->ofLinks($this->everyLink(), $from === null ? null : $this->devices[$from]->id);

        return array_map(fn (array $deviation): array => [$deviation['kind'], $deviation['reason']], $found[$link->id]);
    }

    /**
     * The deviations of an aggregate among all the links, as [kind,
     * reason].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function ofAggregate(PortAggregate $aggregate, ?string $from = null): array
    {
        $found = $this->deviations->ofAggregates($this->everyLink(), $from === null ? null : $this->devices[$from]->id);

        return array_map(fn (array $deviation): array => [$deviation['kind'], $deviation['reason']], $found[$aggregate->id] ?? []);
    }

    // --- Nothing to tell ----------------------------------------------------------

    public function test_two_ends_that_agree_have_no_deviation(): void
    {
        // Two access ports on the same VLAN.
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Access, 10);
        $this->carry($this->port('sw-etage-1', '1/0/1'), PortMode::Access, 10);
        $access = $this->link(['sw-coeur-01', '1/0/1'], ['sw-etage-1', '1/0/1']);

        // Two trunks with the same native VLAN and the same tagged VLANs,
        // whatever the order they were ticked in.
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 99, [10, 20, 30]);
        $this->carry($this->port('sw-etage-1', '1/0/2'), PortMode::Trunk, 99, [30, 10, 20]);
        $trunk = $this->link(['sw-coeur-01', '1/0/2'], ['sw-etage-1', '1/0/2']);

        // Two trunks without a native VLAN, and two that tag nothing.
        $this->carry($this->port('sw-coeur-01', '1/0/3'), PortMode::Trunk, null, [10]);
        $this->carry($this->port('sw-etage-1', '1/0/3'), PortMode::Trunk, null, [10]);
        $noNative = $this->link(['sw-coeur-01', '1/0/3'], ['sw-etage-1', '1/0/3']);

        $this->carry($this->port('sw-coeur-01', '1/0/4'), PortMode::Trunk);
        $this->carry($this->port('sw-etage-1', '1/0/4'), PortMode::Trunk);
        $bare = $this->link(['sw-coeur-01', '1/0/4'], ['sw-etage-1', '1/0/4']);

        // Two ports without a mode.
        $none = $this->link(['sw-coeur-01', '1/0/5'], ['sw-etage-1', '1/0/5']);

        foreach ([$access, $trunk, $noNative, $bare, $none] as $link) {
            $this->assertSame([], $this->of($link));
            $this->assertSame([], $this->of($link, 'sw-etage-1'));
        }
    }

    // --- 1: access facing a trunk ---------------------------------------------------

    public function test_an_access_port_facing_a_trunk_is_a_deviation_that_names_the_access_side(): void
    {
        $this->carry($this->port('sw-etage-1', '1/0/8'), PortMode::Access, 10);
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10, 20]);
        $link = $this->link(['sw-etage-1', '1/0/8'], ['sw-coeur-01', '1/0/1']);

        $expected = [['link_modes', "Accès d'un côté (sw-etage-1 › 1/0/8), trunk de l'autre"]];

        // The same words on the link, on the page of each of its devices.
        $this->assertSame($expected, $this->of($link));
        $this->assertSame($expected, $this->of($link, 'sw-etage-1'));
        $this->assertSame($expected, $this->of($link, 'sw-coeur-01'));

        // Whichever end the access port is.
        $this->carry($this->port('sw-etage-1', '1/0/7'), PortMode::Trunk, 99, [10]);
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Access, 20);
        $other = $this->link(['sw-etage-1', '1/0/7'], ['sw-coeur-01', '1/0/2']);

        $this->assertSame([['link_modes', "Accès d'un côté (sw-coeur-01 › 1/0/2), trunk de l'autre"]], $this->of($other));

        // Their VLANs are not compared: they do not mean the same thing.
        $this->assertCount(1, $this->of($link));
    }

    // --- 2: two access ports on different VLANs -------------------------------------

    public function test_two_access_ports_on_different_vlans_are_a_deviation(): void
    {
        $this->carry($this->port('sw-etage-1', '1/0/1'), PortMode::Access, 10);
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Access, 20);
        $link = $this->link(['sw-etage-1', '1/0/1'], ['sw-coeur-01', '1/0/1']);

        $this->assertSame([['link_access_vlans', "VLAN 10 d'un côté, 20 de l'autre"]], $this->of($link));
        // Seen from the other device, its own side comes first.
        $this->assertSame([['link_access_vlans', "VLAN 20 d'un côté, 10 de l'autre"]], $this->of($link, 'sw-coeur-01'));
        $this->assertSame([['link_access_vlans', "VLAN 10 d'un côté, 20 de l'autre"]], $this->of($link, 'sw-etage-1'));

        // The voice VLAN of an access port is not what the link is on.
        $this->carry($this->port('sw-etage-1', '1/0/2'), PortMode::Access, 10, [30]);
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Access, 10);
        $voice = $this->link(['sw-etage-1', '1/0/2'], ['sw-coeur-01', '1/0/2']);

        $this->assertSame([], $this->of($voice));
    }

    // --- 3: two trunks with different native VLANs ----------------------------------

    public function test_two_trunks_with_different_native_vlans_are_a_deviation(): void
    {
        $this->carry($this->port('sw-etage-1', '1/0/1'), PortMode::Trunk, 99, [10, 20]);
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 1, [10, 20]);
        $link = $this->link(['sw-etage-1', '1/0/1'], ['sw-coeur-01', '1/0/1']);

        $this->assertSame([['link_native_vlans', "Natif 99 d'un côté, 1 de l'autre"]], $this->of($link));
        $this->assertSame([['link_native_vlans', "Natif 1 d'un côté, 99 de l'autre"]], $this->of($link, 'sw-coeur-01'));

        // A native VLAN on one side only: the side that has it is named.
        $this->carry($this->port('sw-etage-1', '1/0/2'), PortMode::Trunk, null, [10]);
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 99, [10]);
        $missing = $this->link(['sw-etage-1', '1/0/2'], ['sw-coeur-01', '1/0/2']);

        $expected = [['link_native_vlans', "Natif 99 d'un côté (sw-coeur-01 › 1/0/2), aucun de l'autre"]];

        $this->assertSame($expected, $this->of($missing));
        $this->assertSame($expected, $this->of($missing, 'sw-coeur-01'));
        $this->assertSame($expected, $this->of($missing, 'sw-etage-1'));
    }

    // --- 4: two trunks that do not tag the same VLANs -------------------------------

    public function test_two_trunks_that_do_not_tag_the_same_vlans_are_a_deviation(): void
    {
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10, 20, 30]);
        $this->carry($this->port('sw-etage-1', '1/0/1'), PortMode::Trunk, 99, [10, 20]);
        $link = $this->link(['sw-etage-1', '1/0/1'], ['sw-coeur-01', '1/0/1']);

        $expected = [['link_tagged_vlans', 'VLAN 30 seulement sur sw-coeur-01']];

        $this->assertSame($expected, $this->of($link));
        $this->assertSame($expected, $this->of($link, 'sw-etage-1'));
        $this->assertSame($expected, $this->of($link, 'sw-coeur-01'));

        // VLANs missing on each side are told together, each side with its
        // own, and with a native VLAN that differs as well.
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 1, [10, 30, 40]);
        $this->carry($this->port('sw-etage-1', '1/0/2'), PortMode::Trunk, 99, [10, 20]);
        $both = $this->link(['sw-etage-1', '1/0/2'], ['sw-coeur-01', '1/0/2']);

        $this->assertSame(
            [
                ['link_native_vlans', "Natif 99 d'un côté, 1 de l'autre"],
                ['link_tagged_vlans', 'VLAN 20 seulement sur sw-etage-1 ; VLAN 30, 40 seulement sur sw-coeur-01'],
            ],
            $this->of($both),
        );

        // A VLAN that is native on one side and tagged on the other is not
        // carried the same way: both deviations say so.
        $this->carry($this->port('sw-coeur-01', '1/0/3'), PortMode::Trunk, 99, [10]);
        $this->carry($this->port('sw-etage-1', '1/0/3'), PortMode::Trunk, null, [10, 99]);
        $mixed = $this->link(['sw-etage-1', '1/0/3'], ['sw-coeur-01', '1/0/3']);

        $this->assertSame(
            [
                ['link_native_vlans', "Natif 99 d'un côté (sw-coeur-01 › 1/0/3), aucun de l'autre"],
                ['link_tagged_vlans', 'VLAN 99 seulement sur sw-etage-1'],
            ],
            $this->of($mixed),
        );
    }

    public function test_a_reason_names_ten_vlans_at_most(): void
    {
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10, ...range(101, 112)]);
        $this->carry($this->port('sw-etage-1', '1/0/1'), PortMode::Trunk, 99, [10]);
        $link = $this->link(['sw-etage-1', '1/0/1'], ['sw-coeur-01', '1/0/1']);

        $this->assertSame(
            [['link_tagged_vlans', 'VLAN 101, 102, 103, 104, 105, 106, 107, 108, 109, 110 et 2 de plus seulement sur sw-coeur-01']],
            $this->of($link),
        );

        // Exactly ten are all named.
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 99, range(101, 110));
        $this->carry($this->port('sw-etage-1', '1/0/2'), PortMode::Trunk, 99);
        $ten = $this->link(['sw-etage-1', '1/0/2'], ['sw-coeur-01', '1/0/2']);

        $this->assertSame([['link_tagged_vlans', 'VLAN 101, 102, 103, 104, 105, 106, 107, 108, 109, 110 seulement sur sw-coeur-01']], $this->of($ten));
    }

    // --- The effective configuration -------------------------------------------------

    public function test_an_end_in_an_aggregate_is_compared_by_the_configuration_of_its_aggregate(): void
    {
        $uplink = $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']);
        $this->carry($uplink, PortMode::Trunk, 99, [10, 20]);

        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10]);
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 99, [10, 20]);

        $first = $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/1']);
        $second = $this->link(['sw-serveurs', '2/0/4'], ['sw-coeur-01', '1/0/2']);

        // The port of the aggregate has neither a mode nor a VLAN of its
        // own: the trunk of Po1 is what the core switch faces.
        $this->assertNull($this->port('sw-serveurs', '1/0/4')->mode);
        $this->assertSame('link_tagged_vlans', $this->of($first)[0][0]);
        $this->assertSame('VLAN 20 seulement sur sw-serveurs', $this->of($first)[0][1]);
        // The second link agrees on VLANs; both face ports that are not an
        // aggregate, which is told apart.
        $this->assertSame(['aggregate_one_sided'], array_column($this->of($second), 0));

        // An access aggregate facing a trunk is named as what holds the
        // mode: the aggregate, not one of its ports.
        $servers = $this->aggregate('sw-serveurs', 'Po2', ['1/0/1', '2/0/1']);
        $this->carry($servers, PortMode::Access, 20);
        $this->carry($this->port('sw-coeur-01', '1/0/3'), PortMode::Trunk, 99, [20]);
        $third = $this->link(['sw-coeur-01', '1/0/3'], ['sw-serveurs', '1/0/1']);

        $this->assertSame(['link_modes', "Accès d'un côté (sw-serveurs › Po2), trunk de l'autre"], $this->of($third)[0]);

        // Two aggregates that agree, facing each other: nothing to tell.
        $core = $this->aggregate('sw-coeur-01', 'Po1', ['1/0/7', '1/0/8']);
        $this->carry($core, PortMode::Trunk, 99, [10, 20, 30]);
        $back = $this->aggregate('sw-etage-1', 'Po1', ['1/0/7', '1/0/8']);
        $this->carry($back, PortMode::Trunk, 99, [10, 20, 30]);

        $fourth = $this->link(['sw-coeur-01', '1/0/7'], ['sw-etage-1', '1/0/7']);
        $fifth = $this->link(['sw-coeur-01', '1/0/8'], ['sw-etage-1', '1/0/8']);

        $this->assertSame([], $this->of($fourth));
        $this->assertSame([], $this->of($fifth));

        // Changed on one aggregate, the deviation is on each of its links.
        $this->carry($back, PortMode::Trunk, 99, [10, 20]);

        $expected = [['link_tagged_vlans', 'VLAN 30 seulement sur sw-coeur-01']];

        $this->assertSame($expected, $this->of($fourth));
        $this->assertSame($expected, $this->of($fifth));
    }

    public function test_an_end_without_a_mode_is_not_compared(): void
    {
        // The port of a firewall has no mode: whatever the switch carries,
        // nothing is expected of the firewall.
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10, 20, 30]);
        $trunk = $this->link(['fw-siege', '1/0/1'], ['sw-coeur-01', '1/0/1']);

        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Access, 10);
        $access = $this->link(['sw-coeur-01', '1/0/2'], ['fw-siege', '1/0/2']);

        foreach ([$trunk, $access] as $link) {
            $this->assertSame([], $this->of($link));
            $this->assertSame([], $this->of($link, 'fw-siege'));
            $this->assertSame([], $this->of($link, 'sw-coeur-01'));
        }

        // An aggregate without a mode is not compared either.
        $bare = $this->aggregate('sw-serveurs', 'Po1', ['1/0/1']);
        $facing = $this->aggregate('sw-coeur-01', 'Po9', ['1/0/3']);
        $this->carry($facing, PortMode::Trunk, 99, [10]);
        $aggregated = $this->link(['sw-serveurs', '1/0/1'], ['sw-coeur-01', '1/0/3']);

        $this->assertNull($bare->mode);
        $this->assertSame([], $this->of($aggregated));

        // What does not compare configurations is still told: a port shut
        // down under an active link.
        $this->port('fw-siege', '1/0/1')->update(['enabled' => false]);

        $this->assertSame([['link_on_disabled_port', 'fw-siege › 1/0/1 est désactivé']], $this->of($trunk));
    }

    public function test_a_link_between_two_sites_is_compared_by_the_numbers_then_by_the_vlans_themselves(): void
    {
        // The VLANs 10, 30 and 99 of the two sites are different VLANs
        // with the same numbers: the numbers agree, the link merges the
        // networks of the two sites.
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10, 30]);
        $this->carry($this->port('sw-atelier', '1/0/1'), PortMode::Trunk, 99, [10, 30]);
        $merged = $this->link(['sw-coeur-01', '1/0/1'], ['sw-atelier', '1/0/1']);

        $this->assertNotSame($this->vlans[$this->office->id][10]->id, $this->vlans[$this->workshopSite->id][10]->id);
        $this->assertSame(
            [
                ['link_merges_vlans', "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux"],
                ['link_merges_vlans', "VLAN 30 du site Siège d'un côté, VLAN 30 du site Atelier de l'autre : la liaison fusionne deux réseaux"],
                ['link_merges_vlans', "VLAN 99 du site Siège d'un côté, VLAN 99 du site Atelier de l'autre : la liaison fusionne deux réseaux"],
            ],
            $this->of($merged),
        );

        // From the workshop, its own side first.
        $this->assertSame(
            "VLAN 10 du site Atelier d'un côté, VLAN 10 du site Siège de l'autre : la liaison fusionne deux réseaux",
            $this->of($merged, 'sw-atelier')[0][1],
        );

        // What differs by number is told as before, then what is merged.
        $this->carry($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, 99, [10, 20, 30]);
        $this->carry($this->port('sw-atelier', '1/0/2'), PortMode::Trunk, 10, [30]);
        $differs = $this->link(['sw-atelier', '1/0/2'], ['sw-coeur-01', '1/0/2']);

        $this->assertSame(
            [
                ['link_native_vlans', "Natif 10 d'un côté, 99 de l'autre"],
                ['link_tagged_vlans', 'VLAN 10, 20 seulement sur sw-coeur-01'],
                ['link_merges_vlans', "VLAN 10 du site Atelier d'un côté, VLAN 10 du site Siège de l'autre : la liaison fusionne deux réseaux"],
                ['link_merges_vlans', "VLAN 30 du site Atelier d'un côté, VLAN 30 du site Siège de l'autre : la liaison fusionne deux réseaux"],
            ],
            $this->of($differs),
        );

        // Two access ports on 10 and 30 carry no number in common.
        $this->carry($this->port('sw-coeur-01', '1/0/4'), PortMode::Access, 10);
        $this->carry($this->port('sw-atelier', '1/0/4'), PortMode::Access, 30);

        $this->assertSame(
            [['link_access_vlans', "VLAN 10 d'un côté, 30 de l'autre"]],
            $this->of($this->link(['sw-coeur-01', '1/0/4'], ['sw-atelier', '1/0/4'])),
        );
    }

    // --- 8: two VLANs merged under one number ---------------------------------------

    /**
     * Give a mode and VLANs of any site to a port or to an aggregate.
     *
     * @param  list<Vlan>  $tagged
     */
    private function carryVlans(Port|PortAggregate $holder, PortMode $mode, ?Vlan $untagged, array $tagged = []): void
    {
        $holder->portVlans()->delete();
        $holder->mode = $mode;
        $holder->save();

        $key = $holder instanceof Port ? 'port_id' : 'port_aggregate_id';

        foreach ([...($untagged === null ? [] : [[$untagged, false]]), ...array_map(fn (Vlan $vlan): array => [$vlan, true], $tagged)] as [$vlan, $isTagged]) {
            PortVlan::factory()->create([$key => $holder->id, 'vlan_id' => $vlan->id, 'tagged' => $isTagged]);
        }
    }

    public function test_two_vlans_under_one_number_are_merged_however_each_end_carries_them(): void
    {
        $office = fn (int $vid): Vlan => $this->vlans[$this->office->id][$vid];
        $workshop = fn (int $vid): Vlan => $this->vlans[$this->workshopSite->id][$vid];
        $reason = fn (int $vid): string => "VLAN {$vid} du site Siège d'un côté, VLAN {$vid} du site Atelier de l'autre : la liaison fusionne deux réseaux";

        // In access.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/1'), PortMode::Access, $office(10));
        $this->carryVlans($this->port('sw-etage-1', '1/0/1'), PortMode::Access, $workshop(10));

        $this->assertSame([['link_merges_vlans', $reason(10)]], $this->of($this->link(['sw-coeur-01', '1/0/1'], ['sw-etage-1', '1/0/1'])));

        // As the native VLAN.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/2'), PortMode::Trunk, $office(99), [$office(10)]);
        $this->carryVlans($this->port('sw-etage-1', '1/0/2'), PortMode::Trunk, $workshop(99), [$office(10)]);

        $this->assertSame([['link_merges_vlans', $reason(99)]], $this->of($this->link(['sw-coeur-01', '1/0/2'], ['sw-etage-1', '1/0/2'])));

        // Tagged.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/3'), PortMode::Trunk, null, [$office(10), $office(30)]);
        $this->carryVlans($this->port('sw-etage-1', '1/0/3'), PortMode::Trunk, null, [$office(10), $workshop(30)]);

        $this->assertSame([['link_merges_vlans', $reason(30)]], $this->of($this->link(['sw-coeur-01', '1/0/3'], ['sw-etage-1', '1/0/3'])));

        // For the voice, which two access ports do not compare by number.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/4'), PortMode::Access, $office(10), [$office(30)]);
        $this->carryVlans($this->port('sw-etage-1', '1/0/4'), PortMode::Access, $office(10), [$workshop(30)]);

        $this->assertSame([['link_merges_vlans', $reason(30)]], $this->of($this->link(['sw-coeur-01', '1/0/4'], ['sw-etage-1', '1/0/4'])));

        // Whatever the modes: an access port facing a trunk that tags the
        // VLAN of the same number of the other site.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/5'), PortMode::Access, $office(10));
        $this->carryVlans($this->port('sw-etage-1', '1/0/5'), PortMode::Trunk, null, [$workshop(10)]);

        $this->assertSame(
            [
                ['link_modes', "Accès d'un côté (sw-coeur-01 › 1/0/5), trunk de l'autre"],
                ['link_merges_vlans', $reason(10)],
            ],
            $this->of($this->link(['sw-coeur-01', '1/0/5'], ['sw-etage-1', '1/0/5'])),
        );

        // Through an aggregate, whose configuration counts: on one side
        // only, which is told as well.
        $aggregate = $this->aggregate('sw-coeur-02', 'Po1', ['1/0/1']);
        $this->carryVlans($aggregate, PortMode::Trunk, null, [$workshop(10)]);
        $this->carryVlans($this->port('sw-etage-1', '1/0/6'), PortMode::Trunk, null, [$office(10)]);

        $this->assertSame(
            [
                ['link_merges_vlans', $reason(10)],
                ['aggregate_one_sided', 'sw-coeur-02 › Po1 : en face, ports hors agrégat sur sw-etage-1'],
            ],
            $this->of($this->link(['sw-etage-1', '1/0/6'], ['sw-coeur-02', '1/0/1'])),
        );

        // Without a mode at one end, nothing is compared.
        $this->carry($this->port('sw-etage-1', '1/0/7'), null);
        $this->carryVlans($this->port('sw-coeur-01', '1/0/7'), PortMode::Access, $workshop(10));

        $this->assertSame([], $this->of($this->link(['sw-coeur-01', '1/0/7'], ['sw-etage-1', '1/0/7'])));
    }

    public function test_the_same_vlan_of_another_site_at_both_ends_merges_nothing(): void
    {
        $workshop = fn (int $vid): Vlan => $this->vlans[$this->workshopSite->id][$vid];

        // A switch of the head office carries the VLAN 30 of the workshop,
        // as the switch of the workshop does.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, null, [$workshop(30)]);
        $this->carryVlans($this->port('sw-atelier', '1/0/1'), PortMode::Trunk, null, [$workshop(30)]);

        $this->assertSame([], $this->of($this->link(['sw-coeur-01', '1/0/1'], ['sw-atelier', '1/0/1'])));

        // Two ports of the head office on the VLAN 10 of the workshop.
        $this->carryVlans($this->port('sw-coeur-01', '1/0/2'), PortMode::Access, $workshop(10));
        $this->carryVlans($this->port('sw-etage-1', '1/0/2'), PortMode::Access, $workshop(10));

        $this->assertSame([], $this->of($this->link(['sw-coeur-01', '1/0/2'], ['sw-etage-1', '1/0/2'])));
    }

    // --- 5: an aggregate that leads to several devices ------------------------------

    public function test_an_aggregate_whose_links_lead_to_several_devices_is_a_deviation_of_the_aggregate(): void
    {
        $aggregate = $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4', '2/0/3']);
        $this->aggregate('sw-coeur-01', 'Po1', ['1/0/1']);
        $this->aggregate('sw-coeur-02', 'Po1', ['1/0/1']);

        $first = $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/1']);

        // One device: nothing to tell.
        $this->assertSame([], $this->ofAggregate($aggregate, 'sw-serveurs'));

        $second = $this->link(['sw-coeur-02', '1/0/1'], ['sw-serveurs', '2/0/4']);

        // On the page of its device, the aggregate goes by its name; away
        // from it, with its device.
        $this->assertSame([['aggregate_several_devices', 'Po1 mène à sw-coeur-01 et sw-coeur-02']], $this->ofAggregate($aggregate, 'sw-serveurs'));
        $this->assertSame([['aggregate_several_devices', 'sw-serveurs › Po1 mène à sw-coeur-01 et sw-coeur-02']], $this->ofAggregate($aggregate));

        // It is a deviation of the aggregate, not of its links: each of
        // them faces one aggregate, as it should.
        $this->assertSame([], $this->of($first));
        $this->assertSame([], $this->of($second, 'sw-serveurs'));

        // Three devices are all named.
        $this->aggregate('sw-etage-1', 'Po1', ['1/0/1']);
        $this->link(['sw-serveurs', '2/0/3'], ['sw-etage-1', '1/0/1']);

        $this->assertSame([['aggregate_several_devices', 'Po1 mène à sw-coeur-01, sw-coeur-02 et sw-etage-1']], $this->ofAggregate($aggregate, 'sw-serveurs'));

        // The aggregates of the other end each lead to one device.
        $this->assertSame([], $this->ofAggregate(PortAggregate::query()->where('device_id', $this->devices['sw-coeur-01']->id)->sole()));
    }

    // --- 6: an aggregate on one side only -------------------------------------------

    public function test_an_aggregate_that_faces_ports_outside_any_aggregate_is_a_deviation(): void
    {
        $aggregate = $this->aggregate('sw-coeur-01', 'Po1', ['1/0/1', '1/0/2']);

        $first = $this->link(['sw-coeur-01', '1/0/1'], ['sw-serveurs', '1/0/4']);
        $second = $this->link(['sw-serveurs', '2/0/4'], ['sw-coeur-01', '1/0/2']);

        // On the aggregate, on each of its links, and on both devices.
        $this->assertSame([['aggregate_one_sided', 'Po1 : en face, ports hors agrégat sur sw-serveurs']], $this->ofAggregate($aggregate, 'sw-coeur-01'));

        foreach ([$first, $second] as $link) {
            $this->assertSame([['aggregate_one_sided', 'Po1 : en face, ports hors agrégat sur sw-serveurs']], $this->of($link, 'sw-coeur-01'));
            $this->assertSame([['aggregate_one_sided', 'sw-coeur-01 › Po1 : en face, ports hors agrégat sur sw-serveurs']], $this->of($link, 'sw-serveurs'));
            $this->assertSame([['aggregate_one_sided', 'sw-coeur-01 › Po1 : en face, ports hors agrégat sur sw-serveurs']], $this->of($link));
        }

        // One of the two opposite ports in an aggregate is not enough.
        $half = $this->aggregate('sw-serveurs', 'Po1', ['1/0/4']);

        $this->assertSame([['aggregate_one_sided', 'Po1 : en face, ports hors agrégat sur sw-serveurs']], $this->ofAggregate($aggregate, 'sw-coeur-01'));
        // The opposite aggregate faces one aggregate: it has nothing to tell.
        $this->assertSame([], $this->ofAggregate($half, 'sw-serveurs'));

        // Both opposite ports in the same aggregate: the aggregated link.
        $this->port('sw-serveurs', '2/0/4')->update(['port_aggregate_id' => $half->id]);

        $this->assertSame([], $this->ofAggregate($aggregate, 'sw-coeur-01'));
        $this->assertSame([], $this->of($first));
        $this->assertSame([], $this->of($second));
    }

    public function test_an_aggregate_that_faces_several_aggregates_of_a_device_is_a_deviation(): void
    {
        $aggregate = $this->aggregate('sw-coeur-01', 'Po1', ['1/0/1', '1/0/2']);
        $this->aggregate('sw-serveurs', 'Po2', ['2/0/4']);
        $this->aggregate('sw-serveurs', 'Po1', ['1/0/4']);

        $first = $this->link(['sw-coeur-01', '1/0/1'], ['sw-serveurs', '1/0/4']);
        $this->link(['sw-coeur-01', '1/0/2'], ['sw-serveurs', '2/0/4']);

        $this->assertSame(
            [['aggregate_one_sided', 'Po1 : en face, plusieurs agrégats sur sw-serveurs (Po1, Po2)']],
            $this->ofAggregate($aggregate, 'sw-coeur-01'),
        );
        $this->assertSame(
            [['aggregate_one_sided', 'sw-coeur-01 › Po1 : en face, plusieurs agrégats sur sw-serveurs (Po1, Po2)']],
            $this->of($first, 'sw-serveurs'),
        );
    }

    public function test_a_port_outside_any_aggregate_facing_an_aggregate_is_told_from_both_ends(): void
    {
        // Two aggregates face each other, and each has one more link to a
        // port of the other device that is in no aggregate.
        $near = $this->aggregate('sw-coeur-01', 'Po1', ['1/0/1', '1/0/2']);
        $far = $this->aggregate('sw-etage-1', 'Po1', ['1/0/1', '1/0/2']);

        $paired = $this->link(['sw-coeur-01', '1/0/1'], ['sw-etage-1', '1/0/1']);
        $this->link(['sw-coeur-01', '1/0/2'], ['sw-etage-1', '1/0/5']);
        $this->link(['sw-coeur-01', '1/0/5'], ['sw-etage-1', '1/0/2']);

        $this->assertSame([['aggregate_one_sided', 'Po1 : en face, ports hors agrégat sur sw-etage-1']], $this->ofAggregate($near, 'sw-coeur-01'));
        $this->assertSame([['aggregate_one_sided', 'Po1 : en face, ports hors agrégat sur sw-coeur-01']], $this->ofAggregate($far, 'sw-etage-1'));

        // The link between the two aggregates is told by each of them.
        $this->assertSame(
            [
                ['aggregate_one_sided', 'Po1 : en face, ports hors agrégat sur sw-etage-1'],
                ['aggregate_one_sided', 'sw-etage-1 › Po1 : en face, ports hors agrégat sur sw-coeur-01'],
            ],
            $this->of($paired, 'sw-coeur-01'),
        );
    }

    // --- 7: an active link on a port shut down --------------------------------------

    public function test_an_active_link_on_a_port_that_is_shut_down_is_a_deviation(): void
    {
        $this->port('sw-etage-1', '1/0/8')->update(['enabled' => false]);
        $link = $this->link(['sw-etage-1', '1/0/8'], ['sw-coeur-01', '1/0/1']);

        // On the page of its device, the port goes by its name; away from
        // it, with its device.
        $this->assertSame([['link_on_disabled_port', '1/0/8 est désactivé']], $this->of($link, 'sw-etage-1'));
        $this->assertSame([['link_on_disabled_port', 'sw-etage-1 › 1/0/8 est désactivé']], $this->of($link, 'sw-coeur-01'));
        $this->assertSame([['link_on_disabled_port', 'sw-etage-1 › 1/0/8 est désactivé']], $this->of($link));

        // Both ports shut down are told together.
        $this->port('sw-coeur-01', '1/0/1')->update(['enabled' => false]);

        $this->assertSame([['link_on_disabled_port', '1/0/8 et sw-coeur-01 › 1/0/1 sont désactivés']], $this->of($link, 'sw-etage-1'));
        $this->assertSame([['link_on_disabled_port', 'sw-etage-1 › 1/0/8 et sw-coeur-01 › 1/0/1 sont désactivés']], $this->of($link));

        // A link that is planned, or retired, says nothing of what is
        // plugged in today.
        foreach ([Status::Planned, Status::Retired] as $status) {
            $link->update(['status' => $status]);

            $this->assertSame([], $this->of($link));
        }

        // It comes with the other deviations of the link.
        $link->update(['status' => Status::Active]);
        $this->carry($this->port('sw-etage-1', '1/0/8'), PortMode::Access, 10);
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Access, 20);

        $this->assertSame(['link_access_vlans', 'link_on_disabled_port'], array_column($this->of($link), 0));
    }

    // --- Never stored, never a query ------------------------------------------------

    public function test_deviations_are_worked_out_without_a_query_and_stored_nowhere(): void
    {
        $this->carry($this->port('sw-coeur-01', '1/0/1'), PortMode::Trunk, 99, [10, 20, 30]);
        $this->carry($this->port('sw-etage-1', '1/0/1'), PortMode::Trunk, 1, [10]);
        $this->aggregate('sw-serveurs', 'Po1', ['1/0/4', '2/0/4']);

        $this->link(['sw-etage-1', '1/0/1'], ['sw-coeur-01', '1/0/1']);
        $this->link(['sw-serveurs', '1/0/4'], ['sw-coeur-01', '1/0/2']);
        $this->link(['sw-serveurs', '2/0/4'], ['sw-coeur-02', '1/0/1']);

        $links = $this->everyLink();

        DB::enableQueryLog();

        $ofLinks = $this->deviations->ofLinks($links, $this->devices['sw-coeur-01']->id);
        $ofAggregates = $this->deviations->ofAggregates($links);

        $this->assertSame([], DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame(4, array_sum(array_map(count(...), $ofLinks)));
        $this->assertSame(3, array_sum(array_map(count(...), $ofAggregates)));

        // No table has a column to keep a deviation in.
        foreach (['links', 'link_ends', 'ports', 'port_aggregates'] as $table) {
            $this->assertSame([], array_values(array_filter(Schema::getColumnListing($table), fn (string $column): bool => str_contains($column, 'deviation'))));
        }
    }
}
