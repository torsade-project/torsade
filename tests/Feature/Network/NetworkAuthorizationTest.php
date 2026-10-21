<?php

// torsade/tests/Feature/Network/NetworkAuthorizationTest.php

namespace Tests\Feature\Network;

use App\Models\AddressBlock;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceTemplate;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\JackCord;
use App\Models\Link;
use App\Models\PatchCord;
use App\Models\PatchPanel;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsRouteUris;

/**
 * Reading the network documentation is open to the three roles; writing it
 * is for editors and administrators. The routes are taken from the router
 * itself, so that a new one cannot be forgotten here.
 */
class NetworkAuthorizationTest extends NetworkTestCase
{
    use BuildsRouteUris;

    private const PREFIXES = [
        'sites', 'addressing-rule', 'catalog-vlans', 'vlans', 'address-blocks', 'subnets', 'ip-ranges', 'ip-addresses',
        'buildings', 'floors', 'rooms', 'devices', 'device-templates', 'device-members', 'ports', 'port-aggregates', 'links',
        'patch-panels', 'patch-panel-ports', 'jacks', 'patch-cords', 'jack-cords',
    ];

    /**
     * The abilities that only read.
     */
    private const READ_ABILITIES = ['viewAny', 'view'];

    /**
     * @return list<RoutingRoute>
     */
    private function networkRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => array_any(
                self::PREFIXES,
                fn (string $prefix): bool => $route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'),
            ),
        ));
    }

    /**
     * The policy ability a route asks for, read from its "can" middleware.
     */
    private function abilityOf(RoutingRoute $route): string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^can:(\w+),/', $middleware, $matches) === 1) {
                return $matches[1];
            }
        }

        $this->fail("[{$route->uri()}] names no policy ability.");
    }

    private function isRead(RoutingRoute $route): bool
    {
        return in_array($this->abilityOf($route), self::READ_ABILITIES, true);
    }

    private function method(RoutingRoute $route): string
    {
        return array_values(array_diff($route->methods(), ['HEAD']))[0];
    }

    public function test_every_network_route_names_a_policy_ability_that_matches_its_verb(): void
    {
        $routes = $this->networkRoutes();

        // Eight routes for sites, three for the addressing rule, six for
        // the catalogue, seven for VLANs, seven for blocks, eight for
        // subnets, three for ranges and four for IP addresses; then three
        // each for buildings, floors and rooms, two to create devices from
        // host names, seven for devices and three for their addresses;
        // then seven for device templates, four for the members of
        // devices, five for their ports, three for their aggregates and
        // four for the links between them; then four for the patch panels,
        // five for the jacks, two to cable a jack to a port of a panel,
        // five for the patch cords, four for the jack cords and two for the
        // layout of each of the physical and the logical diagrams of a site.
        $this->assertCount(114, $routes);

        foreach ($routes as $route) {
            $ability = $this->abilityOf($route);

            $this->assertContains($ability, ['viewAny', 'view', 'create', 'update', 'delete']);

            // Nothing is ever written behind an ability that only reads.
            if ($this->method($route) !== 'GET') {
                $this->assertNotContains($ability, self::READ_ABILITIES, "[{$route->uri()}] writes behind a reading ability.");
            }
        }
    }

    public function test_a_reader_receives_a_403_on_every_route_that_writes(): void
    {
        $reader = $this->reader();
        $checked = 0;

        foreach ($this->networkRoutes() as $route) {
            if ($this->isRead($route)) {
                continue;
            }

            $this->actingAs($reader)
                ->call($this->method($route), $this->uriOf($route, $reader))
                ->assertForbidden();

            $checked++;
        }

        // Every creation, update and deletion, and the forms that lead to them.
        $this->assertSame(97, $checked);

        // Nothing was touched.
        $this->assertDatabaseCount('addressing_rules', 0);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('catalog_vlans', 1);
        $this->assertDatabaseCount('address_blocks', 1);
        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_ranges', 1);
        $this->assertDatabaseCount('ip_addresses', 1);
        $this->assertDatabaseCount('buildings', 1);
        $this->assertDatabaseCount('floors', 1);
        // The room of the walk, and the one of the patch panel.
        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseCount('patch_panels', 1);
        $this->assertDatabaseCount('patch_panel_ports', 24);
        $this->assertDatabaseCount('jacks', 2);
        $this->assertDatabaseMissing('jacks', ['patch_panel_port_id' => null]);
        $this->assertDatabaseCount('patch_cords', 1);
        $this->assertDatabaseCount('jack_cords', 1);
        $this->assertDatabaseHas('jack_cords', ['color' => null]);
        // The device of the walk, the two a link joins, the one a cord goes
        // to and the one plugged into a jack.
        $this->assertDatabaseCount('devices', 5);
        $this->assertDatabaseCount('device_templates', 1);
        $this->assertDatabaseCount('device_template_port_groups', 2);
        $this->assertDatabaseCount('device_members', 5);
        $this->assertDatabaseCount('ports', 5);
        $this->assertDatabaseMissing('ports', ['enabled' => false]);
        $this->assertDatabaseHas('port_aggregates', ['name' => 'Po1']);
        $this->assertDatabaseCount('links', 1);
        $this->assertDatabaseCount('link_ends', 2);
        $this->assertDatabaseHas('links', ['status' => 'active', 'medium' => null]);
        // The address of the device is still attached to it.
        $this->assertDatabaseMissing('ip_addresses', ['device_id' => null]);
    }

    public function test_a_reader_is_refused_even_with_a_valid_payload(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create();
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($reader)->post(route('sites.store'), ['status' => 'active', 'name' => 'Workshop'])->assertForbidden();
        $this->actingAs($reader)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Renamed'])->assertForbidden();
        $this->actingAs($reader)->post(route('catalog-vlans.store'), ['vid' => 10, 'name' => 'Offices'])->assertForbidden();
        $this->actingAs($reader)->post(route('vlans.store'), [
            'site_id' => $site->id, 'vid' => 10, 'name' => 'Offices', 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($reader)->post(route('address-blocks.store'), ['network' => '10.0.0.0/8', 'status' => 'active'])->assertForbidden();
        $this->actingAs($reader)->post(route('subnets.store'), [
            'network' => '10.0.0.0/24', 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($reader)->post(route('ip-ranges.store', $subnet), [
            'kind' => 'dhcp', 'start_address' => '192.168.100.100', 'end_address' => '192.168.100.199',
        ])->assertForbidden();
        $this->actingAs($reader)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.100.10', 'assignment' => 'static', 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($reader)->post(route('ip-addresses.bulk', $subnet), [
            'addresses' => [['address' => '192.168.100.10']],
        ])->assertForbidden();

        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('catalog_vlans', 0);
        $this->assertDatabaseCount('address_blocks', 0);
        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_ranges', 0);
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_the_three_roles_can_read_every_page(): void
    {
        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $checked = 0;

            foreach ($this->networkRoutes() as $route) {
                if (! $this->isRead($route)) {
                    continue;
                }

                $this->actingAs($user)
                    ->get($this->uriOf($route, $user))
                    ->assertOk();

                $checked++;
            }

            // The nine lists, the pages of a site, of an entry of the
            // catalogue, of a VLAN, of a block, of a subnet, of a device
            // and of a patch panel, and the page of the addressing rule.
            $this->assertSame(17, $checked);
        }
    }

    public function test_editors_and_administrators_are_never_refused(): void
    {
        foreach ([$this->editor(), $this->admin()] as $user) {
            foreach ($this->networkRoutes() as $route) {
                $status = $this->actingAs($user)
                    ->call($this->method($route), $this->uriOf($route, $user))
                    ->getStatusCode();

                // An empty payload is refused by validation, never by the gate.
                $this->assertNotSame(403, $status, "[{$this->method($route)} {$route->uri()}] refused a {$user->role->value}.");
            }

            // The deletions of this walk were real: start again from scratch.
            $this->routeParameters = null;
            IpAddress::query()->delete();
            Link::query()->delete();
            PatchCord::query()->delete();
            JackCord::query()->delete();
            Device::query()->delete();
            Jack::query()->delete();
            PatchPanel::query()->delete();
            Room::query()->delete();
            Floor::query()->delete();
            Building::query()->delete();
            Subnet::query()->delete();
            Vlan::query()->delete();
            AddressBlock::query()->delete();
            Site::query()->delete();
            CatalogVlan::query()->delete();
            DeviceTemplate::query()->delete();
        }
    }

    public function test_a_guest_is_sent_to_the_login_page_by_every_route(): void
    {
        $account = User::factory()->create();

        foreach ($this->networkRoutes() as $route) {
            $this->call($this->method($route), $this->uriOf($route, $account))
                ->assertRedirect(route('login'));

            $this->assertGuest();
        }

        $this->assertDatabaseCount('sites', 1);
    }

    /**
     * The home page and the search read the whole documentation: they are
     * behind the gate that every active account passes.
     */
    public function test_the_home_page_and_the_search_are_behind_the_gate_of_reading(): void
    {
        // The former addresses of the search too, which lead to the new
        // ones.
        foreach (['dashboard', 'search', 'search.results', 'search.former', 'search.results.former'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains('can:view-network', $route->gatherMiddleware(), $name);
            $this->assertContains('auth', $route->gatherMiddleware(), $name);
        }

        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $this->actingAs($user)->get(route('search', ['q' => 'siège']))->assertOk();
            $this->actingAs($user)->getJson(route('search.results', ['q' => 'siège']))->assertOk();
            $this->actingAs($user)->get(route('search.former', ['q' => 'siège']))->assertStatus(301);
            $this->actingAs($user)->get(route('search.results.former', ['q' => 'siège']))->assertStatus(301);
        }
    }

    public function test_a_guest_is_sent_to_the_login_page_by_the_home_page_and_the_search(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('search', ['q' => 'siège']))->assertRedirect(route('login'));
        $this->getJson(route('search.results', ['q' => 'siège']))->assertUnauthorized();
        $this->get(route('search.former', ['q' => 'siège']))->assertRedirect(route('login'));
        $this->get(route('search.results.former', ['q' => 'siège']))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_only_an_administrator_receives_the_recent_activity(): void
    {
        $modules = fn (User $user): array => array_column(
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->inertiaProps('modules'),
            'data',
            'key',
        );

        foreach ([$this->reader(), $this->editor()] as $user) {
            $this->assertArrayNotHasKey('recent-activity', $modules($user));
        }

        $this->assertSame(route('activity-log.index'), $modules($this->admin())['recent-activity']['url'] ?? null);
    }

    public function test_a_deactivated_account_reads_nothing(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();

        $this->actingAs($inactive)->get(route('sites.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->assertFalse($inactive->can('viewAny', Site::class));
        $this->assertFalse($inactive->can('create', Site::class));
    }

    public function test_the_pages_tell_the_interface_whether_the_user_can_edit(): void
    {
        $permission = fn (User $user): mixed => $this->actingAs($user)
            ->get(route('sites.index'))
            ->inertiaProps('auth.can.editNetwork');

        // Only decides which buttons are shown: the routes check again.
        $this->assertFalse($permission($this->reader()));
        $this->assertTrue($permission($this->editor()));
        $this->assertTrue($permission($this->admin()));
    }
}
