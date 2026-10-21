<?php

// torsade/tests/Feature/Network/SearchPageTest.php

namespace Tests\Feature\Network;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\Network\GlobalSearch;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The page of results of a search, whose address can be shared: every
 * group, twenty-five results each.
 */
class SearchPageTest extends NetworkTestCase
{
    public function test_the_page_shows_every_group_twenty_five_results_each(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);

        for ($number = 1; $number <= 30; $number++) {
            Device::factory()->create(['site_id' => $site->id, 'name' => sprintf('poste-%02d', $number)]);
        }

        $this->actingAs($this->reader())
            ->get(route('search', ['q' => '  poste ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('search/Index')
                ->where('perPage', GlobalSearch::PAGE_LIMIT)
                ->where('results.query', 'poste')
                ->where('results.total', 30)
                ->where('results.groups.0.key', 'devices')
                ->where('results.groups.0.total', 30)
                ->has('results.groups.0.items', GlobalSearch::PAGE_LIMIT));
    }

    public function test_without_a_search_the_page_only_offers_to_type_one(): void
    {
        $this->actingAs($this->reader())
            ->get(route('search'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('search/Index')
                ->where('results', null));
    }

    public function test_a_search_too_long_is_refused_on_the_page_left_empty(): void
    {
        $this->actingAs($this->reader())
            ->from(route('dashboard'))
            ->get(route('search', ['q' => str_repeat('a', 101)]))
            ->assertRedirect(route('search'));

        $this->assertSame('Une recherche contient 100 caractères au plus.', $this->error('q'));
    }

    public function test_the_three_roles_open_the_page_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('search', ['q' => 'poste']))->assertRedirect(route('login'));
        $this->assertGuest();

        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)->get(route('search', ['q' => 'poste']))->assertOk();
        }
    }

    public function test_the_page_and_the_window_share_the_limit_of_searches(): void
    {
        $user = $this->reader();

        for ($search = 1; $search <= 60; $search++) {
            $this->actingAs($user)->get(route('search', ['q' => '192.0.2.1']))->assertOk();
            $this->actingAs($user)->getJson(route('search.results', ['q' => '192.0.2.1']))->assertOk();
        }

        $this->actingAs($user)->get(route('search', ['q' => '192.0.2.1']))->assertTooManyRequests();
        $this->actingAs($user)->getJson(route('search.results', ['q' => '192.0.2.1']))->assertTooManyRequests();
    }

    public function test_the_page_and_the_window_have_english_addresses(): void
    {
        $this->assertSame(url('/search?q=sw-'), route('search', ['q' => 'sw-']));
        $this->assertSame(url('/search/results?q=sw-'), route('search.results', ['q' => 'sw-']));
    }

    public function test_the_former_addresses_lead_to_the_new_ones_for_good_the_query_with_them(): void
    {
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get('/recherche?q=sw-etage-1')
            ->assertStatus(301)
            ->assertRedirect(route('search', ['q' => 'sw-etage-1']));

        $this->actingAs($reader)
            ->get('/recherche/resultats?q=poste&group=devices&page=2')
            ->assertStatus(301)
            ->assertRedirect(route('search.results', ['q' => 'poste', 'group' => 'devices', 'page' => 2]));

        // Without a query, the page itself.
        $this->actingAs($reader)->get('/recherche')->assertStatus(301)->assertRedirect(route('search'));
    }

    public function test_the_former_addresses_are_behind_the_gate_of_the_pages_they_lead_to(): void
    {
        // A guest is sent to the login page, not to the new address.
        $this->get('/recherche?q=sw')->assertRedirect(route('login'));
        $this->get('/recherche/resultats?q=sw')->assertRedirect(route('login'));

        // A deactivated account reads nothing.
        $this->actingAs(User::factory()->reader()->inactive()->create())
            ->get('/recherche?q=sw')
            ->assertRedirect(route('login'));
    }
}
