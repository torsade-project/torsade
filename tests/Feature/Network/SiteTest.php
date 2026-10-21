<?php

// torsade/tests/Feature/Network/SiteTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\AddressBlock;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

class SiteTest extends NetworkTestCase
{
    public function test_the_list_shows_the_sites_by_name_with_their_number_of_vlans(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop', 'description' => 'North building']);
        Site::factory()->create(['name' => 'Head office']);
        Vlan::factory()->count(2)->create(['site_id' => $workshop->id]);

        $this->actingAs($this->reader())
            ->get(route('sites.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/Index')
                ->has('sites', 2)
                ->where('sites.0.name', 'Head office')
                ->where('sites.0.vlans_count', 0)
                ->where('sites.1.name', 'Workshop')
                ->where('sites.1.description', 'North building')
                ->where('sites.1.vlans_count', 2));
    }

    public function test_an_editor_creates_a_site(): void
    {
        $this->actingAs($this->editor())
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'description' => 'Main building'])
            ->assertRedirect(route('sites.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sites', ['name' => 'Head office', 'description' => 'Main building']);
    }

    public function test_the_description_is_optional(): void
    {
        $this->actingAs($this->editor())
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Site::query()->sole()->description);
    }

    public function test_a_site_needs_a_name(): void
    {
        $response = $this->actingAs($this->editor())->post(route('sites.store'), ['status' => 'active', 'name' => '']);

        $this->assertRefused($response, 'name', 'Le champ nom est obligatoire.');
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_the_name_is_limited_to_100_characters_and_the_description_to_1000(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => str_repeat('a', 101)]),
            'name',
        );

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'description' => str_repeat('a', 1001)]),
            'description',
        );

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => str_repeat('a', 100), 'description' => str_repeat('b', 1000)])
            ->assertSessionHasNoErrors();
    }

    public function test_two_sites_cannot_have_the_same_name(): void
    {
        Site::factory()->create(['name' => 'Head office']);

        $response = $this->actingAs($this->editor())->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office']);

        // A readable message on the field, not a server error.
        $this->assertRefused($response, 'name', 'Un autre site porte déjà ce nom.');
        $this->assertDatabaseCount('sites', 1);
    }

    public function test_an_editor_updates_a_site(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);

        $this->actingAs($this->editor())
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Headquarters', 'description' => 'Renamed'])
            ->assertRedirect(route('sites.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'Headquarters', 'description' => 'Renamed']);
    }

    public function test_a_site_keeps_its_own_name_when_updated(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);

        $this->actingAs($this->editor())
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Head office', 'description' => 'Only the description changes'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_site_cannot_take_the_name_of_another_one(): void
    {
        Site::factory()->create(['name' => 'Head office']);
        $site = Site::factory()->create(['name' => 'Workshop']);

        $response = $this->actingAs($this->editor())->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Head office']);

        $this->assertRefused($response, 'name', 'Un autre site porte déjà ce nom.');
        $this->assertSame('Workshop', $site->refresh()->name);
    }

    public function test_an_empty_site_is_deleted(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->editor())
            ->delete(route('sites.destroy', $site))
            ->assertRedirect(route('sites.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_a_site_with_vlans_cannot_be_deleted(): void
    {
        $site = Site::factory()->create();
        Vlan::factory()->count(3)->create(['site_id' => $site->id]);

        $response = $this->actingAs($this->editor())->delete(route('sites.destroy', $site));

        $this->assertRefused($response, 'site', "Des VLAN sont encore rattachés à ce site (3) : supprimez-les d'abord.");
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertDatabaseCount('vlans', 3);
    }

    public function test_a_reader_reads_the_sites_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create(['name' => 'Head office']);

        $this->actingAs($reader)->get(route('sites.index'))->assertOk();

        $this->actingAs($reader)->post(route('sites.store'), ['status' => 'active', 'name' => 'Workshop'])->assertForbidden();
        $this->actingAs($reader)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Renamed'])->assertForbidden();
        $this->actingAs($reader)->delete(route('sites.destroy', $site))->assertForbidden();

        $this->assertSame(['Head office'], Site::query()->pluck('name')->all());
    }

    public function test_an_administrator_writes_sites_like_an_editor(): void
    {
        $this->actingAs($this->admin())
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sites', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);

        $this->get(route('sites.index'))->assertRedirect(route('login'));
        $this->post(route('sites.store'), ['status' => 'active', 'name' => 'Workshop'])->assertRedirect(route('login'));
        $this->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Renamed'])->assertRedirect(route('login'));
        $this->delete(route('sites.destroy', $site))->assertRedirect(route('login'));

        $this->assertSame(['Head office'], Site::query()->pluck('name')->all());
    }

    public function test_an_unknown_site_gives_a_404(): void
    {
        $this->actingAs($this->editor())->put(route('sites.update', 999999999), ['status' => 'active', 'name' => 'Nowhere'])->assertNotFound();
        $this->actingAs($this->editor())->delete(route('sites.destroy', 999999999))->assertNotFound();
    }

    // --- Code ---------------------------------------------------------------

    public function test_a_site_can_have_a_code_which_is_stored_in_capitals(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'code' => 'hv-1'])
            ->assertSessionHasNoErrors();

        $site = Site::query()->sole();

        $this->assertSame('HV-1', $site->code);

        // The list shows it.
        $this->actingAs($editor)
            ->get(route('sites.index'))
            ->assertInertia(fn (Assert $page) => $page->where('sites.0.code', 'HV-1'));

        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Head office', 'code' => 'Siege'])
            ->assertSessionHasNoErrors();

        $this->assertSame('SIEGE', $site->refresh()->code);

        // Emptied, it is no code at all.
        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Head office', 'code' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($site->refresh()->code);
    }

    public function test_the_code_is_optional(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Workshop'])->assertSessionHasNoErrors();

        // Two sites without a code do not collide.
        $this->assertSame([null, null], Site::query()->orderBy('name')->pluck('code')->all());
    }

    public function test_a_code_only_holds_letters_digits_and_hyphens_ten_at_most(): void
    {
        $editor = $this->editor();
        $message = 'Un code de site ne contient que des lettres sans accent, des chiffres et des tirets.';

        foreach (['HV 1', 'HV_1', 'SIÈGE', 'HV.1', 'HV/1', "HV\n1"] as $code) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'code' => $code]),
                'code',
                $message,
            );
        }

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'code' => 'ABCDEFGHIJK']),
            'code',
            'Le champ code ne doit pas dépasser 10 caractères.',
        );

        $this->assertDatabaseCount('sites', 0);

        $this->actingAs($editor)
            ->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'code' => 'ABCDE-1234'])
            ->assertSessionHasNoErrors();
    }

    public function test_two_sites_cannot_have_the_same_code_whatever_its_case(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['code' => 'HV']);
        $other = Site::factory()->create(['code' => 'ATL']);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Workshop', 'code' => 'hv']),
            'code',
            'Un autre site a déjà ce code.',
        );

        $this->assertRefused(
            $this->actingAs($editor)->put(route('sites.update', $other), ['status' => 'active', 'name' => $other->name, 'code' => 'HV']),
            'code',
            'Un autre site a déjà ce code.',
        );

        // A site keeps its own code when updated.
        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Renamed', 'code' => 'HV'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sites', 2);
    }

    public function test_a_change_of_code_is_logged(): void
    {
        $site = Site::factory()->create(['name' => 'Head office', 'code' => 'HV']);

        $this->actingAs($this->editor())->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Head office', 'code' => 'hq']);

        $entry = $this->lastEntry(ActivityEvent::SiteUpdated);
        $changes = $entry->attribute_changes?->toArray() ?? [];

        $this->assertSame(['code' => 'HV'], $changes['old']);
        $this->assertSame(['code' => 'HQ'], $changes['attributes']);
    }

    // --- What keeps a site from being deleted ---------------------------------

    public function test_a_site_with_a_subnet_cannot_be_deleted(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create();
        $subnet = $this->subnet('192.168.100.0/24', ['site_id' => $site->id]);

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('sites.destroy', $site)),
            'site',
            "Un sous-réseau est encore rattaché à ce site : supprimez-le ou changez son site d'abord.",
        );

        $this->subnet('192.168.101.0/24', ['site_id' => $site->id]);

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('sites.destroy', $site)),
            'site',
            "Des sous-réseaux sont encore rattachés à ce site (2) : supprimez-les ou changez leur site d'abord.",
        );

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::SiteDeleted));

        // Once its subnets are elsewhere, the site can go.
        Subnet::query()->update(['site_id' => null]);

        $this->actingAs($editor)->delete(route('sites.destroy', $site))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id]);
    }

    public function test_a_site_with_an_address_block_cannot_be_deleted(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create();
        AddressBlock::factory()->cidr('10.1.0.0/16')->forSite($site)->create();

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('sites.destroy', $site)),
            'site',
            "Un bloc d'adressage est encore rattaché à ce site : supprimez-le d'abord.",
        );

        AddressBlock::factory()->cidr('10.2.0.0/16')->forSite($site)->create();

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('sites.destroy', $site)),
            'site',
            "Des blocs d'adressage sont encore rattachés à ce site (2) : supprimez-les d'abord.",
        );

        $this->assertDatabaseHas('sites', ['id' => $site->id]);

        AddressBlock::query()->delete();

        $this->actingAs($editor)->delete(route('sites.destroy', $site))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_the_vlans_of_a_site_are_named_first_among_what_keeps_it(): void
    {
        $site = Site::factory()->create();
        $vlan = Vlan::factory()->create(['site_id' => $site->id]);
        $this->subnet('192.168.100.0/24', ['vlan_id' => $vlan->id]);
        AddressBlock::factory()->cidr('192.168.0.0/16')->forSite($site)->create();

        $this->assertRefused(
            $this->actingAs($this->editor())->delete(route('sites.destroy', $site)),
            'site',
            "Un VLAN est encore rattaché à ce site : supprimez-le d'abord.",
        );
    }
}
