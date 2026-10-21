<?php

// torsade/tests/Feature/Network/DeviationTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviationKind;
use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\Deviations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The five ways what is documented can differ from the catalogue and from
 * the blocks of the organisation. Each one is worked out when a page is
 * shown, comes with its reason in words, is stored nowhere and refuses
 * nothing. The three ways it can differ from the addressing rule have
 * their own tests.
 */
class DeviationTest extends NetworkTestCase
{
    private Site $office;

    private Site $depot;

    private Deviations $deviations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->depot = Site::factory()->create(['name' => 'Dépôt']);
        $this->deviations = app(Deviations::class);
    }

    private function block(string $cidr, ?Site $site = null): AddressBlock
    {
        $factory = AddressBlock::factory()->cidr($cidr);

        return ($site === null ? $factory : $factory->forSite($site))->create();
    }

    /**
     * The deviations of a subnet, as the pages get them.
     *
     * @return list<array{kind: string, reason: string}>
     */
    private function of(Subnet $subnet): array
    {
        return $this->deviations->ofSubnet($subnet, $this->deviations->siteBlocks());
    }

    /**
     * What a page shows as deviations: its list, or the deviations of one
     * of its objects.
     *
     * @return list<array<string, mixed>>
     */
    private function shownOn(string $route, mixed $parameter, string $prop = 'deviations'): array
    {
        return (array) $this->actingAs($this->reader())->get(route($route, $parameter))->assertOk()->inertiaProps($prop);
    }

    public function test_there_are_twenty_five_deviations(): void
    {
        $this->assertSame(
            [
                // The catalogue and the blocks.
                'vlan_vid', 'vlan_name', 'subnet_in_block_of_another_site', 'subnet_outside_site_blocks', 'subnet_without_site_in_site_block',
                // The addressing rule.
                'site_number_outside_rule', 'site_block_missing', 'subnet_differs_from_rule',
                // The devices.
                'address_on_device_of_another_site', 'switch_merges_vlans',
                // The links between devices.
                'link_modes', 'link_access_vlans', 'link_native_vlans', 'link_tagged_vlans', 'link_merges_vlans',
                'aggregate_several_devices', 'aggregate_one_sided', 'link_on_disabled_port',
                // The cabling, and the cabled paths.
                'cord_across_rooms', 'cabling_without_link', 'link_contradicted_by_cabling', 'cabling_of_retired_link', 'linked_without_plug',
                'address_on_unpatched_jack', 'address_vlan_not_carried',
            ],
            array_column(DeviationKind::cases(), 'value'),
        );
    }

    // --- 1 and 2: a VLAN and its entry of the catalogue ---------------------------

    public function test_a_vlan_whose_number_differs_from_its_entry_is_shown_on_the_vlan_the_site_and_the_catalogue(): void
    {
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $vlan = Vlan::factory()->create(['site_id' => $this->depot->id, 'vid' => 110, 'name' => 'Bureautique', 'catalog_vlan_id' => $entry->id]);

        $expected = [['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10']];

        $this->assertSame($expected, $this->deviations->ofVlan($vlan->load('catalogVlan')));

        // On the VLAN, in the list of VLANs and on its page.
        $this->assertSame($expected, $this->shownOn('vlans.show', $vlan, 'vlan.deviations'));
        $this->assertSame($expected, $this->shownOn('vlans.index', [], 'vlans.0.deviations'));
        // On its site.
        $this->assertSame(
            [['type' => 'vlan', 'id' => $vlan->id, 'label' => 'VLAN 110 — Bureautique', 'deviations' => $expected]],
            $this->shownOn('sites.show', $this->depot),
        );
        $this->assertSame($expected, $this->shownOn('sites.show', $this->depot, 'vlans.0.deviations'));
        // In the catalogue, in the table and on the page of the entry.
        $this->assertSame($expected, $this->shownOn('catalog-vlans.index', [], 'entries.0.vlans.0.deviations'));
        $this->assertSame($expected, $this->shownOn('catalog-vlans.show', $entry, 'entry.vlans.0.deviations'));

        // Not on the other site.
        $this->assertSame([], $this->shownOn('sites.show', $this->office));
    }

    public function test_a_vlan_whose_name_differs_from_its_entry_whatever_the_case_is_shown_the_same_way(): void
    {
        $entry = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'SERVEURS']);
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 20, 'name' => 'SRV', 'catalog_vlan_id' => $entry->id]);

        $expected = [['kind' => 'vlan_name', 'reason' => 'Nom SRV, catalogue : SERVEURS']];

        $this->assertSame($expected, $this->deviations->ofVlan($vlan->load('catalogVlan')));
        $this->assertSame($expected, $this->shownOn('vlans.show', $vlan, 'vlan.deviations'));
        $this->assertSame($expected, $this->shownOn('sites.show', $this->office, 'deviations.0.deviations'));
        $this->assertSame($expected, $this->shownOn('catalog-vlans.index', [], 'entries.0.vlans.0.deviations'));

        // The same name in another case is the same name; accents count.
        foreach (['serveurs', 'Serveurs', 'SERVEURS'] as $name) {
            $vlan->update(['name' => $name]);

            $this->assertSame([], $this->deviations->ofVlan($vlan->refresh()->load('catalogVlan')), $name);
        }

        $entry->update(['name' => 'Téléphonie']);
        $vlan->update(['name' => 'TÉLÉPHONIE']);

        $this->assertSame([], $this->deviations->ofVlan($vlan->refresh()->load('catalogVlan')));

        $vlan->update(['name' => 'Telephonie']);

        $this->assertSame(
            [['kind' => 'vlan_name', 'reason' => 'Nom Telephonie, catalogue : Téléphonie']],
            $this->deviations->ofVlan($vlan->refresh()->load('catalogVlan')),
        );
    }

    public function test_a_vlan_outside_the_catalogue_or_like_its_entry_has_no_deviation(): void
    {
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);

        $free = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 999, 'name' => 'Ailleurs']);
        $same = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique', 'catalog_vlan_id' => $entry->id]);

        $this->assertSame([], $this->deviations->ofVlan($free->load('catalogVlan')));
        $this->assertSame([], $this->deviations->ofVlan($same->load('catalogVlan')));
        $this->assertSame([], $this->shownOn('sites.show', $this->office));
    }

    // --- 3: a subnet of a site in a block of another site -----------------------------

    public function test_a_subnet_in_a_block_of_another_site_is_shown_on_the_subnet_both_sites_and_the_block(): void
    {
        $block = $this->block('10.2.0.0/16', $this->depot);
        $subnet = $this->subnet('10.2.5.0/24', ['site_id' => $this->office->id]);

        $expected = [['kind' => 'subnet_in_block_of_another_site', 'reason' => 'Dans le bloc 10.2.0.0/16 du site Dépôt']];

        $this->assertSame($expected, $this->of($subnet));

        // On the subnet, on its page and in the list.
        $this->assertSame($expected, $this->shownOn('subnets.show', $subnet));
        $this->assertSame($expected, $this->shownOn('subnets.index', [], 'subnets.0.deviations'));
        // On its own site.
        $this->assertSame(
            [['type' => 'subnet', 'id' => $subnet->id, 'label' => '10.2.5.0/24', 'deviations' => $expected]],
            $this->shownOn('sites.show', $this->office),
        );
        // On the site of the block, told from that side.
        $this->assertSame(
            [[
                'type' => 'subnet',
                'id' => $subnet->id,
                'label' => '10.2.5.0/24',
                'deviations' => [['kind' => 'subnet_in_block_of_another_site', 'reason' => 'Sous-réseau du site Siège, dans le bloc 10.2.0.0/16 de ce site']],
            ]],
            $this->shownOn('sites.show', $this->depot),
        );
        // And on the block.
        $this->assertSame($expected, $this->shownOn('address-blocks.show', $block, 'subnets.0.deviations'));
    }

    public function test_a_subnet_carried_by_a_vlan_is_judged_on_the_site_of_that_vlan(): void
    {
        $this->block('10.2.0.0/16', $this->depot);
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique']);
        $subnet = Subnet::factory()->cidr('10.2.5.0/24')->create(['vlan_id' => $vlan->id]);

        $this->assertSame(
            [['kind' => 'subnet_in_block_of_another_site', 'reason' => 'Dans le bloc 10.2.0.0/16 du site Dépôt']],
            $this->of($subnet),
        );

        // The site of the VLAN lists it, under the subnet.
        $this->assertSame(
            [['type' => 'subnet', 'id' => $subnet->id, 'label' => '10.2.5.0/24']],
            array_map(fn (array $item): array => array_diff_key($item, ['deviations' => true]), $this->shownOn('sites.show', $this->office)),
        );
    }

    // --- 4: a segment of a site that has blocks, outside all of them -------------------

    public function test_a_subnet_outside_the_blocks_of_its_site_is_shown_on_the_subnet_and_on_the_site(): void
    {
        $this->block('10.1.0.0/16', $this->office);
        $this->block('10.3.0.0/16', $this->office);
        $subnet = $this->subnet('192.168.1.0/24', ['site_id' => $this->office->id]);

        $expected = [['kind' => 'subnet_outside_site_blocks', 'reason' => 'Hors des blocs du site (10.1.0.0/16, 10.3.0.0/16)']];

        $this->assertSame($expected, $this->of($subnet));
        $this->assertSame($expected, $this->shownOn('subnets.show', $subnet));
        $this->assertSame(
            [['type' => 'subnet', 'id' => $subnet->id, 'label' => '192.168.1.0/24', 'deviations' => $expected]],
            $this->shownOn('sites.show', $this->office),
        );

        // Inside one of them, it is where it should be.
        $inside = $this->subnet('10.3.4.0/24', ['site_id' => $this->office->id]);

        $this->assertSame([], $this->of($inside));
        $this->assertSame([], $this->shownOn('subnets.show', $inside));
    }

    public function test_the_reason_names_the_blocks_of_the_site_three_at_most(): void
    {
        $subnet = $this->subnet('192.168.1.0/24', ['site_id' => $this->office->id]);

        // The blocks of another site are never named.
        $this->block('10.200.0.0/16', $this->depot);

        $reason = fn (): string => $this->of($subnet)[0]['reason'];

        // Whatever the order they were created in, the blocks are named in
        // the order of their addresses.
        $this->block('10.3.0.0/16', $this->office);
        $this->assertSame('Hors des blocs du site (10.3.0.0/16)', $reason());

        $this->block('10.1.0.0/16', $this->office);
        $this->assertSame('Hors des blocs du site (10.1.0.0/16, 10.3.0.0/16)', $reason());

        $this->block('10.2.0.0/16', $this->office);
        $this->assertSame('Hors des blocs du site (10.1.0.0/16, 10.2.0.0/16, 10.3.0.0/16)', $reason());

        // From the fourth on, they are counted.
        $this->block('10.4.0.0/16', $this->office);
        $this->assertSame('Hors des blocs du site (10.1.0.0/16, 10.2.0.0/16, 10.3.0.0/16 et 1 de plus)', $reason());

        $this->block('10.5.0.0/16', $this->office);
        $this->block('10.6.0.0/24', $this->office);
        $this->assertSame('Hors des blocs du site (10.1.0.0/16, 10.2.0.0/16, 10.3.0.0/16 et 3 de plus)', $reason());

        // The pages say the same.
        $expected = [['kind' => 'subnet_outside_site_blocks', 'reason' => $reason()]];

        $this->assertSame($expected, $this->shownOn('subnets.show', $subnet));
        $this->assertSame($expected, $this->shownOn('sites.show', $this->office, 'deviations.0.deviations'));
        $this->assertSame($expected, $this->shownOn('subnets.index', [], 'subnets.0.deviations'));
    }

    public function test_a_site_without_any_block_compares_its_subnets_with_nothing(): void
    {
        $this->block('10.2.0.0/16', $this->depot);
        $this->block('10.0.0.0/8');

        // The head office has no block: its subnets are outside of nothing.
        $subnet = $this->subnet('192.168.1.0/24', ['site_id' => $this->office->id]);
        $other = $this->subnet('10.9.0.0/24', ['site_id' => $this->office->id]);

        $this->assertSame([], $this->of($subnet));
        $this->assertSame([], $this->of($other));
        $this->assertSame([], $this->shownOn('sites.show', $this->office));
    }

    public function test_in_a_block_of_another_site_a_subnet_is_also_outside_the_blocks_of_its_own(): void
    {
        $this->block('10.1.0.0/16', $this->office);
        $this->block('10.2.0.0/16', $this->depot);
        $subnet = $this->subnet('10.2.5.0/24', ['site_id' => $this->office->id]);

        // Both are true, and both are told.
        $this->assertSame(
            [
                ['kind' => 'subnet_in_block_of_another_site', 'reason' => 'Dans le bloc 10.2.0.0/16 du site Dépôt'],
                ['kind' => 'subnet_outside_site_blocks', 'reason' => 'Hors des blocs du site (10.1.0.0/16)'],
            ],
            $this->of($subnet),
        );
    }

    public function test_only_a_segment_is_expected_in_the_blocks_of_its_site(): void
    {
        $this->block('10.1.0.0/16', $this->office);

        // One subnet of each kind, all of the head office and all outside of
        // its block.
        $cidrs = [
            'segment' => '192.168.1.0/24',
            'point_to_point' => '192.168.2.0/31',
            'loopback' => '192.168.3.1/32',
            'tunnel' => '172.16.255.0/30',
            'external' => '203.0.113.8/29',
        ];

        // A kind added later has to be placed here, on one side or the other.
        $this->assertSame(array_column(SubnetKind::cases(), 'value'), array_keys($cidrs));

        $outside = [['kind' => 'subnet_outside_site_blocks', 'reason' => 'Hors des blocs du site (10.1.0.0/16)']];
        $segment = null;

        foreach ($cidrs as $kind => $cidr) {
            $subnet = Subnet::factory()->cidr($cidr)->kind(SubnetKind::from($kind))->create(['site_id' => $this->office->id]);
            $expected = $kind === 'segment' ? $outside : [];

            $this->assertSame($expected, $this->of($subnet), $kind);
            $this->assertSame($expected, $this->shownOn('subnets.show', $subnet), $kind);

            $segment ??= $subnet;
        }

        // The page of the site and the list of subnets only tell the segment.
        $this->assertSame(
            [['type' => 'subnet', 'id' => $segment->id, 'label' => '192.168.1.0/24', 'deviations' => $outside]],
            $this->shownOn('sites.show', $this->office),
        );
        $this->assertSame(
            ['192.168.1.0/24'],
            array_column(array_filter($this->shownOn('subnets.index', [], 'subnets'), fn (array $subnet): bool => $subnet['deviations'] !== []), 'cidr'),
        );
    }

    public function test_the_other_deviations_are_told_whatever_the_kind_of_the_subnet(): void
    {
        $this->block('10.1.0.0/16', $this->office);
        $block = $this->block('10.2.0.0/16', $this->depot);

        // For each kind: a subnet of the head office in the block of the
        // depot, and a subnet without a site in the block of the head office.
        $lengths = ['segment' => 24, 'point_to_point' => 31, 'loopback' => 32, 'tunnel' => 30, 'external' => 29];

        $this->assertSame(array_column(SubnetKind::cases(), 'value'), array_keys($lengths));

        $elsewhere = ['kind' => 'subnet_in_block_of_another_site', 'reason' => 'Dans le bloc 10.2.0.0/16 du site Dépôt'];
        $outside = ['kind' => 'subnet_outside_site_blocks', 'reason' => 'Hors des blocs du site (10.1.0.0/16)'];
        $third = 10;

        foreach ($lengths as $kind => $length) {
            $third++;

            $misplaced = Subnet::factory()->cidr("10.2.{$third}.0/{$length}")->kind(SubnetKind::from($kind))->create(['site_id' => $this->office->id]);
            $unowned = Subnet::factory()->cidr("10.1.{$third}.0/{$length}")->kind(SubnetKind::from($kind))->create();

            // In the block of another site: told for every kind. Only a
            // segment is, on top of that, outside of the blocks of its own.
            $this->assertSame($kind === 'segment' ? [$elsewhere, $outside] : [$elsewhere], $this->of($misplaced), $kind);

            $this->assertSame(
                [['kind' => 'subnet_without_site_in_site_block', 'reason' => 'Sans site, dans le bloc 10.1.0.0/16 du site Siège']],
                $this->of($unowned),
                $kind,
            );
        }

        // The site of the block lists the five of them, from its side.
        $this->assertSame(
            array_fill(0, 5, [['kind' => 'subnet_in_block_of_another_site', 'reason' => 'Sous-réseau du site Siège, dans le bloc 10.2.0.0/16 de ce site']]),
            array_column($this->shownOn('sites.show', $this->depot), 'deviations'),
        );
        // And so does the page of the block.
        $this->assertCount(5, array_filter($this->shownOn('address-blocks.show', $block, 'subnets'), fn (array $subnet): bool => in_array($elsewhere, $subnet['deviations'], true)));
    }

    // --- 5: a subnet without a site in the block of a site -----------------------------

    public function test_a_subnet_without_a_site_in_the_block_of_a_site_is_shown_on_the_subnet_and_on_the_block(): void
    {
        $block = $this->block('10.1.0.0/16', $this->office);
        $subnet = $this->subnet('10.1.7.0/24');

        $expected = [['kind' => 'subnet_without_site_in_site_block', 'reason' => 'Sans site, dans le bloc 10.1.0.0/16 du site Siège']];

        $this->assertSame($expected, $this->of($subnet));
        $this->assertSame($expected, $this->shownOn('subnets.show', $subnet));
        $this->assertSame($expected, $this->shownOn('address-blocks.show', $block, 'subnets.0.deviations'));

        // The page of the site lists its own subnets and those of other
        // sites: this one is told on the block.
        $this->assertSame([], $this->shownOn('sites.show', $this->office));

        // Without a site and outside of every block of a site: nothing to say.
        $this->block('172.16.0.0/12');
        $elsewhere = $this->subnet('172.16.0.0/30');

        $this->assertSame([], $this->of($elsewhere));
    }

    // --- What is no deviation ---------------------------------------------------------

    public function test_a_subnet_where_the_blocks_say_has_no_deviation(): void
    {
        $this->block('10.0.0.0/8');
        $this->block('10.1.0.0/16', $this->office);
        $this->block('192.168.1.0/24', $this->depot);

        // In the block of its site, exactly the block of its site, or
        // carried by a VLAN of the right site.
        $inside = $this->subnet('10.1.5.0/24', ['site_id' => $this->office->id]);
        $whole = $this->subnet('192.168.1.0/24', ['site_id' => $this->depot->id]);
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id]);
        $carried = Subnet::factory()->cidr('10.1.6.0/24')->create(['vlan_id' => $vlan->id]);

        foreach ([$inside, $whole, $carried] as $subnet) {
            $this->assertSame([], $this->of($subnet), $subnet->cidr());
        }

        // The blocks of the organisation set nothing about sites.
        $this->assertSame([], $this->of($this->subnet('10.200.0.0/24')));
    }

    // --- Never stored, never in the way ------------------------------------------------

    public function test_a_deviation_is_stored_nowhere(): void
    {
        $this->block('10.2.0.0/16', $this->depot);
        $subnet = $this->subnet('10.2.5.0/24', ['site_id' => $this->office->id]);

        $this->assertNotSame([], $this->of($subnet));

        // No table and no column holds it: it is worked out again each time.
        foreach (Schema::getTableListing() as $table) {
            $this->assertStringNotContainsString('deviation', $table);

            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertStringNotContainsString('deviation', $column, $table);
            }
        }

        // And it follows the data at once.
        DB::table('subnets')->where('id', $subnet->id)->update(['site_id' => $this->depot->id]);

        $this->assertSame([], $this->of($subnet->refresh()));
    }

    public function test_what_gives_a_deviation_is_never_refused(): void
    {
        $editor = $this->editor();
        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $this->block('10.1.0.0/16', $this->office);
        $this->block('10.2.0.0/16', $this->depot);

        // Another number and another name than the entry.
        $this->actingAs($editor)->post(route('vlans.store'), [
            'site_id' => $this->office->id, 'catalog_vlan_id' => $entry->id, 'vid' => 110, 'name' => 'Bureaux', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        // In the block of another site, outside of the blocks of its own
        // site, and without a site in the block of one.
        foreach ([['10.2.5.0/24', $this->office->id], ['192.168.1.0/24', $this->office->id], ['10.1.7.0/24', null]] as [$cidr, $site]) {
            $this->actingAs($editor)
                ->post(route('subnets.store'), ['network' => $cidr, 'site_id' => $site, 'status' => 'active'])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('subnets', 3);

        // A block created over what is already there is not refused either.
        $this->actingAs($editor)
            ->post(route('address-blocks.store'), ['network' => '192.168.0.0/16', 'site_id' => $this->depot->id, 'status' => 'active'])
            ->assertSessionHasNoErrors();

        // Nor is changing an entry that VLANs follow.
        $this->actingAs($editor)
            ->put(route('catalog-vlans.update', $entry), ['vid' => 11, 'name' => 'Postes'])
            ->assertSessionHasNoErrors();
    }
}
