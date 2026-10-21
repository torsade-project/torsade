<?php

// torsade/tests/Feature/Network/CatalogVlanTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The VLAN catalogue of the organisation: the number and the name a VLAN is
 * expected to have on every site, and the VLANs of the sites attached to it.
 */
class CatalogVlanTest extends NetworkTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function vlan(Site $site, int $vid, string $name, ?CatalogVlan $entry = null, array $attributes = []): Vlan
    {
        return Vlan::factory()->create([
            'site_id' => $site->id,
            'vid' => $vid,
            'name' => $name,
            'catalog_vlan_id' => $entry?->id,
            ...$attributes,
        ]);
    }

    // --- The table of entries and sites ---------------------------------------

    public function test_the_table_crosses_the_entries_with_the_sites(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop', 'code' => 'WS']);
        $office = Site::factory()->create(['name' => 'Head office']);
        $depot = Site::factory()->create(['name' => 'Depot']);

        $servers = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'SERVEURS', 'description' => 'Server rooms']);
        $offices = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        CatalogVlan::factory()->create(['vid' => 99, 'name' => 'Administration']);

        // As the catalogue says.
        $this->vlan($office, 10, 'Bureautique', $offices);
        // Same name, whatever the case: no deviation.
        $this->vlan($office, 20, 'Serveurs', $servers);
        // Another number, and another name.
        $this->vlan($workshop, 110, 'Bureaux', $offices, ['status' => 'planned']);
        // Not attached: it does not appear in the table.
        $this->vlan($depot, 10, 'Bureautique');

        $this->actingAs($this->reader())
            ->get(route('catalog-vlans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/Index')
                // The sites by name, the entries by number.
                ->where('sites', [
                    ['id' => $depot->id, 'name' => 'Depot', 'code' => null],
                    ['id' => $office->id, 'name' => 'Head office', 'code' => null],
                    ['id' => $workshop->id, 'name' => 'Workshop', 'code' => 'WS'],
                ])
                ->has('entries', 3)
                ->where('entries.0.vid', 10)
                ->where('entries.0.name', 'Bureautique')
                ->has('entries.0.vlans', 2)
                ->where('entries.1.vid', 20)
                ->where('entries.1.description', 'Server rooms')
                ->has('entries.1.vlans', 1)
                ->where('entries.1.vlans.0.site_id', $office->id)
                ->where('entries.1.vlans.0.vid', 20)
                ->where('entries.1.vlans.0.deviations', [])
                // An entry that no VLAN follows: every cell is empty.
                ->where('entries.2.vid', 99)
                ->where('entries.2.vlans', [])
            );

        $cells = collect((array) $this->actingAs($this->reader())->get(route('catalog-vlans.index'))->inertiaProps('entries.0.vlans'))
            ->keyBy('site_id');

        // The cell of a site shows the number its VLAN really has.
        $this->assertSame(10, $cells[$office->id]['vid']);
        $this->assertSame([], $cells[$office->id]['deviations']);

        $this->assertSame(110, $cells[$workshop->id]['vid']);
        $this->assertSame('planned', $cells[$workshop->id]['status']);
        $this->assertSame(
            [
                ['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10'],
                ['kind' => 'vlan_name', 'reason' => 'Nom Bureaux, catalogue : Bureautique'],
            ],
            $cells[$workshop->id]['deviations'],
        );

        // No VLAN of the depot follows the entry: no cell for it.
        $this->assertFalse($cells->has($depot->id));
    }

    public function test_an_empty_catalogue_is_an_empty_table(): void
    {
        Site::factory()->create();

        $this->actingAs($this->reader())
            ->get(route('catalog-vlans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('entries', [])->has('sites', 1));
    }

    // --- Creating, changing, deleting -----------------------------------------

    public function test_an_editor_creates_an_entry_and_lands_on_its_page(): void
    {
        $response = $this->actingAs($this->editor())
            ->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Bureautique', 'description' => 'Postes de travail']);

        $entry = CatalogVlan::query()->sole();

        $response->assertRedirect(route('catalog-vlans.show', $entry))->assertSessionHasNoErrors();

        $this->assertSame([10, 'Bureautique', 'Postes de travail'], [$entry->vid, $entry->name, $entry->description]);
    }

    public function test_an_entry_needs_a_number_from_1_to_4094_and_a_name(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => '', 'name' => '']),
            'vid',
            'Le champ numéro de VLAN est obligatoire.',
        );
        $this->assertSame(['vid', 'name'], $this->errorFields());

        foreach ([0, 4095, -1, 'dix', 10.5] as $vid) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => $vid, 'name' => 'Bureautique']),
                'vid',
            );
        }

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => str_repeat('a', 65)]),
            'name',
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Bureautique', 'description' => str_repeat('a', 1001)]),
            'description',
        );

        $this->assertDatabaseCount('catalog_vlans', 0);

        foreach ([1, 4094] as $vid) {
            $this->actingAs($editor)
                ->post(route('catalog-vlans.store'), ['vid' => $vid, 'name' => str_repeat('a', 60).$vid])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('catalog_vlans', 2);
    }

    public function test_the_number_and_the_name_are_each_unique_in_the_catalogue(): void
    {
        $editor = $this->editor();
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $other = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);

        $numberTaken = 'Le VLAN 10 « Bureautique » est déjà au catalogue. Une entrée vaut pour tous les sites : rattachez-y le VLAN de ce site depuis la page de l\'entrée.';
        $nameTaken = 'Le nom « Bureautique » est déjà celui de l\'entrée 10.';

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Postes']),
            'vid',
            $numberTaken,
        );
        $this->assertSame(['vid'], $this->errorFields());

        // The name is written as the entry has it, whatever was typed.
        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => 30, 'name' => 'bureautique']),
            'name',
            $nameTaken,
        );
        $this->assertSame(['name'], $this->errorFields());

        $this->assertRefused(
            $this->actingAs($editor)->put(route('catalog-vlans.update', $other), ['vid' => 10, 'name' => 'Serveurs']),
            'vid',
            $numberTaken,
        );
        $this->assertRefused(
            $this->actingAs($editor)->put(route('catalog-vlans.update', $other), ['vid' => 20, 'name' => 'Bureautique']),
            'name',
            $nameTaken,
        );

        // An entry keeps its own number and name when updated.
        $this->actingAs($editor)
            ->put(route('catalog-vlans.update', $entry), ['vid' => 10, 'name' => 'Bureautique', 'description' => 'Bureaux'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('catalog_vlans', 2);
        $this->assertSame([20, 'Serveurs'], [$other->refresh()->vid, $other->name]);
    }

    public function test_a_number_or_a_name_already_taken_comes_back_with_the_entry_that_has_it(): void
    {
        $editor = $this->editor();
        $offices = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $servers = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);

        $officesLink = ['id' => $offices->id, 'vid' => 10, 'name' => 'Bureautique'];
        $serversLink = ['id' => $servers->id, 'vid' => 20, 'name' => 'Serveurs'];

        // The number alone: the form leads to the entry that has it.
        $this->actingAs($editor)
            ->from(route('catalog-vlans.index'))
            ->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Bureautique du dépôt'])
            ->assertRedirect(route('catalog-vlans.index'))
            ->assertInertiaFlash('catalogConflict', ['vid' => $officesLink, 'name' => null]);

        // The name alone.
        $this->actingAs($editor)
            ->post(route('catalog-vlans.store'), ['vid' => 30, 'name' => 'SERVEURS'])
            ->assertInertiaFlash('catalogConflict', ['vid' => null, 'name' => $serversLink]);

        // The number of one entry and the name of another: each message
        // goes with its own entry.
        $this->actingAs($editor)
            ->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Serveurs'])
            ->assertSessionHasErrors(['vid', 'name'])
            ->assertInertiaFlash('catalogConflict', ['vid' => $officesLink, 'name' => $serversLink]);

        $this->assertSame('Le VLAN 10 « Bureautique » est déjà au catalogue. Une entrée vaut pour tous les sites : rattachez-y le VLAN de ce site depuis la page de l\'entrée.', $this->error('vid'));
        $this->assertSame('Le nom « Serveurs » est déjà celui de l\'entrée 20.', $this->error('name'));

        // The same when an entry is changed.
        $this->actingAs($editor)
            ->from(route('catalog-vlans.show', $servers))
            ->put(route('catalog-vlans.update', $servers), ['vid' => 10, 'name' => 'Serveurs'])
            ->assertRedirect(route('catalog-vlans.show', $servers))
            ->assertInertiaFlash('catalogConflict', ['vid' => $officesLink, 'name' => null]);

        // The page that follows the refusal receives the entry, once.
        $this->actingAs($editor)
            ->get(route('catalog-vlans.show', $servers))
            ->assertInertia(fn (Assert $page) => $page->hasFlash('catalogConflict.vid', $officesLink));
        $this->actingAs($editor)
            ->get(route('catalog-vlans.show', $servers))
            ->assertInertia(fn (Assert $page) => $page->missingFlash('catalogConflict'));

        // A refusal for another reason leads nowhere, and so does a success.
        $this->actingAs($editor)
            ->post(route('catalog-vlans.store'), ['vid' => 4095, 'name' => 'Trop grand'])
            ->assertSessionHasErrors('vid')
            ->assertInertiaFlashMissing('catalogConflict');
        $this->actingAs($editor)
            ->post(route('catalog-vlans.store'), ['vid' => 30, 'name' => 'Téléphonie'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlashMissing('catalogConflict');

        $this->assertDatabaseCount('catalog_vlans', 3);
        $this->assertSame(1, $this->countEntries(ActivityEvent::CatalogVlanCreated));
    }

    public function test_the_catalogue_says_that_an_entry_stands_for_every_site(): void
    {
        // In the window where an entry is created.
        $window = 'An entry stands for every site: create it once, then attach the VLAN of each site to it.';

        $this->assertSame(
            'Une entrée vaut pour tous les sites : créez-la une fois, puis rattachez-y le VLAN de chaque site.',
            __($window),
        );
        $this->assertStringContainsString(
            $window,
            (string) file_get_contents(resource_path('js/components/network/CatalogVlanDialog.vue')),
        );

        // And first thing on the page of the catalogue.
        $introduction = 'An entry stands for every site. Here are the VLANs the organisation has standardised, and the VLAN of each site that follows each of them. A VLAN may differ from its entry: it is then shown as a deviation.';

        $this->assertStringStartsWith('Une entrée vaut pour tous les sites. ', __($introduction));
        $this->assertStringContainsString(
            $introduction,
            (string) file_get_contents(resource_path('js/pages/catalog/Index.vue')),
        );
    }

    public function test_changing_an_entry_leaves_its_vlans_as_they_are_and_shows_what_differs(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Head office']);
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $vlan = $this->vlan($site, 10, 'Bureautique', $entry);

        $this->actingAs($editor)
            ->put(route('catalog-vlans.update', $entry), ['vid' => 15, 'name' => 'Postes de travail'])
            ->assertRedirect(route('catalog-vlans.show', $entry))
            ->assertSessionHasNoErrors();

        $this->assertSame([15, 'Postes de travail'], [$entry->refresh()->vid, $entry->name]);
        $this->assertSame([10, 'Bureautique', $entry->id], [$vlan->refresh()->vid, $vlan->name, $vlan->catalog_vlan_id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanUpdated));

        $this->actingAs($editor)
            ->get(route('catalog-vlans.show', $entry))
            ->assertInertia(fn (Assert $page) => $page->where('entry.vlans.0.deviations', [
                ['kind' => 'vlan_vid', 'reason' => 'VID 10, catalogue : 15'],
                ['kind' => 'vlan_name', 'reason' => 'Nom Bureautique, catalogue : Postes de travail'],
            ]));
    }

    public function test_an_entry_with_no_vlan_is_deleted(): void
    {
        $entry = CatalogVlan::factory()->create();

        $this->actingAs($this->editor())
            ->delete(route('catalog-vlans.destroy', $entry))
            ->assertRedirect(route('catalog-vlans.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('catalog_vlans', 0);
    }

    public function test_an_entry_is_not_deleted_while_a_vlan_is_attached_to_it(): void
    {
        $editor = $this->editor();
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $first = $this->vlan(Site::factory()->create(), 10, 'Bureautique', $entry);

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('catalog-vlans.destroy', $entry)),
            'catalog_vlan',
            "Un VLAN est encore rattaché à cette entrée : détachez-le d'abord.",
        );

        $second = $this->vlan(Site::factory()->create(), 10, 'Bureautique', $entry);

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('catalog-vlans.destroy', $entry)),
            'catalog_vlan',
            "Des VLAN sont encore rattachés à cette entrée (2) : détachez-les d'abord.",
        );

        $this->assertDatabaseHas('catalog_vlans', ['id' => $entry->id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::CatalogVlanDeleted));

        // Once detached, the VLANs stay and the entry can go.
        Vlan::query()->update(['catalog_vlan_id' => null]);

        $this->actingAs($editor)->delete(route('catalog-vlans.destroy', $entry))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('catalog_vlans', 0);
        $this->assertDatabaseHas('vlans', ['id' => $first->id]);
        $this->assertDatabaseHas('vlans', ['id' => $second->id]);
    }

    public function test_the_life_of_an_entry_is_logged(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Bureautique']);

        $entry = CatalogVlan::query()->sole();
        $created = $this->lastEntry(ActivityEvent::CatalogVlanCreated);

        $this->assertTrue($editor->is($created->causer));
        $this->assertTrue($entry->is($created->subject));
        $this->assertSame(
            ['vid' => 10, 'name' => 'Bureautique', 'description' => null],
            $created->attribute_changes?->toArray()['attributes'],
        );
        $this->assertSame('10 — Bureautique', $created->getProperty('label'));

        $this->actingAs($editor)->put(route('catalog-vlans.update', $entry), ['vid' => 11, 'name' => 'Bureautique']);

        $updated = $this->lastEntry(ActivityEvent::CatalogVlanUpdated);

        $this->assertSame(['old' => ['vid' => 10], 'attributes' => ['vid' => 11]], $updated->attribute_changes?->toArray());
        $this->assertSame('11 — Bureautique', $updated->getProperty('label'));

        // Saving the same values writes nothing.
        $this->actingAs($editor)->put(route('catalog-vlans.update', $entry), ['vid' => 11, 'name' => 'Bureautique']);

        $this->assertSame(1, $this->countEntries(ActivityEvent::CatalogVlanUpdated));

        $this->actingAs($editor)->delete(route('catalog-vlans.destroy', $entry));

        $deleted = $this->lastEntry(ActivityEvent::CatalogVlanDeleted);

        $this->assertSame(11, $deleted->attribute_changes?->toArray()['old']['vid']);
        $this->assertNull($deleted->subject);
        $this->assertSame('11 — Bureautique', $deleted->getProperty('label'));
    }

    // --- The page of an entry ---------------------------------------------------

    public function test_the_page_of_an_entry_lists_its_vlans_and_those_that_could_be_attached(): void
    {
        $office = Site::factory()->create(['name' => 'Head office']);
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        $depot = Site::factory()->create(['name' => 'Depot']);

        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique', 'description' => 'Postes de travail']);
        $other = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);

        $attached = $this->vlan($office, 10, 'Bureautique', $entry);
        // Same number, attached to nothing: they could be this VLAN.
        $onWorkshop = $this->vlan($workshop, 10, 'Bureaux', null, ['status' => 'planned']);
        $onDepot = $this->vlan($depot, 10, 'Bureautique');
        // Another number, or already attached elsewhere: not proposed.
        $this->vlan($workshop, 11, 'Bureautique');
        $this->vlan($depot, 20, 'Serveurs', $other);

        $this->actingAs($this->reader())
            ->get(route('catalog-vlans.show', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/Show')
                ->where('entry.id', $entry->id)
                ->where('entry.vid', 10)
                ->where('entry.name', 'Bureautique')
                ->where('entry.description', 'Postes de travail')
                ->has('entry.vlans', 1)
                ->where('entry.vlans.0.id', $attached->id)
                ->where('entry.vlans.0.site_id', $office->id)
                ->has('sites', 3)
                // By the name of their site.
                ->where('candidates', [
                    [
                        'id' => $onDepot->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active', 'status_label' => 'Actif',
                        'site' => ['id' => $depot->id, 'name' => 'Depot'],
                    ],
                    [
                        'id' => $onWorkshop->id, 'vid' => 10, 'name' => 'Bureaux', 'status' => 'planned', 'status_label' => 'Prévu',
                        'site' => ['id' => $workshop->id, 'name' => 'Workshop'],
                    ],
                ])
            );
    }

    // --- Attaching several VLANs at once -------------------------------------------

    public function test_several_vlans_are_attached_at_once(): void
    {
        $editor = $this->editor();
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $first = $this->vlan(Site::factory()->create(['name' => 'Head office']), 10, 'Bureautique');
        $second = $this->vlan(Site::factory()->create(['name' => 'Workshop']), 10, 'Bureaux');
        $third = $this->vlan(Site::factory()->create(['name' => 'Depot']), 10, 'Bureautique');

        $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $entry), ['vlans' => [$first->id, $second->id]])
            ->assertRedirect(route('catalog-vlans.show', $entry))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 VLAN rattachés.');

        $this->assertSame($entry->id, $first->refresh()->catalog_vlan_id);
        $this->assertSame($entry->id, $second->refresh()->catalog_vlan_id);
        $this->assertNull($third->refresh()->catalog_vlan_id);

        // Each VLAN keeps its number and its name; each change is logged.
        $this->assertSame('Bureaux', $second->name);
        $this->assertSame(2, $this->countEntries(ActivityEvent::VlanUpdated));

        $logged = $this->lastEntry(ActivityEvent::VlanUpdated);

        $this->assertTrue($second->is($logged->subject));
        $this->assertTrue($editor->is($logged->causer));
        $this->assertSame(
            ['old' => ['catalog' => null], 'attributes' => ['catalog' => '10 — Bureautique']],
            $logged->attribute_changes?->toArray(),
        );

        $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $entry), ['vlans' => [$third->id]])
            ->assertInertiaFlash('toast.message', '1 VLAN rattaché.');
    }

    public function test_a_vlan_whose_site_already_follows_the_entry_is_refused_on_its_line_and_nothing_is_attached(): void
    {
        $editor = $this->editor();
        $office = Site::factory()->create(['name' => 'Head office']);
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);

        // The head office already follows the entry, with another number.
        $this->vlan($office, 110, 'Bureautique', $entry);

        $fine = $this->vlan(Site::factory()->create(['name' => 'Workshop']), 10, 'Bureautique');
        $refused = $this->vlan($office, 10, 'Ancienne bureautique');
        $alsoFine = $this->vlan(Site::factory()->create(['name' => 'Depot']), 10, 'Bureautique');

        $response = $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $entry), ['vlans' => [$fine->id, $refused->id, $alsoFine->id]]);

        // The message is on the line of the VLAN in the way, and only there.
        $this->assertRefused(
            $response,
            'vlans.1',
            'Le site Head office a déjà un VLAN rattaché à cette entrée (110 — Bureautique).',
        );
        $this->assertSame(['vlans.1'], $this->errorFields());

        // All or nothing.
        $this->assertSame(1, Vlan::query()->whereNotNull('catalog_vlan_id')->count());
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanUpdated));

        // Without that line, the others pass.
        $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $entry), ['vlans' => [$fine->id, $alsoFine->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Vlan::query()->where('catalog_vlan_id', $entry->id)->count());
    }

    public function test_every_line_in_the_way_gets_its_own_message(): void
    {
        $editor = $this->editor();
        $office = Site::factory()->create(['name' => 'Head office']);
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $other = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);

        $attachedHere = $this->vlan(Site::factory()->create(['name' => 'Depot']), 10, 'Bureautique', $entry);
        $attachedElsewhere = $this->vlan(Site::factory()->create(['name' => 'Annex']), 20, 'Serveurs', $other);
        $fine = $this->vlan($office, 10, 'Bureautique');
        $otherNumber = $this->vlan($office, 11, 'Bureautique bis');

        $response = $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), [
            'vlans' => [$attachedHere->id, $attachedElsewhere->id, $fine->id, $otherNumber->id, 999999],
        ]);

        $response->assertRedirect();

        $this->assertSame(['vlans.0', 'vlans.1', 'vlans.3', 'vlans.4'], $this->errorFields());
        $this->assertSame('Ce VLAN est déjà rattaché à cette entrée.', $this->error('vlans.0'));
        $this->assertSame("Ce VLAN est déjà rattaché à l'entrée 20 — Serveurs.", $this->error('vlans.1'));
        $this->assertSame("Ce VLAN porte le numéro 11, pas le 10 de l'entrée : rattachez-le depuis son formulaire.", $this->error('vlans.3'));
        $this->assertSame("Ce VLAN n'existe plus.", $this->error('vlans.4'));

        $this->assertNull($fine->refresh()->catalog_vlan_id);
        $this->assertSame($other->id, $attachedElsewhere->refresh()->catalog_vlan_id);
    }

    public function test_a_vlan_with_another_number_is_refused_on_its_line_and_nothing_is_attached(): void
    {
        $editor = $this->editor();
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $fine = $this->vlan(Site::factory()->create(['name' => 'Head office']), 10, 'Bureautique');
        $otherNumber = $this->vlan(Site::factory()->create(['name' => 'Workshop']), 110, 'Bureautique');
        $alsoFine = $this->vlan(Site::factory()->create(['name' => 'Depot']), 10, 'Bureautique');

        $response = $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $entry), ['vlans' => [$fine->id, $otherNumber->id, $alsoFine->id]]);

        $this->assertRefused(
            $response,
            'vlans.1',
            "Ce VLAN porte le numéro 110, pas le 10 de l'entrée : rattachez-le depuis son formulaire.",
        );
        $this->assertSame(['vlans.1'], $this->errorFields());

        // All or nothing: the two VLANs with the right number wait too.
        $this->assertSame(0, Vlan::query()->whereNotNull('catalog_vlan_id')->count());
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanUpdated));

        // Alone, it is refused all the same.
        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), ['vlans' => [$otherNumber->id]]),
            'vlans.0',
        );
        $this->assertNull($otherNumber->refresh()->catalog_vlan_id);

        // The number the entry has now is the one that counts.
        $entry->update(['vid' => 110]);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), ['vlans' => [$fine->id]]),
            'vlans.0',
            "Ce VLAN porte le numéro 10, pas le 110 de l'entrée : rattachez-le depuis son formulaire.",
        );
    }

    public function test_a_vlan_with_another_number_is_still_attached_from_its_own_form_and_shown_as_a_deviation(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Workshop']);
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $vlan = $this->vlan($site, 110, 'Bureautique');

        // The catalogue describes the standard; what exists may differ, one
        // VLAN at a time and knowingly.
        $this->actingAs($editor)
            ->put(route('vlans.update', $vlan), [
                'site_id' => $site->id, 'catalog_vlan_id' => $entry->id, 'vid' => 110, 'name' => 'Bureautique', 'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($entry->id, $vlan->refresh()->catalog_vlan_id);

        $this->actingAs($editor)
            ->get(route('vlans.show', $vlan))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vlan.catalog', ['id' => $entry->id, 'vid' => 10, 'name' => 'Bureautique'])
                ->where('vlan.deviations', [['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10']]));
    }

    public function test_the_list_of_vlans_to_attach_is_checked(): void
    {
        $editor = $this->editor();
        $entry = CatalogVlan::factory()->create();
        $vlan = $this->vlan(Site::factory()->create(), 10, 'Bureautique');

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), []),
            'vlans',
            'Choisissez au moins un VLAN à rattacher.',
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), ['vlans' => 'all']),
            'vlans',
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), ['vlans' => [$vlan->id, $vlan->id]]),
            'vlans.0',
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $entry), ['vlans' => ['ten']]),
            'vlans.0',
        );

        $this->assertNull($vlan->refresh()->catalog_vlan_id);
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_reads_the_catalogue_but_cannot_write_it(): void
    {
        $reader = $this->reader();
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $vlan = $this->vlan(Site::factory()->create(), 10, 'Bureautique');

        $this->actingAs($reader)->get(route('catalog-vlans.index'))->assertOk();
        $this->actingAs($reader)->get(route('catalog-vlans.show', $entry))->assertOk();

        $this->actingAs($reader)->post(route('catalog-vlans.store'), ['vid' => 20, 'name' => 'Serveurs'])->assertForbidden();
        $this->actingAs($reader)->put(route('catalog-vlans.update', $entry), ['vid' => 11, 'name' => 'Renamed'])->assertForbidden();
        $this->actingAs($reader)->delete(route('catalog-vlans.destroy', $entry))->assertForbidden();
        $this->actingAs($reader)->post(route('catalog-vlans.attach', $entry), ['vlans' => [$vlan->id]])->assertForbidden();

        $this->assertSame([[10, 'Bureautique']], CatalogVlan::query()->get()->map(fn (CatalogVlan $entry): array => [$entry->vid, $entry->name])->all());
        $this->assertNull($vlan->refresh()->catalog_vlan_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanUpdated));
    }

    public function test_an_administrator_writes_the_catalogue_like_an_editor(): void
    {
        $this->actingAs($this->admin())
            ->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Bureautique'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('catalog_vlans', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $entry = CatalogVlan::factory()->create();

        $this->get(route('catalog-vlans.index'))->assertRedirect(route('login'));
        $this->get(route('catalog-vlans.show', $entry))->assertRedirect(route('login'));
        $this->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Bureautique'])->assertRedirect(route('login'));
        $this->post(route('catalog-vlans.attach', $entry), ['vlans' => [1]])->assertRedirect(route('login'));

        $this->assertDatabaseCount('catalog_vlans', 1);
    }

    public function test_an_unknown_entry_gives_a_404(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('catalog-vlans.show', 999999999))->assertNotFound();
        $this->actingAs($editor)->put(route('catalog-vlans.update', 999999999), ['vid' => 10, 'name' => 'Nowhere'])->assertNotFound();
        $this->actingAs($editor)->delete(route('catalog-vlans.destroy', 999999999))->assertNotFound();
        $this->actingAs($editor)->post(route('catalog-vlans.attach', 999999999), ['vlans' => [1]])->assertNotFound();
    }
}
