<?php

// torsade/tests/Feature/Network/DiagramLayoutTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\DiagramView;
use App\Enums\Status;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DiagramPosition;
use App\Models\Port;
use App\Models\Site;
use App\Models\User;
use Database\Factories\LinkFactory;
use Database\Factories\PortFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The layout of the physical diagram of a site, placed by hand: read with
 * the page, saved all at once over the version it was read at, refused in
 * words when it cannot be, and given back to the automatic layout. Its
 * nodes are named by their keys on the diagram, "device-12" here; the
 * panels are placed the same way (PhysicalCablingDiagramTest).
 */
class DiagramLayoutTest extends NetworkTestCase
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

        // A firewall, a core switch linked to it and to the workshop, an
        // access point linked to nothing.
        $this->device('fw-siege', DeviceType::Firewall);
        $this->device('sw-coeur', DeviceType::Switch);
        $this->device('ap-accueil', DeviceType::AccessPoint);
        $this->device('sw-atelier', DeviceType::Switch, $this->workshop);
        $this->link('fw-siege', '1/0/1', 'sw-coeur', '1/0/1');
        $this->link('sw-coeur', '1/0/2', 'sw-atelier', '1/0/1');
    }

    private function device(string $name, DeviceType $type, ?Site $site = null, Status $status = Status::Active): Device
    {
        $device = Device::factory()->create([
            'site_id' => ($site ?? $this->office)->id,
            'name' => $name,
            'type' => $type,
            'status' => $status,
        ]);

        foreach (PortFactory::series(DeviceMember::factory()->create(['device_id' => $device->id, 'position' => 1]), 4) as $port) {
            $this->ports[$name][$port->name] = $port;
        }

        return $this->devices[$name] = $device;
    }

    private function link(string $device, string $port, string $other, string $otherPort): void
    {
        LinkFactory::between($this->ports[$device][$port], $this->ports[$other][$otherPort]);
    }

    /**
     * The layout of the diagram of the head office, as its page reads it.
     *
     * @return array{positions: list<array{node: string, x: int, y: int}>, version: string}
     */
    private function layout(?User $user = null, ?Site $site = null): array
    {
        $layout = null;

        $this->actingAs($user ?? $this->reader())
            ->get(route('diagrams.physical', ['site' => ($site ?? $this->office)->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$layout): void {
                $layout = $page->toArray()['props']['layout'];
            });

        $this->assertIsArray($layout);

        /** @var array{positions: list<array{node: string, x: int, y: int}>, version: string} $layout */
        return $layout;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $positions  By the name of the device.
     * @return TestResponse<Response>
     */
    private function save(array $positions, string $version, ?User $user = null, ?Site $site = null): TestResponse
    {
        return $this->actingAs($user ?? $this->editor())->put(
            route('sites.diagrams.physical.layout.update', $site ?? $this->office),
            [
                'version' => $version,
                'positions' => array_map(
                    fn (string $name, array $at): array => ['node' => 'device-'.$this->devices[$name]->id, 'x' => $at[0], 'y' => $at[1]],
                    array_keys($positions),
                    $positions,
                ),
            ],
        );
    }

    /**
     * The positions stored for the diagram of a site, by the name of the
     * device.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function stored(?Site $site = null): array
    {
        return DiagramPosition::query()
            ->where('site_id', ($site ?? $this->office)->id)
            ->with('device')
            ->get()
            ->sortBy(fn (DiagramPosition $position): string => $position->device->name)
            ->mapWithKeys(fn (DiagramPosition $position): array => [$position->device->name => [$position->x, $position->y]])
            ->all();
    }

    // --- Reading ----------------------------------------------------------------

    public function test_a_diagram_without_positions_is_laid_out_on_its_own_and_has_a_version(): void
    {
        $layout = $this->layout();

        $this->assertSame([], $layout['positions']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $layout['version']);

        // Read again, the same version.
        $this->assertSame($layout['version'], $this->layout()['version']);
    }

    public function test_the_page_reads_the_positions_of_the_devices_drawn_and_who_may_change_them(): void
    {
        DiagramPosition::query()->insert([
            ['site_id' => $this->office->id, 'view' => 'physical', 'device_id' => $this->devices['sw-coeur']->id, 'x' => 160, 'y' => 240, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => $this->office->id, 'view' => 'physical', 'device_id' => $this->devices['sw-atelier']->id, 'x' => -80, 'y' => 400, 'created_at' => now(), 'updated_at' => now()],
            // On the diagram of the workshop: none of the head office.
            ['site_id' => $this->workshop->id, 'view' => 'physical', 'device_id' => $this->devices['fw-siege']->id, 'x' => 8, 'y' => 8, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $layout = $this->layout();

        $this->assertEqualsCanonicalizing([
            ['node' => 'device-'.$this->devices['sw-coeur']->id, 'x' => 160, 'y' => 240],
            ['node' => 'device-'.$this->devices['sw-atelier']->id, 'x' => -80, 'y' => 400],
        ], $layout['positions']);

        // A reader sees the layout and is not offered to change it; an
        // editor and an administrator are.
        $this->actingAs($this->reader())
            ->get(route('diagrams.physical', ['site' => $this->office->id]))
            ->assertInertia(fn (Assert $page) => $page->where('canEditLayout', false));

        foreach ([$this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)
                ->get(route('diagrams.physical', ['site' => $this->office->id]))
                ->assertInertia(fn (Assert $page) => $page->where('canEditLayout', true));
        }
    }

    public function test_the_position_of_a_device_no_longer_drawn_is_left_out_then_dropped_at_the_next_save(): void
    {
        $version = $this->layout()['version'];

        $this->save(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160], 'ap-accueil' => [240, 160], 'sw-atelier' => [0, 320]], $version)->assertSessionHasNoErrors();

        // The access point is retired: no longer drawn.
        $this->devices['ap-accueil']->update(['status' => Status::Retired]);

        $layout = $this->layout();

        $this->assertNotContains('device-'.$this->devices['ap-accueil']->id, array_column($layout['positions'], 'node'));
        $this->assertCount(3, $layout['positions']);

        // Its position still counts in the version, and goes at the next
        // save.
        $this->save(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160], 'sw-atelier' => [0, 320]], $layout['version'])->assertSessionHasNoErrors();

        $this->assertSame(['fw-siege' => [0, 0], 'sw-atelier' => [0, 320], 'sw-coeur' => [0, 160]], $this->stored());
    }

    // --- Saving -----------------------------------------------------------------

    public function test_a_layout_is_saved_all_at_once_with_one_entry_of_the_log_on_the_site(): void
    {
        $version = $this->layout()['version'];

        $this->save(['fw-siege' => [0, 0], 'sw-coeur' => [8, 160], 'ap-accueil' => [-240, 160], 'sw-atelier' => [1_000_000, -1_000_000]], $version)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('diagrams.physical', ['site' => $this->office->id]));

        $this->assertSame(
            ['ap-accueil' => [-240, 160], 'fw-siege' => [0, 0], 'sw-atelier' => [1_000_000, -1_000_000], 'sw-coeur' => [8, 160]],
            $this->stored(),
        );
        $this->assertSame(['view' => DiagramView::Physical], ['view' => DiagramPosition::query()->firstOrFail()->view]);

        // One entry, on the site, with the number of elements, devices and
        // panels.
        $this->assertSame(1, $this->countEntries(ActivityEvent::SiteDiagramSaved));

        $entry = $this->lastEntry(ActivityEvent::SiteDiagramSaved);

        $this->assertSame($this->office->id, $entry->subject_id);
        $this->assertSame(Site::class, $entry->subject_type);
        $this->assertSame(['diagram' => 'physical', 'nodes_count' => 4, 'devices_count' => 4, 'panels_count' => 0], ($entry->attribute_changes?->toArray() ?? [])['attributes']);

        // The page then reads the new layout, under another version.
        $layout = $this->layout();

        $this->assertCount(4, $layout['positions']);
        $this->assertNotSame($version, $layout['version']);

        // Saved again: what is not sent is not placed any more.
        $this->save(['fw-siege' => [16, 16]], $layout['version'])->assertSessionHasNoErrors();
        $this->assertSame(['fw-siege' => [16, 16]], $this->stored());
    }

    public function test_a_layout_changed_in_the_meantime_is_refused_and_nothing_is_written(): void
    {
        $version = $this->layout()['version'];

        // Someone else saves first, from another page.
        $this->save(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160]], $version)->assertSessionHasNoErrors();

        $this->assertRefused(
            $this->save(['fw-siege' => [80, 80], 'sw-coeur' => [80, 240]], $version),
            'version',
            'La disposition a été modifiée entre-temps : rechargez la page.',
        );
        $this->assertSame(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160]], $this->stored());
        $this->assertSame(1, $this->countEntries(ActivityEvent::SiteDiagramSaved));

        // So is a layout read before a return to the automatic layout.
        $current = $this->layout()['version'];

        $this->actingAs($this->editor())->delete(route('sites.diagrams.physical.layout.destroy', $this->office))->assertSessionHasNoErrors();

        $this->assertRefused($this->save(['fw-siege' => [8, 8]], $current), 'version', 'La disposition a été modifiée entre-temps : rechargez la page.');
        $this->assertSame([], $this->stored());

        // A version that was never given.
        $this->assertRefused($this->save(['fw-siege' => [8, 8]], 'pas-une-version'), 'version', 'La disposition a été modifiée entre-temps : rechargez la page.');
    }

    public function test_a_device_not_drawn_on_the_diagram_is_refused_in_words(): void
    {
        $version = $this->layout()['version'];

        // A device of the workshop that is not linked to the head office,
        // a retired one, and one that does not exist.
        $this->device('sw-quai', DeviceType::Switch, $this->workshop);
        $this->device('sw-ancien', DeviceType::Switch, status: Status::Retired);

        foreach (['sw-quai', 'sw-ancien'] as $name) {
            $this->assertRefused(
                $this->save(['fw-siege' => [0, 0], $name => [0, 160]], $version),
                'positions',
                'Certains de ces éléments ne sont plus dessinés sur ce schéma : rechargez la page.',
            );
        }

        foreach (['device-999999999', 'patch_panel-999999999'] as $node) {
            $response = $this->actingAs($this->editor())->put(route('sites.diagrams.physical.layout.update', $this->office), [
                'version' => $version,
                'positions' => [['node' => $node, 'x' => 0, 'y' => 0]],
            ]);

            $this->assertRefused($response, 'positions', 'Certains de ces éléments ne sont plus dessinés sur ce schéma : rechargez la page.');
        }

        // The device of the workshop drawn at the edge of the head office
        // has a place on its diagram.
        $this->save(['sw-atelier' => [0, 480]], $version)->assertSessionHasNoErrors();
        $this->assertSame(['sw-atelier' => [0, 480]], $this->stored());
    }

    public function test_a_position_out_of_the_bounds_or_off_the_grid_is_refused_in_words_never_with_an_error_of_the_server(): void
    {
        $version = $this->layout()['version'];
        $put = fn (array $payload): TestResponse => $this->actingAs($this->editor())->put(route('sites.diagrams.physical.layout.update', $this->office), $payload);
        $line = fn (mixed $x, mixed $y = 0, mixed $node = null): array => ['node' => $node ?? 'device-'.$this->devices['fw-siege']->id, 'x' => $x, 'y' => $y];

        foreach ([
            [[$line(1_000_008)], 'positions.0.x', 'Un élément est placé hors des limites du schéma : rechargez la page.'],
            [[$line(0, -1_000_008)], 'positions.0.y', 'Un élément est placé hors des limites du schéma : rechargez la page.'],
            [[$line(12)], 'positions.0.x', 'Un élément est placé hors de la grille du schéma : rechargez la page.'],
            [[$line(0, 7)], 'positions.0.y', 'Un élément est placé hors de la grille du schéma : rechargez la page.'],
            [[$line('dix')], 'positions.0.x', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(1.5)], 'positions.0.x', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 'fw-siege')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 'subnet-4')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0, 0, 'internet')], 'positions.0.node', "La disposition n'a pas pu être lue : rechargez la page."],
            [[$line(0), $line(8)], 'positions.1.node', 'Un élément est placé deux fois : rechargez la page.'],
            [[], 'positions', "La disposition n'a pas pu être lue : rechargez la page."],
            ['tout', 'positions', "La disposition n'a pas pu être lue : rechargez la page."],
            [['un'], 'positions.0', "La disposition n'a pas pu être lue : rechargez la page."],
        ] as [$positions, $field, $message]) {
            $this->assertRefused($put(['version' => $version, 'positions' => $positions]), $field, $message);
        }

        $this->assertRefused($put(['positions' => [$line(0)]]), 'version', 'La disposition a été modifiée entre-temps : rechargez la page.');
        $this->assertSame([], $this->stored());
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDiagramSaved));
    }

    public function test_saving_150_positions_costs_the_same_as_saving_three(): void
    {
        $queries = 0;

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        // Measured around the save alone.
        $measure = function (array $positions) use (&$queries): int {
            $version = $this->layout()['version'];
            $queries = 0;

            $this->save($positions, $version)->assertSessionHasNoErrors();

            return $queries;
        };

        $few = $measure(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160], 'sw-atelier' => [0, 320]]);

        // Then a hundred and fifty devices on the diagram, all placed.
        $positions = ['fw-siege' => [0, 0], 'sw-coeur' => [0, 160], 'ap-accueil' => [0, 320], 'sw-atelier' => [0, 480]];

        foreach (range(1, 146) as $number) {
            $this->device('sw-'.$number, DeviceType::Switch);
            $positions['sw-'.$number] = [8 * $number, 640];
        }

        $many = $measure($positions);

        $this->assertCount(150, $this->stored());
        $this->assertSame($few, $many);
    }

    // --- Back to the automatic layout -------------------------------------------

    public function test_the_automatic_layout_comes_back_with_one_entry_of_the_log(): void
    {
        $this->save(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160]], $this->layout()['version'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor())
            ->delete(route('sites.diagrams.physical.layout.destroy', $this->office))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('diagrams.physical', ['site' => $this->office->id]));

        $this->assertSame([], $this->stored());
        $this->assertSame([], $this->layout()['positions']);

        $entry = $this->lastEntry(ActivityEvent::SiteDiagramReset);

        $this->assertSame($this->office->id, $entry->subject_id);
        $this->assertSame(['diagram' => 'physical', 'nodes_count' => 2, 'devices_count' => 2, 'panels_count' => 0], ($entry->attribute_changes?->toArray() ?? [])['old']);

        // Already automatic: nothing to tell.
        $this->actingAs($this->admin())->delete(route('sites.diagrams.physical.layout.destroy', $this->office))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::SiteDiagramReset));
    }

    // --- Rights -------------------------------------------------------------------

    public function test_a_reader_can_neither_save_a_layout_nor_give_it_back_to_the_automatic_layout(): void
    {
        $version = $this->layout()['version'];

        $this->save(['fw-siege' => [0, 0]], $version, $this->reader())->assertForbidden();

        DiagramPosition::query()->create(['site_id' => $this->office->id, 'view' => DiagramView::Physical, 'device_id' => $this->devices['fw-siege']->id, 'x' => 8, 'y' => 8]);

        $this->actingAs($this->reader())->delete(route('sites.diagrams.physical.layout.destroy', $this->office))->assertForbidden();

        $this->assertSame(['fw-siege' => [8, 8]], $this->stored());
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDiagramSaved));
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDiagramReset));

        // Nor anybody without an account.
        auth()->logout();

        $this->put(route('sites.diagrams.physical.layout.update', $this->office), ['version' => $version, 'positions' => []])->assertRedirect(route('login'));
        $this->delete(route('sites.diagrams.physical.layout.destroy', $this->office))->assertRedirect(route('login'));
    }

    public function test_the_log_page_tells_what_a_layout_saved_holds(): void
    {
        $this->save(['fw-siege' => [0, 0], 'sw-coeur' => [0, 160]], $this->layout()['version'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor())->delete(route('sites.diagrams.physical.layout.destroy', $this->office))->assertSessionHasNoErrors();

        // The return to the automatic layout, then the save, on the site.
        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event', 'site.diagram_reset')
                ->where('entries.data.0.event_label', 'Schéma réorganisé automatiquement')
                ->where('entries.data.0.subject.title', 'Siège')
                ->where('entries.data.0.changes.0.field', 'Schéma')
                ->where('entries.data.0.changes.0.old', 'Schéma physique')
                ->where('entries.data.0.changes.1.field', "Nombre d'éléments")
                ->where('entries.data.0.changes.1.old', '2')
                ->where('entries.data.0.changes.2.field', "Nombre d'équipements")
                ->where('entries.data.0.changes.2.old', '2')
                ->where('entries.data.0.changes.3.field', 'Nombre de panneaux')
                ->where('entries.data.0.changes.3.old', '0')
                ->where('entries.data.1.event', 'site.diagram_saved')
                ->where('entries.data.1.event_label', 'Disposition du schéma enregistrée')
                ->where('entries.data.1.subject.title', 'Siège')
                ->where('entries.data.1.changes.0.new', 'Schéma physique')
                ->where('entries.data.1.changes.2.field', "Nombre d'équipements")
                ->where('entries.data.1.changes.2.new', '2'));
    }
}
