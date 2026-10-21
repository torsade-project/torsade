<?php

// torsade/tests/Feature/Network/NetworkPolicyTest.php

namespace Tests\Feature\Network;

use App\Models\AddressBlock;
use App\Models\CatalogVlan;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each object of the network documentation has its policy, and all of them
 * say the same: any active account reads, editors and administrators write.
 */
class NetworkPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One object of each kind.
     *
     * @return list<Site|Vlan|Subnet|IpRange|IpAddress|CatalogVlan|AddressBlock>
     */
    private function objects(): array
    {
        $site = Site::factory()->create();
        $vlan = Vlan::factory()->create(['site_id' => $site->id]);
        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $vlan->id]);

        return [
            $site,
            $vlan,
            $subnet,
            IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]),
            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']),
            CatalogVlan::factory()->create(),
            AddressBlock::factory()->cidr('10.0.0.0/8')->create(),
        ];
    }

    public function test_the_three_roles_can_read(): void
    {
        $users = [
            User::factory()->reader()->create(),
            User::factory()->editor()->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($this->objects() as $object) {
            foreach ($users as $user) {
                $this->assertTrue($user->can('viewAny', $object::class));
                $this->assertTrue($user->can('view', $object));
            }
        }
    }

    public function test_only_editors_and_administrators_can_write(): void
    {
        $reader = User::factory()->reader()->create();
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();

        foreach ($this->objects() as $object) {
            $this->assertFalse($reader->can('create', $object::class));
            $this->assertTrue($editor->can('create', $object::class));
            $this->assertTrue($admin->can('create', $object::class));

            foreach (['update', 'delete'] as $ability) {
                $this->assertFalse($reader->can($ability, $object));
                $this->assertTrue($editor->can($ability, $object));
                $this->assertTrue($admin->can($ability, $object));
            }
        }
    }

    public function test_a_deactivated_account_is_granted_nothing(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();

        foreach ($this->objects() as $object) {
            $this->assertFalse($inactive->can('viewAny', $object::class));
            $this->assertFalse($inactive->can('view', $object));
            $this->assertFalse($inactive->can('create', $object::class));
            $this->assertFalse($inactive->can('update', $object));
            $this->assertFalse($inactive->can('delete', $object));
        }
    }

    public function test_the_models_are_stored_and_read_back(): void
    {
        [$site, $vlan, $subnet, $range, $address] = $this->objects();

        $this->assertTrue($vlan->site->is($site));
        $this->assertTrue($subnet->vlan?->is($vlan));
        $this->assertTrue($vlan->subnet?->is($subnet));
        $this->assertTrue($range->subnet->is($subnet));
        $this->assertTrue($address->subnet->is($subnet));

        $this->assertSame(1, $site->vlans()->count());
        $this->assertSame(1, $subnet->ipRanges()->count());
        $this->assertSame(1, $subnet->ipAddresses()->count());

        $subnet = Subnet::query()->sole();

        $this->assertSame('192.168.100.0', $subnet->network);
        $this->assertSame('192.168.100.0/24', $subnet->cidr());
        $this->assertSame(4, $subnet->ip_version);
        $this->assertSame('192.168.100.100', IpRange::query()->sole()->start_address);
        $this->assertSame('192.168.100.10', IpAddress::query()->sole()->address);
    }

    public function test_the_database_refuses_duplicates_and_orphans(): void
    {
        [$site, $vlan, $subnet] = $this->objects();

        $duplicates = [
            fn () => Site::factory()->create(['name' => $site->name]),
            fn () => Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vlan->vid]),
            fn () => Vlan::factory()->create(['site_id' => $site->id, 'name' => $vlan->name]),
            fn () => Subnet::factory()->cidr('192.168.100.0/24')->create(),
            fn () => Subnet::factory()->cidr('10.0.0.0/24')->create(['vlan_id' => $vlan->id]),
            fn () => IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']),
            // What is still referenced cannot be deleted.
            fn () => $site->delete(),
            fn () => $vlan->delete(),
            fn () => $subnet->delete(),
        ];

        foreach ($duplicates as $index => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$index} was accepted by the database.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
