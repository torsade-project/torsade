<?php

// torsade/tests/Feature/Network/SitePageTest.php

namespace Tests\Feature\Network;

use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\CatalogVlan;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The page of a site: its code, its description, its blocks, its VLANs with
 * the entry of the catalogue each one follows, its subnets that no VLAN
 * carries, and what differs from the standard of the organisation.
 */
class SitePageTest extends NetworkTestCase
{
    public function test_the_page_of_a_site_shows_everything_the_site_holds(): void
    {
        $site = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE', 'description' => 'Bureaux et salle serveurs']);
        $other = Site::factory()->create(['name' => 'Atelier']);

        // Its blocks, in the order of their addresses.
        $second = AddressBlock::factory()->cidr('10.11.0.0/16')->forSite($site)->create();
        $first = AddressBlock::factory()->cidr('10.10.0.0/16')->forSite($site)->create(['name' => 'Bureaux']);
        AddressBlock::factory()->cidr('10.20.0.0/16')->forSite($other)->create();
        AddressBlock::factory()->cidr('10.0.0.0/8')->create();

        // Its VLANs, by number, with or without an entry and a subnet.
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $servers = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 20, 'name' => 'Serveurs', 'status' => 'planned']);
        $offices = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 110, 'name' => 'Bureautique', 'catalog_vlan_id' => $entry->id]);
        Vlan::factory()->create(['site_id' => $other->id, 'vid' => 10, 'name' => 'Bureautique']);

        $carried = Subnet::factory()->cidr('10.10.10.0/24')->create(['vlan_id' => $offices->id, 'gateway' => '10.10.10.254']);
        IpAddress::factory()->create(['subnet_id' => $carried->id, 'address' => '10.10.10.10']);

        // Its subnets that no VLAN carries.
        $link = Subnet::factory()->cidr('10.10.255.0/31')->kind(SubnetKind::PointToPoint)->create(['site_id' => $site->id, 'name' => 'Lien inter-sites']);
        $carrier = Subnet::factory()->cidr('203.0.113.8/29')->kind(SubnetKind::External)->create(['site_id' => $site->id, 'gateway' => '203.0.113.9']);
        // Elsewhere, or with no site: not on this page.
        Subnet::factory()->cidr('10.20.5.0/24')->create(['site_id' => $other->id]);
        Subnet::factory()->cidr('172.16.0.0/30')->create();

        $this->actingAs($this->reader())
            ->get(route('sites.show', $site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/Show')
                ->where('site', [
                    'id' => $site->id, 'name' => 'Siège', 'code' => 'SIE', 'number' => null,
                    'status' => 'active', 'status_label' => 'Actif', 'description' => 'Bureaux et salle serveurs',
                    'vlans_count' => 2, 'blocks' => ['10.10.0.0/16', '10.11.0.0/16'],
                ])
                // Without an addressing rule, nothing is expected of the site.
                ->where('siteDeviations', [])
                ->where('rule', null)
                // Blocks.
                ->has('blocks', 2)
                ->where('blocks.0.id', $first->id)
                ->where('blocks.0.cidr', '10.10.0.0/16')
                ->where('blocks.0.name', 'Bureaux')
                ->where('blocks.0.size', 65536)
                // A /24 and a /31 of this site lie in it.
                ->where('blocks.0.occupied', 258)
                ->where('blocks.1.id', $second->id)
                ->where('blocks.1.occupied', 0)
                // VLANs.
                ->has('vlans', 2)
                ->where('vlans.0.id', $servers->id)
                ->where('vlans.0.vid', 20)
                ->where('vlans.0.status_label', 'Prévu')
                ->where('vlans.0.catalog', null)
                ->where('vlans.0.subnet', null)
                ->where('vlans.1.vid', 110)
                ->where('vlans.1.catalog', ['id' => $entry->id, 'vid' => 10, 'name' => 'Bureautique'])
                ->where('vlans.1.deviations', [['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10']])
                ->where('vlans.1.subnet.cidr', '10.10.10.0/24')
                ->where('vlans.1.subnet.documented_count', 1)
                // Subnets without a VLAN, in the order of their addresses.
                ->has('subnets', 2)
                ->where('subnets.0.id', $link->id)
                ->where('subnets.0.cidr', '10.10.255.0/31')
                ->where('subnets.0.name', 'Lien inter-sites')
                ->where('subnets.0.kind_label', 'Point à point')
                ->where('subnets.0.vlan', null)
                ->where('subnets.1.id', $carrier->id)
                ->where('subnets.1.kind_label', 'Externe')
                ->where('subnets.1.gateway', '203.0.113.9')
                // Deviations: the number of a VLAN. The carrier link is
                // outside of the blocks of the site, as such a link is
                // expected to be: nothing is said about it.
                ->where('deviations', [
                    ['type' => 'vlan', 'id' => $offices->id, 'label' => 'VLAN 110 — Bureautique', 'deviations' => [
                        ['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10'],
                    ]],
                ])
            );
    }

    public function test_the_page_of_an_empty_site_is_empty(): void
    {
        $site = Site::factory()->create(['name' => 'Annexe']);

        $this->actingAs($this->reader())
            ->get(route('sites.show', $site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('site.code', null)
                ->where('site.description', null)
                ->where('blocks', [])
                ->where('vlans', [])
                ->where('subnets', [])
                ->where('deviations', []));
    }

    public function test_a_site_edited_from_its_page_comes_back_to_it(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Siège']);

        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Siège social', 'code' => 'sie', 'from_page' => true])
            ->assertRedirect(route('sites.show', $site))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Siège social', 'SIE'], [$site->refresh()->name, $site->code]);

        // From the list, back to the list.
        $this->actingAs($editor)
            ->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Siège'])
            ->assertRedirect(route('sites.index'));

        $this->assertRefused(
            $this->actingAs($editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Siège', 'from_page' => 'perhaps']),
            'from_page',
        );
    }

    public function test_the_three_roles_read_the_page_of_a_site_and_a_guest_does_not(): void
    {
        $site = Site::factory()->create();

        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)->get(route('sites.show', $site))->assertOk();
        }

        $this->actingAs($this->reader())->get(route('sites.show', 999999999))->assertNotFound();

        auth()->logout();

        $this->get(route('sites.show', $site))->assertRedirect(route('login'));
    }
}
