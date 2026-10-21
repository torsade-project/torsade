<?php

// torsade/tests/Feature/Network/SiteStatusTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Site;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A site is planned, in service or closed, like the objects of its
 * network. The block created along with a site takes its status.
 */
class SiteStatusTest extends NetworkTestCase
{
    public function test_a_site_is_created_with_its_status_and_is_in_service_by_default(): void
    {
        $this->actingAs($this->editor())
            ->post(route('sites.store'), ['name' => 'Dépôt', 'status' => 'planned'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Status::Planned, Site::query()->where('name', 'Dépôt')->sole()->status);

        // What does not come from the form leaves the status out.
        $this->assertSame(Status::Active, Site::factory()->create()->refresh()->status);
        $this->assertSame(Status::Active, (new Site)->status);
    }

    public function test_the_status_is_required_and_is_one_of_the_three(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['name' => 'Dépôt']),
            'status',
            'Le champ statut est obligatoire.',
        );

        $this->assertRefused($this->actingAs($editor)->post(route('sites.store'), ['name' => 'Dépôt', 'status' => 'closed']), 'status');

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_a_change_of_status_is_saved_and_logged(): void
    {
        $site = Site::factory()->create(['name' => 'Agence']);

        $this->actingAs($this->editor())
            ->put(route('sites.update', $site), ['name' => 'Agence', 'status' => 'retired'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Status::Retired, $site->refresh()->status);

        $entry = $this->lastEntry(ActivityEvent::SiteUpdated);
        $changes = $entry->attribute_changes?->toArray() ?? [];

        $this->assertSame(['status' => 'active'], $changes['old']);
        $this->assertSame(['status' => 'retired'], $changes['attributes']);
    }

    public function test_the_log_writes_the_status_of_a_site_in_the_masculine(): void
    {
        $site = Site::factory()->create(['name' => 'Agence']);
        $editor = $this->editor();

        $this->actingAs($editor)->put(route('sites.update', $site), ['name' => 'Agence', 'status' => 'planned']);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Site modifié')
                ->where('entries.data.0.changes', [['field' => 'Statut', 'old' => 'Actif', 'new' => 'Prévu']]));
    }

    public function test_the_list_shows_the_status_of_each_site_and_filters_on_it(): void
    {
        Site::factory()->create(['name' => 'Siège']);
        Site::factory()->create(['name' => 'Dépôt', 'status' => Status::Planned]);
        Site::factory()->create(['name' => 'Ancienne agence', 'status' => Status::Retired]);

        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('sites.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/Index')
                ->has('sites', 3)
                ->where('sites.0.name', 'Ancienne agence')
                ->where('sites.0.status', 'retired')
                // Agreed with the site: "Retiré", not "Retirée".
                ->where('sites.0.status_label', 'Retiré')
                ->where('sites.1.status_label', 'Prévu')
                ->where('sites.2.status_label', 'Actif')
                ->where('filters', ['status' => ''])
                ->where('statuses', [
                    ['value' => 'planned', 'label' => 'Prévu'],
                    ['value' => 'active', 'label' => 'Actif'],
                    ['value' => 'retired', 'label' => 'Retiré'],
                ]));

        $this->actingAs($reader)
            ->get(route('sites.index', ['status' => 'planned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.name', 'Dépôt')
                ->where('filters', ['status' => 'planned']));

        // A status that does not exist filters nothing: it is refused.
        $this->actingAs($reader)->get(route('sites.index', ['status' => 'closed']))->assertSessionHasErrors('status');
    }

    public function test_the_page_of_a_site_shows_its_status(): void
    {
        $site = Site::factory()->create(['name' => 'Dépôt', 'status' => Status::Planned]);

        $this->actingAs($this->reader())
            ->get(route('sites.show', $site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/Show')
                ->where('site.status', 'planned')
                ->where('site.status_label', 'Prévu')
                ->has('statuses', 3));
    }

    public function test_the_block_created_in_the_window_of_a_site_takes_the_status_of_the_site(): void
    {
        AddressingRule::factory()->create();
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('sites.store'), ['name' => 'Dépôt', 'number' => 30, 'status' => 'planned', 'create_block' => true])
            ->assertSessionHasNoErrors();

        $block = AddressBlock::query()->whereNotNull('site_id')->sole();

        $this->assertSame('10.30.0.0/16', $block->cidr());
        $this->assertSame(Status::Planned, $block->status);

        // An existing site that gets its number, too: the status sent with
        // the number is the one of the block.
        $office = Site::factory()->create(['name' => 'Ancienne agence']);

        $this->actingAs($editor)
            ->put(route('sites.update', $office), ['name' => 'Ancienne agence', 'number' => 40, 'status' => 'retired', 'create_block' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(Status::Retired, AddressBlock::query()->where('site_id', $office->id)->sole()->status);

        // And a site in service gets a block in service.
        $this->actingAs($editor)
            ->post(route('sites.store'), ['name' => 'Siège', 'number' => 10, 'status' => 'active', 'create_block' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(Status::Active, AddressBlock::query()->whereRelation('site', 'name', 'Siège')->sole()->status);
    }

    public function test_the_block_created_where_it_is_reported_missing_takes_the_status_of_the_site(): void
    {
        AddressingRule::factory()->create();
        $editor = $this->editor();

        $planned = Site::factory()->create(['name' => 'Dépôt', 'number' => 30, 'status' => Status::Planned]);
        $retired = Site::factory()->create(['name' => 'Ancienne agence', 'number' => 40, 'status' => Status::Retired]);
        $active = Site::factory()->create(['name' => 'Siège', 'number' => 10]);

        // From the page of the site.
        $this->actingAs($editor)
            ->post(route('sites.expected-block.store', $planned))
            ->assertRedirect(route('sites.show', $planned))
            ->assertSessionHasNoErrors();

        // From the table of the addressing rule.
        $this->actingAs($editor)
            ->post(route('sites.expected-block.store', $retired), ['from_rule' => true])
            ->assertRedirect(route('addressing-rule.show'))
            ->assertSessionHasNoErrors();

        $this->actingAs($editor)->post(route('sites.expected-block.store', $active), ['from_rule' => true])->assertSessionHasNoErrors();

        $this->assertSame(
            ['10.10.0.0/16' => 'active', '10.30.0.0/16' => 'planned', '10.40.0.0/16' => 'retired'],
            AddressBlock::query()->whereNotNull('site_id')->orderBy('network')->get()
                ->mapWithKeys(fn (AddressBlock $block): array => [$block->cidr() => $block->status->value])
                ->all(),
        );

        // The log says so too.
        $created = $this->lastEntry(ActivityEvent::AddressBlockCreated);

        $this->assertSame('active', ($created->attribute_changes?->toArray() ?? [])['attributes']['status']);
    }
}
