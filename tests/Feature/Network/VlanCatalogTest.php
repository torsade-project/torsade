<?php

// torsade/tests/Feature/Network/VlanCatalogTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A VLAN of a site and the entry of the catalogue it follows: chosen in its
 * form, shown in the list and on its page, with what differs between them.
 */
class VlanCatalogTest extends NetworkTestCase
{
    private Site $site;

    private CatalogVlan $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'Head office']);
        $this->entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'site_id' => $this->site->id,
            'catalog_vlan_id' => $this->entry->id,
            'vid' => 10,
            'name' => 'Bureautique',
            'status' => 'active',
            ...$overrides,
        ];
    }

    // --- The form -----------------------------------------------------------

    public function test_the_form_offers_the_entries_of_the_catalogue_by_number(): void
    {
        $servers = CatalogVlan::factory()->create(['vid' => 5, 'name' => 'Serveurs']);

        $this->actingAs($this->editor())
            ->get(route('vlans.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('catalogEntries', [
                    ['id' => $servers->id, 'vid' => 5, 'name' => 'Serveurs'],
                    ['id' => $this->entry->id, 'vid' => 10, 'name' => 'Bureautique'],
                ])
                ->where('catalogEntry', null)
            );
    }

    public function test_the_form_can_open_with_a_site_and_an_entry_already_chosen(): void
    {
        $editor = $this->editor();

        // From the page of an entry: its number and its name are proposed.
        $this->actingAs($editor)
            ->get(route('vlans.create', ['site' => $this->site->id, 'catalog' => $this->entry->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('site', $this->site->id)
                ->where('catalogEntry', $this->entry->id)
            );

        $this->assertRefused($this->actingAs($editor)->get(route('vlans.create', ['catalog' => 999999999])), 'catalog');
    }

    public function test_a_vlan_is_created_from_an_entry_of_the_catalogue(): void
    {
        $this->actingAs($this->editor())
            ->post(route('vlans.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $vlan = Vlan::query()->sole();

        $this->assertSame($this->entry->id, $vlan->catalog_vlan_id);
        $this->assertTrue($vlan->catalogVlan?->is($this->entry));

        $this->assertSame(
            '10 — Bureautique',
            $this->lastEntry(ActivityEvent::VlanCreated)->attribute_changes?->toArray()['attributes']['catalog'],
        );
    }

    public function test_the_number_and_the_name_proposed_by_the_entry_can_be_changed(): void
    {
        // What is filled in from the entry stays free: the VLAN is created
        // as typed, and differs from its entry.
        $this->actingAs($this->editor())
            ->post(route('vlans.store'), $this->payload(['vid' => 110, 'name' => 'Bureaux']))
            ->assertSessionHasNoErrors();

        $vlan = Vlan::query()->sole();

        $this->assertSame([110, 'Bureaux', $this->entry->id], [$vlan->vid, $vlan->name, $vlan->catalog_vlan_id]);
    }

    public function test_the_entry_is_optional_and_must_exist(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('vlans.store'), $this->payload(['catalog_vlan_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Vlan::query()->sole()->catalog_vlan_id);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('vlans.store'), $this->payload(['catalog_vlan_id' => 999999999, 'vid' => 11, 'name' => 'Other'])),
            'catalog_vlan_id',
        );
    }

    public function test_an_entry_is_followed_by_one_vlan_per_site_at_most(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'catalog_vlan_id' => $this->entry->id]);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('vlans.store'), $this->payload(['vid' => 11, 'name' => 'Bureautique bis'])),
            'catalog_vlan_id',
            'Ce site a déjà un VLAN rattaché à cette entrée du catalogue (10 — Bureautique).',
        );

        // On another site, the same entry is free.
        $this->actingAs($editor)
            ->post(route('vlans.store'), $this->payload(['site_id' => $workshop->id]))
            ->assertSessionHasNoErrors();

        // And an existing VLAN cannot take it either, nor move to a site
        // where it is taken.
        $other = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 12, 'name' => 'Annexe']);

        $this->assertRefused(
            $this->actingAs($editor)->put(route('vlans.update', $other), $this->payload(['vid' => 12, 'name' => 'Annexe'])),
            'catalog_vlan_id',
            'Ce site a déjà un VLAN rattaché à cette entrée du catalogue (10 — Bureautique).',
        );

        $moving = Vlan::query()->where('site_id', $workshop->id)->sole();

        $this->assertRefused(
            $this->actingAs($editor)->put(route('vlans.update', $moving), $this->payload(['vid' => 13, 'name' => 'Déménagé'])),
            'catalog_vlan_id',
        );

        $this->assertSame(2, Vlan::query()->where('catalog_vlan_id', $this->entry->id)->count());
    }

    public function test_a_vlan_is_attached_to_an_entry_and_detached_from_its_form(): void
    {
        $editor = $this->editor();
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique']);

        $this->actingAs($editor)
            ->get(route('vlans.edit', $vlan))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.catalog_vlan_id', null)->has('catalogEntries', 1));

        $this->actingAs($editor)->put(route('vlans.update', $vlan), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame($this->entry->id, $vlan->refresh()->catalog_vlan_id);

        $attached = $this->lastEntry(ActivityEvent::VlanUpdated);

        $this->assertSame(
            ['old' => ['catalog' => null], 'attributes' => ['catalog' => '10 — Bureautique']],
            $attached->attribute_changes?->toArray(),
        );

        $this->actingAs($editor)
            ->get(route('vlans.edit', $vlan))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.catalog_vlan_id', $this->entry->id));

        $this->actingAs($editor)->put(route('vlans.update', $vlan), $this->payload(['catalog_vlan_id' => null]))->assertSessionHasNoErrors();

        $this->assertNull($vlan->refresh()->catalog_vlan_id);
        $this->assertSame(
            ['old' => ['catalog' => '10 — Bureautique'], 'attributes' => ['catalog' => null]],
            $this->lastEntry(ActivityEvent::VlanUpdated)->attribute_changes?->toArray(),
        );
    }

    // --- Deviations -------------------------------------------------------------

    public function test_a_vlan_that_differs_from_its_entry_is_never_refused_and_is_shown_as_a_deviation(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        $same = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'BUREAUTIQUE', 'catalog_vlan_id' => $this->entry->id]);
        $different = Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 110, 'name' => 'Bureaux', 'catalog_vlan_id' => $this->entry->id]);
        $free = Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 999, 'name' => 'Ailleurs']);

        // The name only differs by its case: no deviation.
        $this->actingAs($editor)
            ->get(route('vlans.show', $same))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vlan.catalog', ['id' => $this->entry->id, 'vid' => 10, 'name' => 'Bureautique'])
                ->where('vlan.deviations', []));

        $this->actingAs($editor)
            ->get(route('vlans.show', $different))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vlan.catalog.id', $this->entry->id)
                ->where('vlan.deviations', [
                    ['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10'],
                    ['kind' => 'vlan_name', 'reason' => 'Nom Bureaux, catalogue : Bureautique'],
                ]));

        // A VLAN outside the catalogue differs from nothing.
        $this->actingAs($editor)
            ->get(route('vlans.show', $free))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.catalog', null)->where('vlan.deviations', []));

        // One difference alone gives one deviation.
        $different->update(['name' => 'Bureautique']);

        $this->actingAs($editor)
            ->get(route('vlans.show', $different))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.deviations', [
                ['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10'],
            ]));

        $different->update(['vid' => 10, 'name' => 'Postes']);

        $this->actingAs($editor)
            ->get(route('vlans.show', $different))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.deviations', [
                ['kind' => 'vlan_name', 'reason' => 'Nom Postes, catalogue : Bureautique'],
            ]));
    }

    // --- Attaching a VLAN from its own page ------------------------------------

    public function test_the_page_of_a_vlan_proposes_the_entry_that_has_its_number_to_those_who_write_the_catalogue(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureaux']);

        foreach ([$this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)
                ->get(route('vlans.show', $vlan))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('vlan.catalog', null)
                    ->where('attachableEntry', ['id' => $this->entry->id, 'vid' => 10, 'name' => 'Bureautique']));
        }

        // A reader cannot write the catalogue: nothing is proposed.
        $this->actingAs($this->reader())
            ->get(route('vlans.show', $vlan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attachableEntry', null));
    }

    public function test_nothing_is_proposed_when_the_vlan_cannot_be_attached_in_one_action(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        $servers = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);

        // No entry has its number.
        $unknown = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 999, 'name' => 'Ailleurs']);
        // It follows an entry already: its own, or another one although the
        // catalogue has an entry with its number.
        $attached = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 110, 'name' => 'Bureautique', 'catalog_vlan_id' => $this->entry->id]);
        $elsewhere = Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 20, 'name' => 'Production', 'catalog_vlan_id' => $this->entry->id]);
        // Its site already follows the entry, with the VLAN 110.
        $second = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Ancienne bureautique']);

        foreach ([$unknown, $attached, $elsewhere, $second] as $vlan) {
            $this->actingAs($editor)
                ->get(route('vlans.show', $vlan))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('attachableEntry', null));
        }

        // The head office has no VLAN that follows the entry 20, and one
        // that bears its number.
        $free = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Serveurs']);

        $this->actingAs($editor)
            ->get(route('vlans.show', $free))
            ->assertInertia(fn (Assert $page) => $page
                ->where('attachableEntry', ['id' => $servers->id, 'vid' => 20, 'name' => 'Serveurs']));
    }

    public function test_the_button_of_the_page_attaches_the_vlan_logs_it_and_comes_back_to_the_page(): void
    {
        $editor = $this->editor();
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureaux']);
        $link = ['id' => $this->entry->id, 'vid' => 10, 'name' => 'Bureautique'];

        // The route of the attachment in a row, with this VLAN alone.
        $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $this->entry), ['vlans' => [$vlan->id], 'from_vlan' => true])
            ->assertRedirect(route('vlans.show', $vlan))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 VLAN rattaché.');

        $this->assertSame($this->entry->id, $vlan->refresh()->catalog_vlan_id);
        // The VLAN itself is left as it was.
        $this->assertSame([10, 'Bureaux'], [$vlan->vid, $vlan->name]);

        $this->assertSame(1, $this->countEntries(ActivityEvent::VlanUpdated));

        $logged = $this->lastEntry(ActivityEvent::VlanUpdated);

        $this->assertTrue($vlan->is($logged->subject));
        $this->assertTrue($editor->is($logged->causer));
        $this->assertSame(
            ['old' => ['catalog' => null], 'attributes' => ['catalog' => '10 — Bureautique']],
            $logged->attribute_changes?->toArray(),
        );

        // Its page then shows its entry and what differs, and proposes
        // nothing more; so does its line in the list.
        $this->actingAs($editor)
            ->get(route('vlans.show', $vlan))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vlan.catalog', $link)
                ->where('vlan.deviations', [['kind' => 'vlan_name', 'reason' => 'Nom Bureaux, catalogue : Bureautique']])
                ->where('attachableEntry', null));
        $this->actingAs($editor)
            ->get(route('vlans.index'))
            ->assertInertia(fn (Assert $page) => $page->where('vlans.0.catalog', $link));
    }

    public function test_the_button_of_the_page_goes_through_the_rules_of_the_attachment_in_a_row(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        // Another number than the entry: refused here as from the page of
        // the entry, and the refusal comes back to the page of the VLAN.
        $otherNumber = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 110, 'name' => 'Bureaux']);

        $response = $this->actingAs($editor)
            ->from(route('vlans.show', $otherNumber))
            ->post(route('catalog-vlans.attach', $this->entry), ['vlans' => [$otherNumber->id], 'from_vlan' => true]);

        $response->assertRedirect(route('vlans.show', $otherNumber));
        $this->assertRefused($response, 'vlans.0', "Ce VLAN porte le numéro 110, pas le 10 de l'entrée : rattachez-le depuis son formulaire.");

        // A site that already follows the entry.
        Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 15, 'name' => 'Bureautique', 'catalog_vlan_id' => $this->entry->id]);
        $second = Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Ancienne bureautique']);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $this->entry), ['vlans' => [$second->id], 'from_vlan' => true]),
            'vlans.0',
            'Le site Workshop a déjà un VLAN rattaché à cette entrée (15 — Bureautique).',
        );

        $this->assertNull($otherNumber->refresh()->catalog_vlan_id);
        $this->assertNull($second->refresh()->catalog_vlan_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanUpdated));

        // Where the request says it comes from is checked like the rest.
        $fine = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique']);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('catalog-vlans.attach', $this->entry), ['vlans' => [$fine->id], 'from_vlan' => 'page']),
            'from_vlan',
        );
        $this->assertNull($fine->refresh()->catalog_vlan_id);

        // Without it, the answer goes to the page of the entry, as before.
        $this->actingAs($editor)
            ->post(route('catalog-vlans.attach', $this->entry), ['vlans' => [$fine->id]])
            ->assertRedirect(route('catalog-vlans.show', $this->entry));
    }

    public function test_a_reader_cannot_attach_a_vlan_from_its_page(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique']);

        $this->actingAs($this->reader())
            ->post(route('catalog-vlans.attach', $this->entry), ['vlans' => [$vlan->id], 'from_vlan' => true])
            ->assertForbidden();

        $this->assertNull($vlan->refresh()->catalog_vlan_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanUpdated));
    }

    // --- The list -------------------------------------------------------------

    public function test_the_list_shows_the_entry_of_each_vlan_and_can_be_filtered_by_it(): void
    {
        $reader = $this->reader();
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        $servers = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);

        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'catalog_vlan_id' => $this->entry->id]);
        Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 110, 'name' => 'Bureautique', 'catalog_vlan_id' => $this->entry->id]);
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Serveurs', 'catalog_vlan_id' => $servers->id]);
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 99, 'name' => 'Divers']);

        $this->actingAs($reader)
            ->get(route('vlans.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vlans', 4)
                ->has('catalogEntries', 2)
                ->where('filters.catalog', '')
                ->where('vlans.0.vid', 10)
                ->where('vlans.0.catalog', ['id' => $this->entry->id, 'vid' => 10, 'name' => 'Bureautique'])
                ->where('vlans.0.deviations', [])
                ->where('vlans.2.vid', 99)
                ->where('vlans.2.catalog', null)
                ->where('vlans.3.vid', 110)
                ->where('vlans.3.deviations.0.reason', 'VID 110, catalogue : 10')
            );

        $this->actingAs($reader)
            ->get(route('vlans.index', ['catalog' => $this->entry->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vlans', 2)
                ->where('vlans.0.vid', 10)
                ->where('vlans.1.vid', 110)
                ->where('filters.catalog', (string) $this->entry->id));

        // The VLANs that follow no entry.
        $this->actingAs($reader)
            ->get(route('vlans.index', ['catalog' => 'none']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vlans', 1)
                ->where('vlans.0.name', 'Divers')
                ->where('filters.catalog', 'none'));

        // With the other filters.
        $this->actingAs($reader)
            ->get(route('vlans.index', ['catalog' => $this->entry->id, 'site' => $workshop->id]))
            ->assertInertia(fn (Assert $page) => $page->has('vlans', 1)->where('vlans.0.vid', 110));

        $this->assertRefused($this->actingAs($reader)->get(route('vlans.index', ['catalog' => 999999999])), 'catalog');
        $this->assertRefused($this->actingAs($reader)->get(route('vlans.index', ['catalog' => 'all'])), 'catalog');
    }
}
