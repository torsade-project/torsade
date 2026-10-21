<?php

// torsade/tests/Feature/Network/JackTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wall jacks: labelled on their floor, one by one or as a series, cabled to
 * a port of a panel of their site, and detached from it.
 */
class JackTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Floor $ground;

    private Floor $first;

    private PatchPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);

        $this->ground = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $this->first = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);

        $rack = Room::factory()->technical()->create(['floor_id' => $this->first->id, 'name' => 'Local technique']);

        $this->panel = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-A', 'port_count' => 24]);
    }

    /**
     * What the window of a jack sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'label' => 'B.15',
            'location' => 'Bureau 12',
            'patch_panel_port_id' => null,
            'status' => 'active',
            'description' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function create(array $overrides = [], ?Floor $floor = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('jacks.store', $floor ?? $this->first), $this->payload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function update(Jack $jack, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->put(route('jacks.update', $jack), $this->payload([
            'label' => $jack->label,
            'location' => $jack->location,
            'patch_panel_port_id' => $jack->patch_panel_port_id,
            'status' => $jack->status->value,
            ...$overrides,
        ]));
    }

    /**
     * What the window of a series sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function series(array $overrides = [], ?Floor $floor = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('jacks.series.store', $floor ?? $this->first), [
            'prefix' => 'B.',
            'suffix' => '',
            'first_number' => 1,
            'last_number' => 24,
            'digits' => 2,
            'patch_panel_id' => null,
            'first_port' => null,
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function port(int $number, ?PatchPanel $panel = null): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', ($panel ?? $this->panel)->id)->where('number', $number)->sole();
    }

    /**
     * Each jack of a floor with the number of the port it is cabled to, by
     * label.
     *
     * @return array<string, int|null>
     */
    private function cabling(Floor $floor): array
    {
        return Jack::query()
            ->where('floor_id', $floor->id)
            ->with('panelPort')
            ->get()
            ->sortBy('label', SORT_NATURAL)
            ->mapWithKeys(fn (Jack $jack): array => [$jack->label => $jack->panelPort?->number])
            ->all();
    }

    /**
     * A panel kept on another site.
     */
    private function elsewhere(): PatchPanel
    {
        $site = Site::factory()->create(['name' => 'Atelier']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Hangar']);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $room = Room::factory()->technical()->create(['floor_id' => $floor->id, 'name' => 'Baie']);

        return PatchPanel::factory()->create(['room_id' => $room->id, 'name' => 'PB-Z', 'port_count' => 12]);
    }

    // --- One jack -------------------------------------------------------------

    public function test_an_editor_creates_a_jack_on_a_floor(): void
    {
        $this->create(['patch_panel_port_id' => $this->port(15)->id, 'description' => 'Sous le bureau'])
            ->assertRedirect(route('sites.show', $this->office))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Prise créée.');

        $jack = Jack::query()->sole();

        $this->assertSame(
            [$this->first->id, 'B.15', 'Bureau 12', $this->port(15)->id, Status::Active, 'Sous le bureau'],
            [$jack->floor_id, $jack->label, $jack->location, $jack->patch_panel_port_id, $jack->status, $jack->description],
        );

        $created = $this->lastEntry(ActivityEvent::JackCreated);

        $this->assertSame('Bâtiment principal › 1er étage › B.15 (Siège)', $created->getProperty('label'));
        $this->assertSame(
            [
                'floor' => 'Bâtiment principal › 1er étage',
                'label' => 'B.15',
                'location' => 'Bureau 12',
                'panel_port' => 'Bâtiment principal › 1er étage › Local technique › PB-A 15',
                'status' => 'active',
                'description' => 'Sous le bureau',
            ],
            ($created->attribute_changes?->toArray() ?? [])['attributes'],
        );

        // Without a port of a panel, an empty location and description are
        // kept as nothing.
        $this->create(['label' => 'B.16', 'location' => '  ', 'patch_panel_port_id' => null])->assertSessionHasNoErrors();

        $alone = Jack::query()->where('label', 'B.16')->sole();

        $this->assertNull($alone->location);
        $this->assertNull($alone->patch_panel_port_id);
        $this->assertNull($alone->description);
    }

    public function test_the_shape_of_a_jack_is_checked(): void
    {
        $this->assertRefused($this->create(['label' => '']), 'label');
        $this->assertRefused($this->create(['label' => str_repeat('a', 33)]), 'label');
        $this->assertRefused($this->create(['location' => str_repeat('a', 101)]), 'location');
        $this->assertRefused($this->create(['patch_panel_port_id' => 'quinze']), 'patch_panel_port_id');
        $this->assertRefused($this->create(['status' => 'broken']), 'status');
        $this->assertRefused($this->create(['description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('jacks', 0);

        // At the limits, a jack is accepted.
        $this->create(['label' => str_repeat('a', 32), 'location' => str_repeat('b', 100), 'description' => str_repeat('c', 1000)])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('jacks', 1);
    }

    public function test_the_label_of_a_jack_is_unique_within_its_floor_whatever_its_case(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertRefused($this->create(['label' => 'b.15']), 'label', "L'étage a déjà une prise libellée B.15.");

        // Another floor has its own labels.
        $this->create([], $this->ground)->assertSessionHasNoErrors();

        // A jack keeps its own label when it is changed.
        $jack = Jack::query()->where('floor_id', $this->first->id)->sole();

        $this->update($jack, ['location' => 'Bureau 14'])->assertSessionHasNoErrors();

        $this->assertSame('Bureau 14', $jack->refresh()->location);
        $this->assertDatabaseCount('jacks', 2);
    }

    public function test_a_jack_is_cabled_to_a_free_port_of_a_panel_of_its_site(): void
    {
        $taken = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.01', 'patch_panel_port_id' => $this->port(1)->id]);
        $other = $this->elsewhere();

        $this->assertRefused(
            $this->create(['patch_panel_port_id' => $this->port(1)->id]),
            'patch_panel_port_id',
            'Le port PB-A 1 a déjà la prise B.01.',
        );
        $this->assertRefused(
            $this->create(['patch_panel_port_id' => $this->port(3, $other)->id]),
            'patch_panel_port_id',
            'Le panneau PB-Z est sur le site Atelier : choisissez un panneau du site de la prise.',
        );
        $this->assertRefused(
            $this->create(['patch_panel_port_id' => 999999]),
            'patch_panel_port_id',
            "Ce port de panneau n'existe plus.",
        );

        $this->assertSame(['B.01' => 1], $this->cabling($this->first));

        // A jack of the ground floor is cabled to the panel of the first
        // floor: the panel is of the same site.
        $this->create(['patch_panel_port_id' => $this->port(2)->id], $this->ground)->assertSessionHasNoErrors();

        $this->assertSame(['B.15' => 2], $this->cabling($this->ground));

        // A jack keeps its own port, moves to another, or leaves the panel.
        $this->update($taken, ['location' => 'Accueil'])->assertSessionHasNoErrors();
        $this->assertSame(1, $taken->refresh()->panelPort?->number);

        $this->update($taken, ['patch_panel_port_id' => $this->port(5)->id])->assertSessionHasNoErrors();
        $this->assertSame(5, $taken->refresh()->panelPort?->number);

        $this->update($taken, ['patch_panel_port_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($taken->refresh()->patch_panel_port_id);
    }

    public function test_a_jack_is_deleted_once_no_address_is_plugged_into_it_and_its_port_stays_free(): void
    {
        $jack = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.15', 'patch_panel_port_id' => $this->port(15)->id]);
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet('192.168.100.0/24', ['site_id' => $this->office->id])->id, 'address' => '192.168.100.15', 'jack_id' => $jack->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('jacks.destroy', $jack)),
            'jack',
            "Une adresse est encore raccordée à cette prise : débranchez-la d'abord.",
        );
        $this->assertModelExists($jack);

        $address->update(['jack_id' => null]);

        $this->actingAs($this->editor)->delete(route('jacks.destroy', $jack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Prise supprimée.');

        $this->assertModelMissing($jack);
        $this->assertModelExists($this->port(15));
        $this->assertSame(1, $this->countEntries(ActivityEvent::JackDeleted));
    }

    public function test_a_floor_that_has_jacks_is_not_deleted(): void
    {
        Jack::factory()->count(2)->create(['floor_id' => $this->ground->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('floors.destroy', $this->ground)),
            'floor',
            "2 prises sont encore à cet étage : supprimez-les d'abord.",
        );
        $this->assertModelExists($this->ground);
    }

    // --- A series -------------------------------------------------------------

    public function test_jacks_are_created_as_a_series_and_cabled_in_order_to_the_ports_of_a_panel(): void
    {
        $this->series(['patch_panel_id' => $this->panel->id, 'first_port' => 1])
            ->assertRedirect(route('sites.show', $this->office))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '24 prises créées.');

        $expected = [];

        foreach (range(1, 24) as $number) {
            $expected[sprintf('B.%02d', $number)] = $number;
        }

        $this->assertSame($expected, $this->cabling($this->first));

        // One entry for the whole series, on its floor; none for each jack.
        $this->assertSame(1, $this->countEntries(ActivityEvent::FloorJacksAdded));
        $this->assertSame(0, $this->countEntries(ActivityEvent::JackCreated));

        $entry = $this->lastEntry(ActivityEvent::FloorJacksAdded);
        $added = ($entry->attribute_changes?->toArray() ?? [])['attributes'];

        $this->assertSame($this->first->id, $entry->subject_id);
        $this->assertSame(24, $added['jacks_count']);
        $this->assertStringStartsWith('B.01 (PB-A 1), B.02 (PB-A 2), ', $added['jacks']);
        $this->assertStringEndsWith('B.24 (PB-A 24)', $added['jacks']);
    }

    public function test_a_series_follows_its_prefix_its_suffix_its_digits_and_its_first_port(): void
    {
        // Without a panel: the jacks are not cabled yet.
        $this->series(['prefix' => 'R', 'suffix' => '-g', 'first_number' => 8, 'last_number' => 11, 'digits' => 1, 'status' => 'planned'], $this->ground)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '4 prises créées.');

        $this->assertSame(['R8-g' => null, 'R9-g' => null, 'R10-g' => null, 'R11-g' => null], $this->cabling($this->ground));
        $this->assertSame([Status::Planned], Jack::query()->where('floor_id', $this->ground->id)->get()->pluck('status')->unique()->values()->all());

        // From a port other than the first, and one jack alone.
        $this->series(['prefix' => 'C', 'first_number' => 1, 'last_number' => 1, 'digits' => 3, 'patch_panel_id' => $this->panel->id, 'first_port' => 20])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 prise créée.');

        $this->assertSame(['C001' => 20], $this->cabling($this->first));

        // A panel without a first port starts on its port 1.
        $this->series(['prefix' => 'D', 'first_number' => 1, 'last_number' => 2, 'digits' => 1, 'patch_panel_id' => $this->panel->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(['C001' => 20, 'D1' => 1, 'D2' => 2], $this->cabling($this->first));
    }

    public function test_a_series_is_created_whole_or_not_at_all(): void
    {
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.12']);
        Jack::factory()->create(['floor_id' => $this->ground->id, 'label' => 'A.01', 'patch_panel_port_id' => $this->port(20)->id]);

        // A label taken in the middle of the series.
        $this->assertRefused($this->series(), 'prefix', "L'étage a déjà une prise libellée B.12.");

        // A port of the panel that already has a jack.
        $this->assertRefused(
            $this->series(['prefix' => 'C.', 'patch_panel_id' => $this->panel->id, 'first_port' => 1]),
            'first_port',
            'Le port 20 de PB-A a déjà une prise.',
        );

        // Past the last port of the panel.
        $this->assertRefused(
            $this->series(['prefix' => 'C.', 'first_number' => 1, 'last_number' => 4, 'patch_panel_id' => $this->panel->id, 'first_port' => 22]),
            'first_port',
            'La série finirait sur le port 25 de PB-A, au-delà de son dernier port, le 24.',
        );

        // A panel of another site, or one that no longer exists.
        $this->assertRefused(
            $this->series(['prefix' => 'C.', 'patch_panel_id' => $this->elsewhere()->id, 'first_port' => 1, 'last_number' => 2]),
            'patch_panel_id',
            'Le panneau PB-Z est sur le site Atelier : choisissez un panneau du site de la prise.',
        );
        $this->assertRefused($this->series(['prefix' => 'C.', 'patch_panel_id' => 999999]), 'patch_panel_id', "Ce panneau de brassage n'existe plus.");

        // Too many jacks, labels too long, numbers in the wrong order.
        $this->assertRefused($this->series(['prefix' => 'C.', 'first_number' => 1, 'last_number' => 201]), 'last_number', 'Une série compte 200 prises au plus.');
        $this->assertRefused($this->series(['prefix' => str_repeat('x', 30), 'first_number' => 99, 'last_number' => 100]), 'prefix', 'Les libellés de cette série dépassent 32 caractères.');
        $this->assertRefused($this->series(['prefix' => 'C.', 'first_number' => 5, 'last_number' => 4]), 'last_number');
        $this->assertRefused($this->series(['prefix' => 'C.', 'digits' => 7]), 'digits');
        $this->assertRefused($this->series(['prefix' => 'C.', 'first_port' => 97]), 'first_port');

        // Nothing was created, and nothing was written to the log.
        $this->assertSame(['B.12' => null], $this->cabling($this->first));
        $this->assertSame(0, $this->countEntries(ActivityEvent::FloorJacksAdded));

        // At the limits, a series is accepted.
        $this->series(['prefix' => str_repeat('x', 29), 'first_number' => 1, 'last_number' => 200, 'digits' => 3])->assertSessionHasNoErrors();

        $this->assertSame(201, Jack::query()->where('floor_id', $this->first->id)->count());
    }

    // --- From the page of a panel ---------------------------------------------

    public function test_a_jack_of_the_site_is_cabled_to_a_port_of_a_panel_and_detached_from_it(): void
    {
        $jack = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.15']);

        $this->actingAs($this->editor)
            ->put(route('patch-panel-ports.jack.update', $this->port(15)), ['jack_id' => $jack->id])
            ->assertRedirect(route('patch-panels.show', $this->panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Prise reliée au port.');

        $this->assertSame(15, $jack->refresh()->panelPort?->number);

        // A jack already cabled elsewhere, a port that has a jack, a jack of
        // another site and a jack that no longer exists are refused.
        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('patch-panel-ports.jack.update', $this->port(16)), ['jack_id' => $jack->id]),
            'jack_id',
            "La prise B.15 est déjà reliée au port PB-A 15 : détachez-la d'abord.",
        );

        $second = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.16']);

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('patch-panel-ports.jack.update', $this->port(15)), ['jack_id' => $second->id]),
            'jack_id',
            'Le port PB-A 15 a déjà la prise B.15.',
        );

        $far = $this->elsewhere();
        $faraway = Jack::factory()->create(['floor_id' => $far->room->floor_id, 'label' => 'Z.01']);

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('patch-panel-ports.jack.update', $this->port(16)), ['jack_id' => $faraway->id]),
            'jack_id',
            'Le panneau PB-A est sur le site Siège : choisissez un panneau du site de la prise.',
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('patch-panel-ports.jack.update', $this->port(16)), ['jack_id' => 999999]),
            'jack_id',
            "Cette prise n'existe plus.",
        );
        $this->assertRefused($this->actingAs($this->editor)->put(route('patch-panel-ports.jack.update', $this->port(16)), []), 'jack_id');

        // Detached, the jack stays on its floor and the port is free.
        $this->actingAs($this->editor)
            ->delete(route('patch-panel-ports.jack.destroy', $this->port(15)))
            ->assertRedirect(route('patch-panels.show', $this->panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Prise détachée du port.');

        $this->assertNull($jack->refresh()->patch_panel_port_id);
        $this->assertModelExists($jack);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('patch-panel-ports.jack.destroy', $this->port(15))),
            'jack',
            "Ce port du panneau n'a pas de prise.",
        );

        // Cabled, then detached: two changes of the jack in the log.
        $this->assertSame(2, $this->countEntries(ActivityEvent::JackUpdated));
    }

    // --- Pages ----------------------------------------------------------------

    public function test_the_page_of_a_site_counts_the_jacks_of_each_floor_and_offers_its_panels_when_asked(): void
    {
        Jack::factory()->count(3)->create(['floor_id' => $this->first->id]);
        Jack::factory()->create(['floor_id' => $this->ground->id, 'patch_panel_port_id' => $this->port(2)->id]);
        $second = PatchPanel::factory()->create(['room_id' => $this->panel->room_id, 'name' => 'PB-10', 'port_count' => 2]);
        PatchPanel::factory()->create(['room_id' => $this->panel->room_id, 'name' => 'PB-9', 'port_count' => 1]);
        $this->elsewhere();

        $choices = null;

        $this->actingAs($this->reader())
            ->get(route('sites.show', $this->office))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$choices): void {
                $page
                    ->where('places.0.floors.0.jacks_count', 1)
                    ->where('places.0.floors.1.jacks_count', 3)
                    ->has('jackStatuses', 3)
                    ->where('jackStatuses.1', ['value' => 'active', 'label' => 'Active'])
                    // The panels are not there before a window asks.
                    ->missing('panelChoices')
                    ->reloadOnly('panelChoices', function (Assert $reload) use (&$choices): void {
                        $choices = $reload->toArray()['props']['panelChoices'];
                    });
            });

        $this->assertIsArray($choices);

        // The panels of the site only, by name, a number read as a number,
        // each with its ports that have no jack.
        $this->assertSame(['PB-9', 'PB-10', 'PB-A'], array_column($choices, 'name'));
        $this->assertSame(
            ['id' => $second->id, 'name' => 'PB-10', 'place' => 'Bâtiment principal › 1er étage › Local technique', 'port_count' => 2, 'free' => [
                ['id' => $this->port(1, $second)->id, 'number' => 1],
                ['id' => $this->port(2, $second)->id, 'number' => 2],
            ]],
            $choices[1],
        );
        $this->assertSame([1, ...range(3, 24)], array_column($choices[2]['free'], 'number'));
    }

    public function test_the_page_of_a_panel_offers_the_jacks_of_every_floor_of_its_site_that_are_not_cabled(): void
    {
        foreach (['B.10', 'B.2', 'b.1'] as $label) {
            Jack::factory()->create(['floor_id' => $this->first->id, 'label' => $label, 'location' => $label === 'B.2' ? 'Bureau 2' : null]);
        }

        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.3', 'patch_panel_port_id' => $this->port(3)->id]);
        // Another floor of the site: offered too, before the floor above.
        Jack::factory()->create(['floor_id' => $this->ground->id, 'label' => 'A.1']);
        // A jack of another site is not.
        Jack::factory()->create(['label' => 'Z.1']);

        $id = fn (string $label): int => (int) Jack::query()->where('label', $label)->value('id');

        $this->actingAs($this->reader())
            ->get(route('patch-panels.show', $this->panel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeJacks', [
                    ['floor' => $this->ground->id, 'place' => 'Bâtiment principal › Rez-de-chaussée', 'jacks' => [
                        ['id' => $id('A.1'), 'label' => 'A.1', 'location' => null],
                    ]],
                    ['floor' => $this->first->id, 'place' => 'Bâtiment principal › 1er étage', 'jacks' => [
                        ['id' => $id('b.1'), 'label' => 'b.1', 'location' => null],
                        ['id' => $id('B.2'), 'label' => 'B.2', 'location' => 'Bureau 2'],
                        ['id' => $id('B.10'), 'label' => 'B.10', 'location' => null],
                    ]],
                ])
                ->where('ports.2.jack.label', 'B.3'));

        // A jack of another floor is cabled from the page of the panel.
        $this->actingAs($this->editor)
            ->put(route('patch-panel-ports.jack.update', $this->port(5)), ['jack_id' => $id('A.1')])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->port(5)->id, Jack::query()->find($id('A.1'))?->patch_panel_port_id);

        // A jack of another site is refused by the server.
        $this->actingAs($this->editor)
            ->put(route('patch-panel-ports.jack.update', $this->port(6)), ['jack_id' => $id('Z.1')])
            ->assertSessionHasErrors('jack_id');

        $this->assertNull(Jack::query()->find($id('Z.1'))?->patch_panel_port_id);
    }

    // --- Rights and log ------------------------------------------------------

    public function test_a_reader_cannot_write_a_jack_and_a_guest_is_sent_to_the_login_page(): void
    {
        $jack = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.15']);
        $reader = $this->reader();

        $this->actingAs($reader)->post(route('jacks.store', $this->first), $this->payload(['label' => 'B.16']))->assertForbidden();
        $this->actingAs($reader)->post(route('jacks.series.store', $this->first), ['prefix' => 'C', 'first_number' => 1, 'last_number' => 2, 'digits' => 1, 'status' => 'active'])->assertForbidden();
        $this->actingAs($reader)->put(route('jacks.update', $jack), $this->payload(['location' => 'Ailleurs']))->assertForbidden();
        $this->actingAs($reader)->delete(route('jacks.destroy', $jack))->assertForbidden();
        $this->actingAs($reader)->put(route('patch-panel-ports.jack.update', $this->port(1)), ['jack_id' => $jack->id])->assertForbidden();

        $jack->update(['patch_panel_port_id' => $this->port(1)->id]);

        $this->actingAs($reader)->delete(route('patch-panel-ports.jack.destroy', $this->port(1)))->assertForbidden();

        $this->assertSame(['B.15'], Jack::query()->pluck('label')->all());
        $this->assertSame([$this->port(1)->id, null], [$jack->refresh()->patch_panel_port_id, $jack->location]);

        auth()->logout();

        $this->post(route('jacks.store', $this->first), $this->payload())->assertRedirect(route('login'));

        // An administrator writes them like an editor.
        $this->actingAs($this->admin())->put(route('jacks.update', $jack), $this->payload(['label' => 'B.15', 'patch_panel_port_id' => $this->port(1)->id, 'location' => 'Accueil']))->assertSessionHasNoErrors();

        $this->assertSame('Accueil', $jack->refresh()->location);
    }

    public function test_the_activity_log_tells_the_life_of_jacks_in_words(): void
    {
        $this->create(['label' => 'B.15', 'location' => ''])->assertSessionHasNoErrors();
        $this->series(['first_number' => 1, 'last_number' => 2, 'patch_panel_id' => $this->panel->id, 'first_port' => 1], $this->ground)->assertSessionHasNoErrors();

        $jack = Jack::query()->where('label', 'B.15')->sole();

        $this->actingAs($this->editor)->put(route('patch-panel-ports.jack.update', $this->port(15)), ['jack_id' => $jack->id])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Prise modifiée')
                ->where('entries.data.0.changes', [['field' => 'Port de panneau', 'old' => '—', 'new' => 'Bâtiment principal › 1er étage › Local technique › PB-A 15']])
                ->where('entries.data.1.event_label', 'Prises ajoutées')
                ->where('entries.data.1.changes', [
                    ['field' => 'Nombre de prises', 'old' => null, 'new' => '2'],
                    ['field' => 'Prises', 'old' => null, 'new' => 'B.01 (PB-A 1), B.02 (PB-A 2)'],
                ])
                ->where('entries.data.2.event_label', 'Prise créée')
                ->where('entries.data.2.changes', [
                    ['field' => 'Étage', 'old' => null, 'new' => 'Bâtiment principal › 1er étage'],
                    ['field' => 'Libellé', 'old' => null, 'new' => 'B.15'],
                    ['field' => 'Statut', 'old' => null, 'new' => 'Active'],
                ]));
    }
}
