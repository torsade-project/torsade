<?php

// torsade/tests/Feature/Network/CrossSiteVlanTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\Vlan;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * VLANs carried out of their site: a firewall of the head office that
 * routes the VLANs of the workshop, a fibre that carries them from one
 * building to the other. What a port carries names the site of such a
 * VLAN wherever it is summed up, and the activity log names it with its
 * site.
 */
class CrossSiteVlanTest extends NetworkTestCase
{
    private Site $office;

    private Site $workshop;

    /**
     * The VLANs of each site, by the name of the site, then by number.
     *
     * @var array<string, array<int, Vlan>>
     */
    private array $vlans = [];

    private Room $rack;

    private PatchPanel $panel;

    private Device $firewall;

    /**
     * The ports of the firewall, by name.
     *
     * @var array<string, Port>
     */
    private array $ports = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
        $this->workshop = Site::factory()->create(['name' => 'Atelier', 'code' => 'ATL']);
        // A site without a code is named in full.
        $depot = Site::factory()->create(['name' => 'Dépôt', 'code' => null]);

        foreach ([[$this->office, [10, 20, 99]], [$this->workshop, [10, 60, 70, 99]], [$depot, [300]]] as [$site, $vids]) {
            foreach ($vids as $vid) {
                $this->vlans[$site->name][$vid] = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid, 'name' => 'VLAN '.$vid]);
            }
        }

        $this->rack = Room::factory()->technical()->inSite($this->office)->create(['name' => 'Local technique']);
        $this->panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A', 'port_count' => 8]);
        $this->firewall = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $this->rack->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall]);

        $member = DeviceMember::factory()->primary()->create(['device_id' => $this->firewall->id, 'position' => 1]);

        foreach (PortFactory::series($member, 8) as $port) {
            $this->ports[$port->name] = $port;
        }
    }

    /**
     * A VLAN by the name of its site and its number.
     */
    private function vlan(string $site, int $vid): Vlan
    {
        return $this->vlans[$site][$vid];
    }

    /**
     * Give a port or an aggregate a mode and VLANs, as they are stored, in
     * place of those it had.
     *
     * @param  list<Vlan>  $tagged
     */
    private function carry(Port|PortAggregate $holder, PortMode $mode, ?Vlan $untagged, array $tagged = []): void
    {
        $holder->update(['mode' => $mode]);

        $owner = $holder instanceof Port ? ['port_id' => $holder->id] : ['port_aggregate_id' => $holder->id];

        PortVlan::query()->where($owner)->delete();

        foreach ([...($untagged === null ? [] : [[$untagged, false]]), ...array_map(fn (Vlan $vlan): array => [$vlan, true], $tagged)] as [$vlan, $isTagged]) {
            PortVlan::factory()->create([...$owner, 'vlan_id' => $vlan->id, 'tagged' => $isTagged]);
        }
    }

    private function summary(Port|PortAggregate $holder): ?string
    {
        return $holder->newQuery()->with('portVlans.vlan.site')->findOrFail($holder->id)->vlanSummary();
    }

    private function panelPort(int $number): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', $this->panel->id)->where('number', $number)->sole();
    }

    // --- The summary of a port ------------------------------------------------

    public function test_the_vlans_of_another_site_follow_those_of_the_site_behind_its_code_or_its_name(): void
    {
        $port = $this->ports['1/0/1'];

        $this->carry($port, PortMode::Trunk, $this->vlan('Siège', 99), [$this->vlan('Siège', 10), $this->vlan('Siège', 20), $this->vlan('Atelier', 60), $this->vlan('Atelier', 70)]);
        $this->assertSame('Trunk, natif 99 : 10, 20 · ATL 60, 70', $this->summary($port));

        // Only VLANs of another site.
        $port = $this->ports['1/0/2'];
        $this->carry($port, PortMode::Trunk, null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60), $this->vlan('Atelier', 70), $this->vlan('Atelier', 99)]);
        $this->assertSame('Trunk : ATL 10, 60, 70, 99', $this->summary($port));

        // The native VLAN of another site, and the other sites by name: a
        // site without a code by its name.
        $port = $this->ports['1/0/3'];
        $this->carry($port, PortMode::Trunk, $this->vlan('Atelier', 99), [$this->vlan('Dépôt', 300), $this->vlan('Siège', 10), $this->vlan('Atelier', 60)]);
        $this->assertSame('Trunk, natif ATL 99 : 10 · ATL 60 · Dépôt 300', $this->summary($port));

        $port = $this->ports['1/0/4'];
        $this->carry($port, PortMode::Access, $this->vlan('Atelier', 60));
        $this->assertSame('Accès ATL 60', $this->summary($port));

        $port = $this->ports['1/0/5'];
        $this->carry($port, PortMode::Access, $this->vlan('Siège', 10), [$this->vlan('Atelier', 70)]);
        $this->assertSame('Accès 10, voix ATL 70', $this->summary($port));

        // The VLANs of the site of the device alone, as before.
        $port = $this->ports['1/0/6'];
        $this->carry($port, PortMode::Trunk, $this->vlan('Siège', 99), [$this->vlan('Siège', 20), $this->vlan('Siège', 10)]);
        $this->assertSame('Trunk, natif 99 : 10, 20', $this->summary($port));
    }

    public function test_the_summary_names_the_site_wherever_a_port_is_summed_up(): void
    {
        $workshopSide = PortAggregate::factory()->create(['device_id' => $this->firewall->id, 'name' => 'Po2']);
        $this->ports['1/0/3']->update(['port_aggregate_id' => $workshopSide->id]);
        $this->ports['1/0/4']->update(['port_aggregate_id' => $workshopSide->id]);

        $this->carry($workshopSide, PortMode::Trunk, null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60), $this->vlan('Atelier', 70)]);
        $this->carry($this->ports['1/0/1'], PortMode::Trunk, $this->vlan('Siège', 99), [$this->vlan('Siège', 10), $this->vlan('Atelier', 60)]);
        $this->carry($this->ports['1/0/2'], PortMode::Access, $this->vlan('Atelier', 60));

        // A jack patched to a port, another to a port of the aggregate.
        $floor = $this->rack->floor_id;
        Jack::factory()->create(['floor_id' => $floor, 'label' => 'B.01', 'patch_panel_port_id' => $this->panelPort(1)->id]);
        Jack::factory()->create(['floor_id' => $floor, 'label' => 'B.02', 'patch_panel_port_id' => $this->panelPort(2)->id]);
        PatchCordFactory::between($this->panelPort(1), $this->ports['1/0/2']);
        PatchCordFactory::between($this->panelPort(2), $this->ports['1/0/3']);

        $reader = $this->reader();

        // The table of the ports and the aggregates.
        $this->actingAs($reader)
            ->get(route('devices.show', $this->firewall))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $ports = collect($page->toArray()['props']['ports'])->keyBy('name');

                $this->assertSame('Trunk, natif 99 : 10 · ATL 60', $ports['1/0/1']['summary']);
                $this->assertSame('Accès ATL 60', $ports['1/0/2']['summary']);

                $page->where('aggregates.0.name', 'Po2')->where('aggregates.0.summary', 'Trunk : ATL 10, 60, 70');
            });

        // The path of a jack.
        $this->actingAs($reader)
            ->get(route('jacks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jacks.0.label', 'B.01')
                ->where('jacks.0.path.configuration', 'Accès ATL 60')
                ->where('jacks.1.path.configuration', 'via Po2 : Trunk : ATL 10, 60, 70'));

        // The page of the panel, and its window "Patch".
        $devicePorts = null;

        $this->actingAs($reader)
            ->get(route('patch-panels.show', ['patchPanel' => $this->panel, 'device' => $this->firewall->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$devicePorts): void {
                $page
                    ->where('ports.0.cord.configuration', 'Accès ATL 60')
                    ->where('ports.1.cord.configuration', 'via Po2 : Trunk : ATL 10, 60, 70')
                    ->reloadOnly('devicePorts', function (Assert $reload) use (&$devicePorts): void {
                        $devicePorts = $reload->toArray()['props']['devicePorts'];
                    });
            });

        $this->assertIsArray($devicePorts);
        $this->assertSame('Trunk, natif 99 : 10 · ATL 60', $devicePorts[0]['configuration']);

        // The window "Link…" of another device, which lists the free ports
        // of the firewall: from the site of the firewall.
        $switch = Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'sw-atelier-01', 'type' => DeviceType::Switch]);
        $oppositePorts = null;

        $this->actingAs($reader)
            ->get(route('devices.show', ['device' => $switch, 'opposite' => $this->firewall->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$oppositePorts): void {
                $page->reloadOnly('oppositePorts', function (Assert $reload) use (&$oppositePorts): void {
                    $oppositePorts = $reload->toArray()['props']['oppositePorts'];
                });
            });

        $this->assertIsArray($oppositePorts);

        $summaries = collect($oppositePorts['ports'])->pluck('summary', 'name');

        $this->assertSame('Trunk, natif 99 : 10 · ATL 60', $summaries['1/0/1']);
        $this->assertSame('Trunk : ATL 10, 60, 70', $summaries['1/0/3']);
    }

    // --- Carried by a port, an aggregate, a series -----------------------------

    /**
     * What the window of a port sends.
     *
     * @param  list<Vlan>  $tagged
     * @return array<string, mixed>
     */
    private function portPayload(Port $port, string $mode, ?Vlan $untagged, array $tagged = []): array
    {
        return [
            'name' => $port->name,
            'position' => $port->position,
            'connector' => 'rj45',
            'max_speed' => '1g',
            'poe_type' => 'none',
            'enabled' => true,
            'mode' => $mode,
            'untagged_vlan_id' => $untagged?->id,
            'tagged_vlan_ids' => array_map(fn (Vlan $vlan): int => $vlan->id, $tagged),
        ];
    }

    /**
     * What the window of an aggregate sends.
     *
     * @param  list<string>  $ports
     * @param  list<Vlan>  $tagged
     * @return array<string, mixed>
     */
    private function aggregatePayload(string $name, array $ports, string $mode, ?Vlan $untagged, array $tagged = []): array
    {
        return [
            'name' => $name,
            'protocol' => 'lacp',
            'port_ids' => array_map(fn (string $port): int => $this->ports[$port]->id, $ports),
            'mode' => $mode,
            'untagged_vlan_id' => $untagged?->id,
            'tagged_vlan_ids' => array_map(fn (Vlan $vlan): int => $vlan->id, $tagged),
        ];
    }

    public function test_the_page_of_a_device_offers_the_vlans_of_its_site_first_then_those_of_the_other_sites(): void
    {
        // A site without a VLAN offers none.
        $empty = Site::factory()->create(['name' => 'Entrepôt']);

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->firewall))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $groups = $page->toArray()['props']['vlans'];

                $this->assertSame(['Siège', 'Atelier', 'Dépôt'], array_map(fn (array $group): string => $group['site']['name'], $groups));
                $this->assertSame([10, 60, 70, 99], array_map(fn (array $vlan): int => $vlan['vid'], $groups[1]['vlans']));
            });

        // A device of that site sees its own site first, without a VLAN.
        $device = Device::factory()->create(['site_id' => $empty->id, 'name' => 'sw-entrepot']);

        $this->actingAs($this->reader())
            ->get(route('devices.show', $device))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('vlans.0.site.name', 'Entrepôt')
                ->where('vlans.0.vlans', [])
                ->where('vlans.1.site.name', 'Atelier')
                ->where('vlans.3.site.name', 'Siège'));
    }

    public function test_a_port_an_aggregate_and_a_series_of_ports_carry_vlans_of_another_site(): void
    {
        $editor = $this->editor();

        // A port: the VLANs of both sites.
        $this->actingAs($editor)
            ->put(route('ports.update', $this->ports['1/0/1']), $this->portPayload($this->ports['1/0/1'], 'trunk', $this->vlan('Siège', 99), [$this->vlan('Siège', 10), $this->vlan('Atelier', 60)]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Trunk, natif 99 : 10 · ATL 60', $this->summary($this->ports['1/0/1']));
        $this->assertSame('U 99 ; T 10 · Atelier 60', $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray()['attributes']['vlans'] ?? null);

        // An aggregate towards each site, with the same numbers on both: a
        // firewall keeps them apart, one interface per site.
        $this->actingAs($editor)
            ->post(route('port-aggregates.store', $this->firewall), $this->aggregatePayload('Po1', ['1/0/3', '1/0/4'], 'trunk', $this->vlan('Siège', 99), [$this->vlan('Siège', 10), $this->vlan('Siège', 20)]))
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)
            ->post(route('port-aggregates.store', $this->firewall), $this->aggregatePayload('Po2', ['1/0/5', '1/0/6'], 'trunk', null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60), $this->vlan('Atelier', 70), $this->vlan('Atelier', 99)]))
            ->assertSessionHasNoErrors();

        $workshopSide = PortAggregate::query()->where('name', 'Po2')->sole();

        $this->assertSame('Trunk : ATL 10, 60, 70, 99', $this->summary($workshopSide));
        $this->assertSame('T Atelier 10, 60, 70, 99', $this->lastEntry(ActivityEvent::PortAggregateCreated)->attribute_changes?->toArray()['attributes']['vlans'] ?? null);

        // Ports given a mode together.
        $this->actingAs($editor)
            ->patch(route('ports.update-many', $this->firewall), [
                'ports' => [$this->ports['1/0/7']->id, $this->ports['1/0/8']->id],
                'action' => 'mode',
                'mode' => 'access',
                'untagged_vlan_id' => $this->vlan('Atelier', 60)->id,
                'tagged_vlan_ids' => [$this->vlan('Siège', 20)->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Accès ATL 60, voix 20', $this->summary($this->ports['1/0/7']));
        $this->assertSame('Accès ATL 60, voix 20', $this->summary($this->ports['1/0/8']));

        // The VLAN 10 of the head office added to the aggregate towards the
        // workshop, which carries the VLAN 10 of the workshop: refused.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('port-aggregates.update', $workshopSide), $this->aggregatePayload('Po2', ['1/0/5', '1/0/6'], 'trunk', null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60), $this->vlan('Siège', 10)])),
            'tagged_vlan_ids',
            'Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port.',
        );

        $this->assertSame('Trunk : ATL 10, 60, 70, 99', $this->summary($workshopSide));
    }

    public function test_the_vlans_given_to_a_port_are_read_and_kept_as_they_are_stored_until_it_is_written(): void
    {
        $read = [];

        DB::listen(function (QueryExecuted $query) use (&$read): void {
            if (str_contains($query->sql, 'from `vlans`')) {
                $read[] = $query->sql;
            }
        });

        $this->actingAs($this->editor())
            ->put(route('ports.update', $this->ports['1/0/1']), $this->portPayload($this->ports['1/0/1'], 'access', $this->vlan('Atelier', 60)))
            ->assertSessionHasNoErrors();

        // A change of their numbers waits for the end of the transaction.
        $this->assertCount(1, array_filter($read, fn (string $sql): bool => str_ends_with($sql, 'lock in share mode')));
    }

    // --- The number of a VLAN carried -----------------------------------------

    public function test_a_vlan_does_not_take_the_number_of_another_vlan_carried_with_it(): void
    {
        $editor = $this->editor();
        $workshop = $this->vlan('Atelier', 60);
        $payload = ['site_id' => $this->workshop->id, 'name' => 'VLAN 60', 'status' => 'active'];

        $aggregate = PortAggregate::factory()->create(['device_id' => $this->firewall->id, 'name' => 'Po2']);

        $this->carry($this->ports['1/0/1'], PortMode::Trunk, null, [$this->vlan('Siège', 20), $workshop]);
        $this->carry($this->ports['1/0/2'], PortMode::Access, $workshop);
        $this->carry($aggregate, PortMode::Trunk, $this->vlan('Siège', 20), [$workshop]);

        // The ports first, then the aggregates.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('vlans.update', $workshop), [...$payload, 'vid' => 20]),
            'vid',
            '2 ports ou agrégats qui portent ce VLAN portent déjà un autre VLAN 20 : fw-siege (1/0/1, Po2).',
        );

        $this->carry($this->ports['1/0/2'], PortMode::Access, $workshop, [$this->vlan('Dépôt', 300)]);

        $this->assertRefused(
            $this->actingAs($editor)->put(route('vlans.update', $workshop), [...$payload, 'vid' => 300]),
            'vid',
            'Un port ou un agrégat qui porte ce VLAN porte déjà un autre VLAN 300 : fw-siege (1/0/2).',
        );

        // A number the site already has is told first, as it was.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('vlans.update', $workshop), [...$payload, 'vid' => 10]),
            'vid',
            'Ce site a déjà un VLAN avec ce numéro.',
        );

        $this->assertSame(60, $workshop->refresh()->vid);

        // Ten ports named at most, then how many more.
        $switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'type' => DeviceType::Switch]);
        $member = DeviceMember::factory()->primary()->create(['device_id' => $switch->id, 'position' => 1]);

        foreach (PortFactory::series($member, 12) as $port) {
            $this->carry($port, PortMode::Trunk, $this->vlan('Siège', 20), [$this->vlan('Atelier', 70)]);
        }

        $this->assertRefused(
            $this->actingAs($editor)->put(route('vlans.update', $this->vlan('Atelier', 70)), [...$payload, 'name' => 'VLAN 70', 'vid' => 20]),
            'vid',
            '12 ports ou agrégats qui portent ce VLAN portent déjà un autre VLAN 20 : sw-coeur (1/0/1, 1/0/2, 1/0/3, 1/0/4, 1/0/5, 1/0/6, 1/0/7, 1/0/8, 1/0/9, 1/0/10) et 2 de plus.',
        );

        // A number no VLAN carried with it has: it changes, and the ports
        // carry it under that number.
        $this->actingAs($editor)->put(route('vlans.update', $workshop), [...$payload, 'vid' => 61])->assertSessionHasNoErrors();

        $this->assertSame('Trunk : 20 · ATL 61', $this->summary($this->ports['1/0/1']));

        // Nor does the check stand in the way of anything but the number.
        $this->actingAs($editor)->put(route('vlans.update', $workshop), [...$payload, 'vid' => 61, 'name' => 'Ateliers'])->assertSessionHasNoErrors();
    }

    public function test_a_change_of_number_reads_the_ports_that_carry_the_vlan_as_they_are_stored_and_keeps_them(): void
    {
        $workshop = $this->vlan('Atelier', 60);

        $this->carry($this->ports['1/0/1'], PortMode::Trunk, null, [$workshop]);

        $locked = [];

        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            if (str_ends_with($query->sql, 'for update') && (str_contains($query->sql, 'from `port_vlans`') || str_contains($query->sql, 'from `vlans`'))) {
                $locked[] = $query->sql;
            }
        });

        $this->actingAs($this->editor())
            ->put(route('vlans.update', $workshop), ['site_id' => $this->workshop->id, 'vid' => 61, 'name' => 'VLAN 60', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        // The VLAN, the lines that carry it, and those carried with it.
        $this->assertCount(3, $locked);
    }

    // --- Two networks merged --------------------------------------------------

    public function test_a_link_that_merges_two_vlans_is_shown_where_the_deviations_of_links_are(): void
    {
        $core = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur-02', 'type' => DeviceType::Switch]);
        $remote = Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'sw-atelier-01', 'type' => DeviceType::Switch]);
        $corePort = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $core->id, 'position' => 1]), 1)[0];
        $remotePort = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $remote->id, 'position' => 1]), 1)[0];

        // The VLAN 60 of the workshop at both ends, and the VLAN 10 of each
        // site.
        $this->carry($corePort, PortMode::Trunk, null, [$this->vlan('Siège', 10), $this->vlan('Atelier', 60)]);
        $this->carry($remotePort, PortMode::Trunk, null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60)]);

        LinkFactory::between($corePort, $remotePort);

        $fromOffice = ['kind' => 'link_merges_vlans', 'reason' => "VLAN 10 du site Siège d'un côté, VLAN 10 du site Atelier de l'autre : la liaison fusionne deux réseaux"];
        $fromWorkshop = ['kind' => 'link_merges_vlans', 'reason' => "VLAN 10 du site Atelier d'un côté, VLAN 10 du site Siège de l'autre : la liaison fusionne deux réseaux"];
        $reader = $this->reader();

        // The list of links, the links of each device and the list of the
        // deviations of each device page.
        $this->actingAs($reader)
            ->get(route('links.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.0.deviations', [$fromOffice]));

        $this->actingAs($reader)
            ->get(route('devices.show', $core))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('links.0.deviations', [$fromOffice])
                ->where('linkDeviations.0.label', '1/0/1 ↔ sw-atelier-01 › 1/0/1')
                ->where('linkDeviations.0.deviations', [$fromOffice]));

        $this->actingAs($reader)
            ->get(route('devices.show', $remote))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.0.deviations', [$fromWorkshop]));

        // One end corrected: the VLAN 10 of the workshop on both.
        $this->carry($corePort, PortMode::Trunk, null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60)]);

        $this->actingAs($reader)
            ->get(route('links.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.0.deviations', []));
    }

    /**
     * The deviations of the VLANs of a device, as the list of the
     * deviations of its page shows them: null when there is none.
     *
     * @return list<array{kind: string, reason: string}>|null
     */
    private function mergedOn(Device $device): ?array
    {
        $items = collect($this->actingAs($this->reader())->get(route('devices.show', $device))->assertOk()->inertiaProps('linkDeviations'));

        $item = $items->firstWhere('key', 'vlans');

        if ($item === null) {
            return null;
        }

        $this->assertSame(['VLAN', 'ports'], [$item['label'], $item['section']]);

        return $item['deviations'];
    }

    public function test_a_switch_that_carries_two_vlans_of_one_number_merges_them(): void
    {
        $switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur-02', 'type' => DeviceType::Switch]);
        $ports = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $switch->id, 'position' => 1]), 4);

        // The VLAN 60 of the workshop alone: nothing merged.
        $this->carry($ports[0], PortMode::Trunk, $this->vlan('Siège', 99), [$this->vlan('Siège', 10), $this->vlan('Atelier', 60)]);

        $this->assertNull($this->mergedOn($switch));

        // The VLAN 10 of each site, on two different ports.
        $this->carry($ports[1], PortMode::Access, $this->vlan('Atelier', 10));

        $this->assertSame(
            [['kind' => 'switch_merges_vlans', 'reason' => 'VLAN 10 des sites Siège et Atelier : sw-coeur-02 les fusionne']],
            $this->mergedOn($switch),
        );

        // On an aggregate as well, three sites, each number once; the site
        // of the switch first, then the others by name.
        $aggregate = PortAggregate::factory()->create(['device_id' => $switch->id, 'name' => 'Po1']);
        $ports[2]->update(['port_aggregate_id' => $aggregate->id]);
        $this->carry($aggregate, PortMode::Trunk, $this->vlan('Atelier', 99), [$this->vlan('Dépôt', 300)]);
        $this->carry($ports[3], PortMode::Trunk, null, [Vlan::factory()->create(['site_id' => Site::factory()->create(['name' => 'Annexe'])->id, 'vid' => 10])]);

        $this->assertSame(
            [
                ['kind' => 'switch_merges_vlans', 'reason' => 'VLAN 10 des sites Siège, Annexe et Atelier : sw-coeur-02 les fusionne'],
                ['kind' => 'switch_merges_vlans', 'reason' => 'VLAN 99 des sites Siège et Atelier : sw-coeur-02 les fusionne'],
            ],
            $this->mergedOn($switch),
        );
    }

    public function test_a_firewall_keeps_the_vlans_of_one_number_of_two_sites_apart(): void
    {
        $officeSide = PortAggregate::factory()->create(['device_id' => $this->firewall->id, 'name' => 'Po1']);
        $workshopSide = PortAggregate::factory()->create(['device_id' => $this->firewall->id, 'name' => 'Po2']);
        $this->ports['1/0/1']->update(['port_aggregate_id' => $officeSide->id]);
        $this->ports['1/0/2']->update(['port_aggregate_id' => $workshopSide->id]);

        // The VLANs of the head office on one aggregate, those of the
        // workshop on the other, under the same numbers.
        $this->carry($officeSide, PortMode::Trunk, $this->vlan('Siège', 99), [$this->vlan('Siège', 10), $this->vlan('Siège', 20)]);
        $this->carry($workshopSide, PortMode::Trunk, $this->vlan('Atelier', 99), [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60)]);

        $this->assertNull($this->mergedOn($this->firewall));

        // A router neither.
        $this->firewall->update(['type' => DeviceType::Router]);

        $this->assertNull($this->mergedOn($this->firewall));

        // A switch would.
        $this->firewall->update(['type' => DeviceType::Switch]);

        $this->assertCount(2, $this->mergedOn($this->firewall) ?? []);
    }

    // --- An address of a device of another site --------------------------------

    /**
     * The deviations of an address on the page of its device and in the
     * table of its subnet, which must be the same.
     *
     * @return list<array{kind: string, reason: string}>
     */
    private function addressDeviations(IpAddress $address): array
    {
        $reader = $this->reader();

        $onDevice = collect($this->actingAs($reader)->get(route('devices.show', $address->device_id))->assertOk()->inertiaProps('addresses'))
            ->firstWhere('id', $address->id)['deviations'] ?? null;
        $onSubnet = collect($this->actingAs($reader)->get(route('subnets.show', $address->subnet_id))->assertOk()->inertiaProps('addresses'))
            ->firstWhere('id', $address->id)['deviations'] ?? null;

        $this->assertSame($onDevice, $onSubnet);
        $this->assertIsArray($onDevice);

        return $onDevice;
    }

    public function test_an_address_of_a_device_of_another_site_is_no_deviation_once_the_device_carries_its_vlan(): void
    {
        $workshopSide = PortAggregate::factory()->create(['device_id' => $this->firewall->id, 'name' => 'Po2']);
        $this->ports['1/0/1']->update(['port_aggregate_id' => $workshopSide->id]);

        // The gateway of a subnet of the workshop, on the firewall of the
        // head office.
        $subnet = $this->subnet('10.20.60.0/24', ['site_id' => $this->workshop->id, 'vlan_id' => $this->vlan('Atelier', 60)->id]);
        $address = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.60.1', 'device_id' => $this->firewall->id]);

        $another = [['kind' => 'address_on_device_of_another_site', 'reason' => 'Sous-réseau du site Atelier, équipement du site Siège']];

        $this->assertSame($another, $this->addressDeviations($address));

        // Another VLAN of the workshop on the firewall changes nothing.
        $this->carry($workshopSide, PortMode::Trunk, null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 70)]);

        $this->assertSame($another, $this->addressDeviations($address));

        // Its VLAN, tagged on the aggregate.
        $this->carry($workshopSide, PortMode::Trunk, null, [$this->vlan('Atelier', 10), $this->vlan('Atelier', 60)]);

        $this->assertSame([], $this->addressDeviations($address));

        // Or untagged on a port of its own, in access.
        $this->carry($workshopSide, PortMode::Trunk, null, [$this->vlan('Atelier', 10)]);
        $this->carry($this->ports['1/0/2'], PortMode::Access, $this->vlan('Atelier', 60));

        $this->assertSame([], $this->addressDeviations($address));

        // Taken away: the deviation again.
        PortVlan::query()->where('port_id', $this->ports['1/0/2']->id)->delete();

        $this->assertSame($another, $this->addressDeviations($address));

        // A subnet of the workshop without a VLAN keeps the rule: its site.
        $loose = $this->subnet('10.20.99.0/24', ['site_id' => $this->workshop->id]);
        $other = IpAddress::factory()->create(['subnet_id' => $loose->id, 'address' => '10.20.99.1', 'device_id' => $this->firewall->id]);

        $this->assertSame($another, $this->addressDeviations($other));
    }

    // --- An address plugged into a jack ----------------------------------------

    public function test_the_vlan_of_a_plugged_address_is_compared_with_what_its_port_carries_vlan_by_vlan(): void
    {
        $jack = Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.01', 'patch_panel_port_id' => $this->panelPort(1)->id]);
        PatchCordFactory::between($this->panelPort(1), $this->ports['1/0/2']);

        $subnet = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id, 'vlan_id' => $this->vlan('Siège', 10)->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.5', 'jack_id' => $jack->id]);

        $deviations = fn (): array => $this->actingAs($this->reader())->get(route('jacks.index'))->assertOk()->inertiaProps('jacks.0.deviations');

        // The VLAN 10 of the workshop is not the one of the address.
        $this->carry($this->ports['1/0/2'], PortMode::Access, $this->vlan('Atelier', 10));

        $this->assertSame(
            [['kind' => 'address_vlan_not_carried', 'reason' => 'fw-siege 1/0/2 en accès VLAN 10 du site Atelier, adresse 10.10.10.5 du VLAN 10 du site Siège']],
            $deviations(),
        );

        $this->carry($this->ports['1/0/2'], PortMode::Trunk, $this->vlan('Siège', 99), [$this->vlan('Atelier', 10)]);

        $this->assertSame(
            [['kind' => 'address_vlan_not_carried', 'reason' => "fw-siege 1/0/2 en trunk sans le VLAN 10 du site Siège, celui de l'adresse 10.10.10.5"]],
            $deviations(),
        );

        // Without a VLAN of its number, the numbers alone, as before.
        $this->carry($this->ports['1/0/2'], PortMode::Access, $this->vlan('Atelier', 60));

        $this->assertSame(
            [['kind' => 'address_vlan_not_carried', 'reason' => 'fw-siege 1/0/2 en accès VLAN 60, adresse 10.10.10.5 du VLAN 10']],
            $deviations(),
        );

        // Its own VLAN: nothing to tell.
        $this->carry($this->ports['1/0/2'], PortMode::Access, $this->vlan('Siège', 10));

        $this->assertSame([], $deviations());
    }

    // --- The activity log -----------------------------------------------------

    public function test_the_activity_log_names_a_vlan_of_another_site_with_its_site(): void
    {
        $editor = $this->editor();
        $port = $this->ports['1/0/1'];
        $aggregate = PortAggregate::factory()->create(['device_id' => $this->firewall->id, 'name' => 'Po2']);
        $this->ports['1/0/3']->update(['port_aggregate_id' => $aggregate->id]);

        $this->carry($port, PortMode::Trunk, $this->vlan('Atelier', 99), [$this->vlan('Siège', 10), $this->vlan('Siège', 20), $this->vlan('Atelier', 60), $this->vlan('Dépôt', 300)]);
        $this->carry($aggregate, PortMode::Access, $this->vlan('Atelier', 60));

        $this->actingAs($editor)->patch(route('ports.update-many', $this->firewall), [
            'ports' => [$port->id],
            'action' => 'clear_mode',
        ])->assertSessionHasNoErrors();

        // The VLANs of the site of the device first, then those of each
        // other site, behind its name.
        $this->assertSame(
            ['old' => ['mode' => 'trunk', 'vlans' => 'U Atelier 99 ; T 10, 20 · Atelier 60 · Dépôt 300'], 'attributes' => ['mode' => null, 'vlans' => null]],
            $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray(),
        );

        // As the page of the log shows it.
        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => ActivityEvent::PortUpdated->value]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.subject.title', 'fw-siege (Siège) › 1/0/1')
                ->where('entries.data.0.changes', [
                    ['field' => 'Mode', 'old' => 'Trunk', 'new' => '—'],
                    ['field' => 'VLAN', 'old' => 'U Atelier 99 ; T 10, 20 · Atelier 60 · Dépôt 300', 'new' => '—'],
                ]));

        $this->actingAs($editor)->delete(route('port-aggregates.destroy', $aggregate))->assertSessionHasNoErrors();

        $this->assertSame('U Atelier 60', $this->lastEntry(ActivityEvent::PortAggregateDeleted)->attribute_changes?->toArray()['old']['vlans'] ?? null);
    }
}
