<?php

// torsade/tests/Feature/Network/SiteNumberTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Site;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The number of a site: its rank in the addressing rule of the
 * organisation. With a rule, it gives the block the site is expected to
 * have, which can be declared along with the site.
 */
class SiteNumberTest extends NetworkTestCase
{
    /**
     * The usual rule: the site 20 is expected in 10.20.0.0/16.
     */
    private function rule(): AddressingRule
    {
        return AddressingRule::factory()->create();
    }

    // --- The number itself -----------------------------------------------------

    public function test_a_site_is_given_a_number_which_the_list_and_its_page_show(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Atelier', 'number' => 20])
            ->assertRedirect(route('sites.index'))
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();

        $this->assertSame(20, $site->number);

        $this->actingAs($this->reader())
            ->get(route('sites.index'))
            ->assertInertia(fn (Assert $page) => $page->where('sites.0.number', 20)->where('sites.0.blocks', [])->where('rule', null));
        $this->actingAs($this->reader())
            ->get(route('sites.show', $site))
            ->assertInertia(fn (Assert $page) => $page->where('site.number', 20)->where('siteDeviations', [])->where('rule', null));

        // It is logged like the rest of the site.
        $this->assertSame(20, $this->lastEntry(ActivityEvent::SiteCreated)->attribute_changes?->toArray()['attributes']['number']);

        $this->actingAs($editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier', 'number' => 21])->assertSessionHasNoErrors();

        $this->assertSame(
            ['old' => ['number' => 20], 'attributes' => ['number' => 21]],
            $this->lastEntry(ActivityEvent::SiteUpdated)->attribute_changes?->toArray(),
        );

        // And it can be taken away.
        $this->actingAs($editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier', 'number' => null])->assertSessionHasNoErrors();

        $this->assertNull($site->refresh()->number);
    }

    public function test_a_number_goes_from_0_to_65535(): void
    {
        $editor = $this->editor();

        foreach ([-1, 65536, 100000, 'vingt', 20.5] as $number) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Atelier', 'number' => $number]),
                'number',
            );
        }

        $this->assertDatabaseCount('sites', 0);

        foreach ([0, 65535] as $number) {
            $this->actingAs($editor)
                ->post(route('sites.store'), ['status' => 'active', 'name' => "Site {$number}", 'number' => $number])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame([0, 65535], Site::query()->orderBy('number')->pluck('number')->all());

        // A site does without one.
        $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Annexe'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Dépôt', 'number' => ''])->assertSessionHasNoErrors();

        $this->assertSame(2, Site::query()->whereNull('number')->count());
    }

    public function test_a_number_belongs_to_one_site_and_the_refusal_names_it(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        $office = Site::factory()->create(['name' => 'Siège', 'number' => 10]);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Dépôt', 'number' => 20]),
            'number',
            'Le site Atelier porte déjà le numéro 20.',
        );
        $this->assertRefused(
            $this->actingAs($editor)->put(route('sites.update', $office), ['status' => 'active', 'name' => 'Siège', 'number' => 20]),
            'number',
            'Le site Atelier porte déjà le numéro 20.',
        );

        $this->assertDatabaseCount('sites', 2);
        $this->assertSame(10, $office->refresh()->number);

        // A site keeps its own number when updated.
        $this->actingAs($editor)
            ->put(route('sites.update', $workshop), ['status' => 'active', 'name' => 'Atelier nord', 'number' => 20])
            ->assertSessionHasNoErrors();
    }

    // --- The block the rule expects ---------------------------------------------

    public function test_the_pages_of_sites_get_what_the_rule_works_out(): void
    {
        $this->rule();

        $site = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        AddressBlock::factory()->cidr('10.20.0.0/16')->forSite($site)->create();
        AddressBlock::factory()->cidr('10.21.0.0/16')->forSite($site)->create();

        $params = ['network' => '10.0.0.0', 'prefix_length' => 8, 'site_prefix_length' => 16, 'vlan_prefix_length' => 24, 'gateway_position' => 'first', 'ranges' => []];

        // The list: the rule, and the blocks each site has declared.
        $this->actingAs($this->reader())
            ->get(route('sites.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule', $params)
                ->where('sites.0.blocks', ['10.20.0.0/16', '10.21.0.0/16']));

        $this->actingAs($this->reader())
            ->get(route('sites.show', $site))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule', $params)
                ->where('site.blocks', ['10.20.0.0/16', '10.21.0.0/16'])
                ->where('siteDeviations', []));
    }

    public function test_a_site_is_created_with_the_block_expected_for_its_number(): void
    {
        $editor = $this->editor();

        $this->rule();

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'create_block' => true])
            ->assertRedirect(route('sites.index'))
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();
        $block = AddressBlock::query()->where('site_id', $site->id)->sole();

        $this->assertSame('10.20.0.0/16', $block->cidr());

        // Both are logged, the block as the block of the site.
        $this->assertSame(1, $this->countEntries(ActivityEvent::SiteCreated));

        $created = $this->lastEntry(ActivityEvent::AddressBlockCreated);

        $this->assertTrue($block->is($created->subject));
        $this->assertTrue($editor->is($created->causer));
        $this->assertSame('Atelier', $created->attribute_changes?->toArray()['attributes']['site']);

        // Nothing differs from the rule.
        $this->actingAs($editor)
            ->get(route('sites.show', $site))
            ->assertInertia(fn (Assert $page) => $page->where('siteDeviations', [])->where('site.blocks', ['10.20.0.0/16']));

        // Without the box, the site is created alone.
        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Siège', 'number' => 10, 'create_block' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sites', 2);
        $this->assertSame(1, AddressBlock::query()->whereNotNull('site_id')->count());
    }

    public function test_a_block_that_cannot_be_created_is_told_on_its_box_and_nothing_is_saved(): void
    {
        $editor = $this->editor();
        $neighbour = Site::factory()->create(['name' => 'Siège']);

        $this->rule();

        // The block of another site lies over the expected one.
        AddressBlock::factory()->cidr('10.20.0.0/15')->forSite($neighbour)->create();

        $response = $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'create_block' => true]);

        $this->assertRefused(
            $response,
            'create_block',
            'Le bloc 10.20.0.0/16 ne peut pas être créé. Ce bloc chevauche le bloc 10.20.0.0/15 du site Siège.',
        );
        $this->assertSame(['create_block'], $this->errorFields());

        // All or nothing: no site, no block, nothing in the log.
        $this->assertDatabaseCount('sites', 1);
        $this->assertSame(1, AddressBlock::query()->whereNotNull('site_id')->count());
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::AddressBlockCreated));

        // The same when an existing site is given a number.
        $site = Site::factory()->create(['name' => 'Atelier', 'description' => 'Production']);

        $this->assertRefused(
            $this->actingAs($editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'create_block' => true]),
            'create_block',
        );

        $this->assertNull($site->refresh()->number);
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteUpdated));

        // Without the box, the number is saved, and the block is reported
        // missing.
        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'from_page' => true])
            ->assertRedirect(route('sites.show', $site))
            ->assertSessionHasNoErrors();

        $this->actingAs($editor)
            ->get(route('sites.show', $site))
            ->assertInertia(fn (Assert $page) => $page->where('siteDeviations', [
                ['kind' => 'site_block_missing', 'reason' => 'Bloc attendu par la règle : 10.20.0.0/16'],
            ]));
    }

    public function test_an_existing_site_gets_its_expected_block_with_its_number(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Atelier']);

        $this->rule();

        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'create_block' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(20, $site->refresh()->number);
        $this->assertSame('10.20.0.0/16', AddressBlock::query()->where('site_id', $site->id)->sole()->cidr());

        // Asked again while the block is there: refused, and nothing changes.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier renommé', 'number' => 20, 'create_block' => true]),
            'create_block',
            'Le bloc 10.20.0.0/16 ne peut pas être créé. Ce bloc existe déjà.',
        );

        $this->assertSame('Atelier', $site->refresh()->name);
    }

    public function test_a_number_beyond_the_rule_is_accepted_and_shown_as_a_deviation(): void
    {
        $editor = $this->editor();

        $this->rule();

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Dépôt', 'number' => 300])
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();

        $this->assertSame(300, $site->number);

        $this->actingAs($editor)
            ->get(route('sites.show', $site))
            ->assertInertia(fn (Assert $page) => $page->where('siteDeviations', [
                ['kind' => 'site_number_outside_rule', 'reason' => 'Numéro 300 : la règle en permet de 0 à 255'],
            ]));

        // No block is expected for it: asking for one is refused, whole.
        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Agence', 'number' => 301, 'create_block' => true]),
            'create_block',
            "La règle permet les numéros 0 à 255 : elle n'attend aucun bloc pour le site 301.",
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Agence', 'create_block' => true]),
            'create_block',
            "Ce site n'a pas de numéro : la règle n'attend aucun bloc pour lui.",
        );

        $this->assertDatabaseCount('sites', 1);
    }

    public function test_without_a_rule_a_number_expects_nothing(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Atelier', 'number' => 20])
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();

        $this->actingAs($editor)
            ->get(route('sites.show', $site))
            ->assertInertia(fn (Assert $page) => $page->where('siteDeviations', [])->where('rule', null));

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Siège', 'number' => 10, 'create_block' => true]),
            'create_block',
            "Il n'y a pas de règle d'adressage : aucun bloc n'est attendu pour ce site.",
        );

        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('address_blocks', 0);
    }

    public function test_the_box_is_checked_like_the_rest(): void
    {
        $this->rule();

        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('sites.store'), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'create_block' => 'peut-être']),
            'create_block',
        );

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_a_reader_cannot_give_a_number_nor_create_a_block(): void
    {
        $site = Site::factory()->create(['name' => 'Atelier']);

        $this->rule();

        $this->actingAs($this->reader())
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Atelier', 'number' => 20, 'create_block' => true])
            ->assertForbidden();

        $this->assertNull($site->refresh()->number);
        $this->assertSame(0, AddressBlock::query()->whereNotNull('site_id')->count());
    }
}
