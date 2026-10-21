<?php

// torsade/tests/Feature/Network/AddressBlockTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Address blocks: the addresses set aside for the organisation and for each
 * site. Only what makes no sense in any network is refused.
 */
class AddressBlockTest extends NetworkTestCase
{
    private Site $office;

    private Site $workshop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Head office']);
        $this->workshop = Site::factory()->create(['name' => 'Workshop']);
    }

    private function block(string $cidr, ?Site $site = null, array $attributes = []): AddressBlock
    {
        $factory = AddressBlock::factory()->cidr($cidr);

        return ($site === null ? $factory : $factory->forSite($site))->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $cidr, ?Site $site = null, array $overrides = []): array
    {
        return [
            'network' => $cidr,
            'site_id' => $site?->id,
            'status' => 'active',
            ...$overrides,
        ];
    }

    // --- The list, as a tree ----------------------------------------------------

    public function test_the_list_nests_the_blocks_by_their_addresses(): void
    {
        // Created in no particular order.
        $this->block('10.20.0.0/16', $this->workshop);
        $this->block('172.16.0.0/12', null, ['name' => 'Interconnections']);
        $this->block('10.0.0.0/8', null, ['name' => 'Private addressing']);
        $this->block('192.168.50.0/24', $this->workshop, ['status' => Status::Retired]);
        $this->block('10.10.0.0/16', $this->office, ['name' => 'Head office']);
        $this->block('10.0.0.0/16', null, ['name' => 'Core']);
        $this->block('10.0.4.0/22', null);
        $this->block('192.168.1.0/24', $this->office);

        $this->subnet('10.10.10.0/24');
        $this->subnet('10.10.20.0/25');
        $this->subnet('10.0.4.0/30');

        $this->actingAs($this->reader())
            ->get(route('address-blocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('blocks/Index')
                // Each block of the organisation, followed by what it holds.
                ->where('blocks', fn ($blocks) => collect($blocks)->map(fn (array $block): array => [$block['cidr'], $block['depth']])->all() === [
                    ['10.0.0.0/8', 0],
                    ['10.0.0.0/16', 1],
                    ['10.0.4.0/22', 2],
                    ['10.10.0.0/16', 1],
                    ['10.20.0.0/16', 1],
                    ['172.16.0.0/12', 0],
                ])
                ->where('blocks.0.name', 'Private addressing')
                ->where('blocks.0.site', null)
                ->where('blocks.0.size', 16777216)
                ->where('blocks.0.mask', '255.0.0.0')
                // The addresses of the block that belong to a subnet.
                ->where('blocks.0.occupied', 256 + 128 + 4)
                ->where('blocks.2.occupied', 4)
                ->where('blocks.3.site', ['id' => $this->office->id, 'name' => 'Head office'])
                ->where('blocks.3.occupied', 256 + 128)
                ->where('blocks.4.occupied', 0)
                ->where('blocks.5.status_label', 'Actif')
                // The blocks of sites that no block of the organisation holds.
                ->where('outside', fn ($blocks) => collect($blocks)->map(fn (array $block): array => [$block['cidr'], $block['depth'], $block['site']['name']])->all() === [
                    ['192.168.1.0/24', 0, 'Head office'],
                    ['192.168.50.0/24', 0, 'Workshop'],
                ])
                ->where('outside.1.status', 'retired')
            );
    }

    public function test_without_any_block_the_list_is_empty(): void
    {
        $this->actingAs($this->reader())
            ->get(route('address-blocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('blocks', [])->where('outside', []));
    }

    // --- Creation ---------------------------------------------------------------

    public function test_the_form_offers_sites_statuses_and_prefix_lengths(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->get(route('address-blocks.create', ['site' => $this->workshop->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('blocks/Form')
                ->where('block', null)
                ->where('site', $this->workshop->id)
                ->has('sites', 2)
                ->where('sites.0.name', 'Head office')
                ->has('statuses', 3)
                ->has('prefixes', 32));

        $this->assertRefused($this->actingAs($editor)->get(route('address-blocks.create', ['site' => 999999999])), 'site');
    }

    public function test_the_form_gets_the_segments_of_the_sites_and_their_blocks_to_count_with(): void
    {
        $editor = $this->editor();

        $block = $this->block('10.20.1.0/24', $this->workshop);
        // A block of the organisation says nothing about a site.
        $this->block('10.0.0.0/8');

        // The segments of the sites, carried by a VLAN or not.
        $vlan = Vlan::factory()->create(['site_id' => $this->workshop->id]);
        Subnet::factory()->cidr('10.20.30.0/24')->create(['vlan_id' => $vlan->id]);
        $this->subnet('10.20.10.0/24', ['site_id' => $this->workshop->id, 'status' => 'retired']);
        $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);

        // Left out: what is not expected in the blocks of a site, and a
        // segment that belongs to no site.
        Subnet::factory()->cidr('203.0.113.8/29')->kind(SubnetKind::External)->create(['site_id' => $this->workshop->id]);
        Subnet::factory()->cidr('10.255.0.0/31')->kind(SubnetKind::PointToPoint)->create(['site_id' => $this->workshop->id]);
        Subnet::factory()->cidr('10.20.255.1/32')->kind(SubnetKind::Loopback)->create(['site_id' => $this->workshop->id]);
        Subnet::factory()->cidr('172.16.255.0/30')->kind(SubnetKind::Tunnel)->create(['site_id' => $this->workshop->id]);
        $this->subnet('172.20.0.0/24');

        foreach ([route('address-blocks.create'), route('address-blocks.edit', $block)] as $url) {
            $this->actingAs($editor)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('blocks/Form')
                    // In the order of their addresses.
                    ->where('segments', [
                        ['site_id' => $this->office->id, 'network' => '10.10.10.0', 'prefix_length' => 24],
                        ['site_id' => $this->workshop->id, 'network' => '10.20.10.0', 'prefix_length' => 24],
                        ['site_id' => $this->workshop->id, 'network' => '10.20.30.0', 'prefix_length' => 24],
                    ])
                    ->where('siteBlocks', [
                        ['id' => $block->id, 'site_id' => $this->workshop->id, 'network' => '10.20.1.0', 'prefix_length' => 24],
                    ]));
        }
    }

    public function test_the_form_and_the_page_of_a_site_say_in_the_same_words_what_the_block_of_a_site_is(): void
    {
        $explanation = 'The block of a site is the envelope of all of its subnets, for example 192.168.0.0/16 for VLANs in 192.168.10.0/24 and 192.168.20.0/24. The network of a LAN is declared as the subnet of its VLAN, not as a block. A site without a hierarchical plan may have no block at all.';

        $this->assertSame(
            "Le bloc d'un site est l'enveloppe de tous ses sous-réseaux, par exemple 192.168.0.0/16 pour des VLAN en 192.168.10.0/24 et 192.168.20.0/24. Le réseau d'un LAN se déclare comme sous-réseau de son VLAN, pas comme bloc. Un site sans plan hiérarchique peut n'avoir aucun bloc.",
            __($explanation),
        );

        // Under the choice of the site in the form, and in the section of
        // the blocks of the page of a site.
        foreach (['js/pages/blocks/Form.vue', 'js/pages/sites/Show.vue'] as $component) {
            $this->assertStringContainsString($explanation, (string) file_get_contents(resource_path($component)), $component);
        }

        // What the form says of the block being typed.
        $this->assertSame(
            'Ce bloc contiendrait 3 des 5 sous-réseaux du site Atelier.',
            __('This block would hold :held of the :total subnets of the site :site.', ['held' => 3, 'total' => 5, 'site' => 'Atelier']),
        );

        $none = 'It would still be reported outside of the blocks of the site.|None would lie in it: they would still be reported outside of the blocks of the site.';

        $this->assertSame("Aucun ne s'y trouverait : ils resteraient signalés hors des blocs du site.", trans_choice($none, 5));
        // A site with a single subnet is spoken of in the singular.
        $this->assertSame('Il resterait signalé hors des blocs du site.', trans_choice($none, 1));
        $this->assertSame('Ce bloc ne contiendrait pas le seul sous-réseau du site Atelier.', __('This block would not hold the only subnet of the site :site.', ['site' => 'Atelier']));
        $this->assertSame('Ce bloc contiendrait le seul sous-réseau du site Atelier.', __('This block would hold the only subnet of the site :site.', ['site' => 'Atelier']));
    }

    public function test_a_block_that_holds_none_of_the_subnets_of_its_site_is_saved_and_they_say_which_block_they_are_outside_of(): void
    {
        $editor = $this->editor();

        $first = $this->subnet('10.20.10.0/24', ['site_id' => $this->workshop->id]);
        $second = $this->subnet('10.20.30.0/24', ['site_id' => $this->workshop->id]);

        // One network of the site declared as its block: never refused.
        $this->actingAs($editor)
            ->post(route('address-blocks.store'), $this->payload('10.20.1.0/24', $this->workshop))
            ->assertSessionHasNoErrors();

        $block = AddressBlock::query()->sole();

        // Each subnet then names the block it is compared with.
        foreach ([$first, $second] as $subnet) {
            $this->actingAs($editor)
                ->get(route('subnets.show', $subnet))
                ->assertInertia(fn (Assert $page) => $page->where('deviations', [
                    ['kind' => 'subnet_outside_site_blocks', 'reason' => 'Hors des blocs du site (10.20.1.0/24)'],
                ]));
        }

        // Widened to the space that holds them all, nothing is left to say.
        $this->actingAs($editor)
            ->put(route('address-blocks.update', $block), $this->payload('10.20.0.0/16', $this->workshop))
            ->assertSessionHasNoErrors();

        foreach ([$first, $second] as $subnet) {
            $this->actingAs($editor)
                ->get(route('subnets.show', $subnet))
                ->assertInertia(fn (Assert $page) => $page->where('deviations', []));
        }
    }

    public function test_an_editor_creates_a_block_of_the_organisation_and_a_block_of_a_site(): void
    {
        $editor = $this->editor();

        $response = $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.0.0.0/8', null, [
            'name' => 'Private addressing',
            'description' => 'Everything internal',
        ]));

        $organisation = AddressBlock::query()->sole();

        $response->assertRedirect(route('address-blocks.show', $organisation))->assertSessionHasNoErrors();

        $this->assertSame('10.0.0.0/8', $organisation->cidr());
        $this->assertNull($organisation->site_id);
        $this->assertSame(4, $organisation->ip_version);
        $this->assertSame(['Private addressing', 'Everything internal', Status::Active], [$organisation->name, $organisation->description, $organisation->status]);

        // Address and prefix length given apart, on a site.
        $this->actingAs($editor)
            ->post(route('address-blocks.store'), [
                'network' => '10.10.0.0', 'prefix_length' => 16, 'site_id' => $this->office->id, 'status' => 'planned',
            ])
            ->assertSessionHasNoErrors();

        $ofSite = AddressBlock::query()->whereNotNull('site_id')->sole();

        $this->assertSame('10.10.0.0/16', $ofSite->cidr());
        $this->assertTrue($ofSite->site?->is($this->office));
        $this->assertSame(Status::Planned, $ofSite->status);
    }

    public function test_host_bits_are_refused_with_the_block_that_was_probably_meant(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.10.20.5/16')),
            'network',
            "10.10.20.5/16 n'est pas une adresse de réseau. Vouliez-vous dire 10.10.0.0/16 ?",
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('address-blocks.store'), ['network' => '10.0.0.1', 'prefix_length' => 8, 'status' => 'active']),
            'network',
            "10.0.0.1/8 n'est pas une adresse de réseau. Vouliez-vous dire 10.0.0.0/8 ?",
        );

        $this->assertDatabaseCount('address_blocks', 0);
    }

    public function test_the_shape_of_a_block_is_checked(): void
    {
        $editor = $this->editor();
        $post = fn (array $payload) => $this->actingAs($editor)->post(route('address-blocks.store'), $payload);

        $this->assertRefused($post(['network' => '', 'status' => 'active']), 'network');
        $this->assertRefused($post($this->payload('10.0.0.0/0')), 'prefix_length');
        $this->assertRefused($post($this->payload('10.0.0.0/33')), 'prefix_length');
        $this->assertRefused($post(['network' => '10.0.0.0', 'status' => 'active']), 'prefix_length');
        $this->assertRefused($post($this->payload('10.256.0.0/16')), 'network', 'Saisissez une adresse IPv4 valide, par exemple 192.168.1.10.');
        $this->assertRefused(
            $post($this->payload('2001:db8::/32')),
            'network',
        );
        $this->assertRefused($post($this->payload('10.0.0.0/8', null, ['site_id' => 999999999])), 'site_id');
        $this->assertRefused($post($this->payload('10.0.0.0/8', null, ['status' => 'unknown'])), 'status');
        $this->assertRefused($post($this->payload('10.0.0.0/8', null, ['name' => str_repeat('a', 101)])), 'name');
        $this->assertRefused($post($this->payload('10.0.0.0/8', null, ['description' => str_repeat('a', 1001)])), 'description');

        $this->assertDatabaseCount('address_blocks', 0);

        // The smallest and the largest blocks there can be.
        $post($this->payload('128.0.0.0/1'))->assertSessionHasNoErrors();
        $post($this->payload('192.0.2.1/32', $this->office))->assertSessionHasNoErrors();
    }

    public function test_a_block_exists_once(): void
    {
        $editor = $this->editor();

        $this->block('10.0.0.0/8');
        $this->block('10.10.0.0/16', $this->office);

        foreach ([['10.0.0.0/8', null], ['10.0.0.0/8', $this->office], ['10.10.0.0/16', null], ['10.10.0.0/16', $this->workshop]] as [$cidr, $site]) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload($cidr, $site)),
                'network',
                'Ce bloc existe déjà.',
            );
        }

        $this->assertDatabaseCount('address_blocks', 2);
    }

    // --- What is refused ----------------------------------------------------------

    public function test_two_blocks_of_sites_never_overlap_whatever_their_sites(): void
    {
        $editor = $this->editor();
        $existing = $this->block('10.10.0.0/16', $this->office);

        // Inside it, around it, on the same site or on another one.
        $attempts = [
            ['10.10.5.0/24', $this->office],
            ['10.10.5.0/24', $this->workshop],
            ['10.0.0.0/8', $this->office],
            ['10.8.0.0/13', $this->workshop],
        ];

        foreach ($attempts as [$cidr, $site]) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload($cidr, $site)),
                'network',
                'Ce bloc chevauche le bloc 10.10.0.0/16 du site Head office.',
            );
        }

        $this->assertDatabaseCount('address_blocks', 1);

        // Beside it, a site can have as many blocks as it needs.
        $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.11.0.0/16', $this->office))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.20.0.0/16', $this->workshop))->assertSessionHasNoErrors();

        // And an existing block cannot grow over its neighbour.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('address-blocks.update', $existing), $this->payload('10.10.0.0/15', $this->office)),
            'network',
            'Ce bloc chevauche le bloc 10.11.0.0/16 du site Head office.',
        );

        $this->assertSame(16, $existing->refresh()->prefix_length);
    }

    public function test_every_block_in_the_way_is_named(): void
    {
        $this->block('10.10.0.0/16', $this->office);
        $this->block('10.20.0.0/16', $this->workshop);

        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('address-blocks.store'), $this->payload('10.0.0.0/8', $this->office)),
            'network',
            'Ce bloc chevauche le bloc 10.10.0.0/16 du site Head office, le bloc 10.20.0.0/16 du site Workshop.',
        );
    }

    public function test_a_block_of_a_site_never_holds_a_block_of_the_organisation(): void
    {
        $editor = $this->editor();
        $organisation = $this->block('10.10.0.0/16');

        $this->assertRefused(
            $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.0.0.0/8', $this->office)),
            'network',
            "Un bloc de site ne peut pas contenir un bloc de l'organisation : il contiendrait le bloc 10.10.0.0/16 de l'organisation.",
        );

        // The other way round, a block of the organisation is not put
        // inside the block of a site either.
        $ofSite = $this->block('10.20.0.0/16', $this->workshop);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.20.4.0/22')),
            'network',
            "Un bloc de l'organisation ne peut pas se trouver dans le bloc d'un site : il serait dans le bloc 10.20.0.0/16 du site Workshop.",
        );

        // Nor does a block change hands into such a place.
        $this->block('10.10.4.0/22');

        $this->assertRefused(
            $this->actingAs($editor)->put(route('address-blocks.update', $organisation), $this->payload('10.10.0.0/16', $this->office)),
            'network',
            "Un bloc de site ne peut pas contenir un bloc de l'organisation : il contiendrait le bloc 10.10.4.0/22 de l'organisation.",
        );

        $inside = $this->block('10.30.0.0/16', $this->office);
        $this->block('10.30.8.0/24', null, ['site_id' => null]);

        $this->assertNull($organisation->refresh()->site_id);
        $this->assertNotNull($ofSite->refresh()->site_id);
        $this->assertNotNull($inside->refresh()->site_id);
    }

    public function test_a_block_is_never_inside_a_subnet_and_a_subnet_never_holds_a_block(): void
    {
        $editor = $this->editor();

        $this->subnet('10.10.0.0/16');

        foreach ([null, $this->office] as $site) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.10.4.0/22', $site)),
                'network',
                'Un bloc ne peut pas se trouver dans un sous-réseau : il serait dans le sous-réseau 10.10.0.0/16.',
            );
        }

        $this->assertDatabaseCount('address_blocks', 0);

        // The other way round: a subnet is not created around a block.
        $this->block('10.20.4.0/22', $this->workshop);
        $this->block('10.20.8.0/24');

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), ['network' => '10.20.0.0/16', 'status' => 'active']),
            'network',
            "Un sous-réseau ne peut pas contenir un bloc : il contiendrait le bloc 10.20.4.0/22 du site Workshop, le bloc 10.20.8.0/24 de l'organisation.",
        );

        // Nor grown around one.
        $subnet = $this->subnet('10.20.4.0/24');

        $this->assertRefused(
            $this->actingAs($editor)->put(route('subnets.update', $subnet), ['network' => '10.20.0.0/20', 'status' => 'active']),
            'network',
            "Un sous-réseau ne peut pas contenir un bloc : il contiendrait le bloc 10.20.4.0/22 du site Workshop, le bloc 10.20.8.0/24 de l'organisation.",
        );

        $this->assertSame(24, $subnet->refresh()->prefix_length);
    }

    // --- What is allowed ----------------------------------------------------------

    public function test_blocks_of_the_organisation_nest_and_hold_blocks_of_sites(): void
    {
        $editor = $this->editor();
        $create = fn (string $cidr, ?Site $site = null) => $this->actingAs($editor)
            ->post(route('address-blocks.store'), $this->payload($cidr, $site))
            ->assertSessionHasNoErrors();

        // From the inside out as well as from the outside in.
        $create('10.0.0.0/16');
        $create('10.0.0.0/8');
        $create('10.0.4.0/22');
        $create('10.10.0.0/16', $this->office);
        $create('10.20.0.0/16', $this->workshop);
        // A block of the organisation created around blocks of sites.
        $create('10.16.0.0/12');

        $this->assertDatabaseCount('address_blocks', 6);
    }

    public function test_a_block_may_be_exactly_a_subnet(): void
    {
        $editor = $this->editor();

        // A small site that is a single network: its block is its subnet.
        $this->subnet('192.168.1.0/24');

        $this->actingAs($editor)
            ->post(route('address-blocks.store'), $this->payload('192.168.1.0/24', $this->office))
            ->assertSessionHasNoErrors();

        // And the subnet can come after the block.
        $this->block('192.168.2.0/24', $this->workshop);

        $this->actingAs($editor)
            ->post(route('subnets.store'), ['network' => '192.168.2.0/24', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('address_blocks', 2);
        $this->assertDatabaseCount('subnets', 2);
    }

    public function test_a_subnet_is_never_refused_for_where_it_lies_among_the_blocks(): void
    {
        $editor = $this->editor();

        $this->block('10.10.0.0/16', $this->office);

        // In the block of another site, outside every block, or with no
        // site at all: documented as it is.
        foreach (['10.10.5.0/24' => $this->workshop->id, '172.16.0.0/24' => $this->office->id, '10.10.6.0/24' => null] as $cidr => $site) {
            $this->actingAs($editor)
                ->post(route('subnets.store'), ['network' => $cidr, 'site_id' => $site, 'status' => 'active'])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('subnets', 3);
    }

    // --- Updating and deleting ------------------------------------------------------

    public function test_an_editor_updates_a_block(): void
    {
        $block = $this->block('10.10.0.0/16', $this->office, ['name' => 'Offices']);

        $this->actingAs($this->editor())
            ->put(route('address-blocks.update', $block), $this->payload('10.12.0.0/15', $this->workshop, [
                'name' => 'Production', 'description' => 'Moved', 'status' => 'retired',
            ]))
            ->assertRedirect(route('address-blocks.show', $block))
            ->assertSessionHasNoErrors();

        $block->refresh();

        $this->assertSame('10.12.0.0/15', $block->cidr());
        $this->assertSame($this->workshop->id, $block->site_id);
        $this->assertSame(['Production', 'Moved', Status::Retired], [$block->name, $block->description, $block->status]);
    }

    public function test_a_block_keeps_its_own_place_when_updated(): void
    {
        $editor = $this->editor();
        $block = $this->block('10.10.0.0/16', $this->office);

        $this->actingAs($editor)
            ->get(route('address-blocks.edit', $block))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('block.cidr', '10.10.0.0/16')
                ->where('block.network', '10.10.0.0')
                ->where('block.prefix_length', 16)
                ->where('block.site_id', $this->office->id));

        $this->actingAs($editor)
            ->put(route('address-blocks.update', $block), $this->payload('10.10.0.0/16', $this->office, ['name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        // It can go from its site to the organisation.
        $this->actingAs($editor)
            ->put(route('address-blocks.update', $block), $this->payload('10.10.0.0/16'))
            ->assertSessionHasNoErrors();

        $this->assertNull($block->refresh()->site_id);
    }

    public function test_a_block_is_always_deleted_and_its_subnets_are_kept(): void
    {
        $block = $this->block('10.10.0.0/16', $this->office);
        $subnet = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);

        $this->actingAs($this->editor())
            ->delete(route('address-blocks.destroy', $block))
            ->assertRedirect(route('address-blocks.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('address_blocks', 0);
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id]);
    }

    public function test_the_life_of_a_block_is_logged_with_addresses_as_text(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('address-blocks.store'), $this->payload('10.10.0.0/16', $this->office, ['name' => 'Offices']));

        $block = AddressBlock::query()->sole();
        $created = $this->lastEntry(ActivityEvent::AddressBlockCreated);

        $this->assertTrue($editor->is($created->causer));
        $this->assertTrue($block->is($created->subject));
        $this->assertSame(
            ['network' => '10.10.0.0', 'prefix_length' => 16, 'site' => 'Head office', 'name' => 'Offices', 'description' => null, 'status' => 'active'],
            $created->attribute_changes?->toArray()['attributes'],
        );
        $this->assertSame('10.10.0.0/16', $created->getProperty('label'));

        $this->actingAs($editor)->put(route('address-blocks.update', $block), $this->payload('10.12.0.0/15', null, ['name' => 'Offices']));

        $updated = $this->lastEntry(ActivityEvent::AddressBlockUpdated);

        $this->assertSame(
            [
                'old' => ['network' => '10.10.0.0', 'prefix_length' => 16, 'site' => 'Head office'],
                'attributes' => ['network' => '10.12.0.0', 'prefix_length' => 15, 'site' => null],
            ],
            $updated->attribute_changes?->toArray(),
        );
        $this->assertSame('10.12.0.0/15', $updated->getProperty('label'));

        $this->actingAs($editor)->delete(route('address-blocks.destroy', $block));

        $deleted = $this->lastEntry(ActivityEvent::AddressBlockDeleted);

        $this->assertSame('10.12.0.0', $deleted->attribute_changes?->toArray()['old']['network']);
        $this->assertNull($deleted->subject);
        $this->assertSame('10.12.0.0/15', $deleted->getProperty('label'));

        // The page of the log shows them in French.
        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => 'address_block.updated']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', "Bloc d'adressage modifié")
                ->where('entries.data.0.subject.title', '10.12.0.0/15')
                ->where('entries.data.0.changes', [
                    ['field' => 'Adresse du réseau', 'old' => '10.10.0.0', 'new' => '10.12.0.0'],
                    ['field' => 'Préfixe', 'old' => '16', 'new' => '15'],
                    ['field' => 'Site', 'old' => 'Head office', 'new' => '—'],
                ]));
    }

    // --- The page of a block --------------------------------------------------------

    public function test_the_page_of_a_block_shows_its_subnets_its_space_and_what_is_left(): void
    {
        $block = $this->block('10.10.0.0/16', $this->office, ['name' => 'Offices', 'description' => 'Everything at the head office']);

        // Inside the block.
        $first = $this->subnet('10.10.0.0/24', ['site_id' => $this->office->id, 'name' => 'Core']);
        $this->subnet('10.10.2.0/23', ['site_id' => $this->office->id]);
        $this->subnet('10.10.255.252/30');
        // Outside it.
        $this->subnet('10.11.0.0/24', ['site_id' => $this->office->id]);
        $this->subnet('10.9.255.0/24');

        $this->actingAs($this->reader())
            ->get(route('address-blocks.show', $block))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('blocks/Show')
                ->where('block.cidr', '10.10.0.0/16')
                ->where('block.mask', '255.255.0.0')
                ->where('block.last_address', '10.10.255.255')
                ->where('block.size', 65536)
                ->where('block.name', 'Offices')
                ->where('block.description', 'Everything at the head office')
                ->where('block.site', ['id' => $this->office->id, 'name' => 'Head office'])
                // The subnets of the block, in the order of their addresses.
                ->has('subnets', 3)
                ->where('subnets.0.id', $first->id)
                ->where('subnets.0.cidr', '10.10.0.0/24')
                ->where('subnets.0.name', 'Core')
                ->where('subnets.0.kind_label', 'Segment')
                ->where('subnets.0.site.name', 'Head office')
                ->where('subnets.0.deviations', [])
                ->where('subnets.1.cidr', '10.10.2.0/23')
                ->where('subnets.2.cidr', '10.10.255.252/30')
                // 256 + 512 + 4 addresses out of 65 536.
                ->where('space.size', 65536)
                ->where('space.occupied', 772)
                ->where('space.free', 65536 - 772)
                // What is left, as the largest prefixes that fit.
                ->where('space.free_prefixes', fn ($prefixes) => collect($prefixes)->pluck('cidr')->all() === [
                    '10.10.1.0/24',
                    '10.10.4.0/22',
                    '10.10.8.0/21',
                    '10.10.16.0/20',
                    '10.10.32.0/19',
                    '10.10.64.0/18',
                    '10.10.128.0/18',
                    '10.10.192.0/19',
                    '10.10.224.0/20',
                    '10.10.240.0/21',
                    '10.10.248.0/22',
                    '10.10.252.0/23',
                    '10.10.254.0/24',
                    '10.10.255.0/25',
                    '10.10.255.128/26',
                    '10.10.255.192/27',
                    '10.10.255.224/28',
                    '10.10.255.240/29',
                    '10.10.255.248/30',
                ])
                ->where('space.free_prefixes.0.size', 256)
                ->where('space.free_prefix_count', 19)
            );
    }

    public function test_an_empty_block_is_free_as_a_whole_and_a_full_one_has_nothing_left(): void
    {
        $empty = $this->block('10.10.0.0/16', $this->office);
        $full = $this->block('192.168.1.0/24', $this->workshop);
        $this->subnet('192.168.1.0/24', ['site_id' => $this->workshop->id]);

        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('address-blocks.show', $empty))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subnets', [])
                ->where('space', [
                    'size' => 65536,
                    'occupied' => 0,
                    'free' => 65536,
                    'free_prefix_count' => 1,
                    'free_prefixes' => [['cidr' => '10.10.0.0/16', 'size' => 65536]],
                ]));

        // A block that is exactly its subnet.
        $this->actingAs($reader)
            ->get(route('address-blocks.show', $full))
            ->assertInertia(fn (Assert $page) => $page
                ->has('subnets', 1)
                ->where('space', ['size' => 256, 'occupied' => 256, 'free' => 0, 'free_prefix_count' => 0, 'free_prefixes' => []]));
    }

    public function test_the_free_space_of_a_slash_8_is_worked_out_without_listing_its_addresses(): void
    {
        $block = $this->block('10.0.0.0/8');

        // Sixty /24, every other one: sixty gaps, then the rest of the /8.
        for ($index = 0; $index < 60; $index++) {
            $this->subnet('10.0.'.($index * 2).'.0/24');
        }

        $started = hrtime(true);

        $response = $this->actingAs($this->reader())->get(route('address-blocks.show', $block))->assertOk();

        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $response->assertInertia(fn (Assert $page) => $page
            ->has('subnets', 60)
            ->where('space.size', 16777216)
            ->where('space.occupied', 60 * 256)
            ->where('space.free', 16777216 - 60 * 256)
            // The first fifty prefixes are listed, all of them are counted:
            // 59 gaps of one /24, then 10.0.119.0/24, 10.0.120.0/21 and
            // one prefix of each size from 10.0.128.0/17 up to 10.128.0.0/9.
            ->has('space.free_prefixes', 50)
            ->where('space.free_prefixes.0', ['cidr' => '10.0.1.0/24', 'size' => 256])
            ->where('space.free_prefixes.49', ['cidr' => '10.0.99.0/24', 'size' => 256])
            ->where('space.free_prefix_count', 59 + 2 + 9)
        );

        // Sixteen million addresses, one by one, would take far longer.
        $this->assertLessThan(2000, $elapsedMilliseconds);
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_reads_the_blocks_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $block = $this->block('10.10.0.0/16', $this->office);

        $this->actingAs($reader)->get(route('address-blocks.index'))->assertOk();
        $this->actingAs($reader)->get(route('address-blocks.show', $block))->assertOk();

        $this->actingAs($reader)->get(route('address-blocks.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('address-blocks.store'), $this->payload('10.20.0.0/16'))->assertForbidden();
        $this->actingAs($reader)->get(route('address-blocks.edit', $block))->assertForbidden();
        $this->actingAs($reader)->put(route('address-blocks.update', $block), $this->payload('10.10.0.0/15'))->assertForbidden();
        $this->actingAs($reader)->delete(route('address-blocks.destroy', $block))->assertForbidden();

        $this->assertSame(['10.10.0.0/16'], AddressBlock::query()->get()->map->cidr()->all());
        $this->assertSame(0, $this->countEntries(ActivityEvent::AddressBlockUpdated));
    }

    public function test_an_administrator_writes_blocks_like_an_editor(): void
    {
        $this->actingAs($this->admin())
            ->post(route('address-blocks.store'), $this->payload('10.0.0.0/8'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('address_blocks', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $block = $this->block('10.10.0.0/16');

        $this->get(route('address-blocks.index'))->assertRedirect(route('login'));
        $this->get(route('address-blocks.show', $block))->assertRedirect(route('login'));
        $this->get(route('address-blocks.create'))->assertRedirect(route('login'));
        $this->post(route('address-blocks.store'), $this->payload('10.20.0.0/16'))->assertRedirect(route('login'));
        $this->put(route('address-blocks.update', $block), $this->payload('10.10.0.0/15'))->assertRedirect(route('login'));
        $this->delete(route('address-blocks.destroy', $block))->assertRedirect(route('login'));

        $this->assertDatabaseCount('address_blocks', 1);
    }

    public function test_an_unknown_block_gives_a_404(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('address-blocks.show', 999999999))->assertNotFound();
        $this->actingAs($editor)->get(route('address-blocks.edit', 999999999))->assertNotFound();
        $this->actingAs($editor)->put(route('address-blocks.update', 999999999), $this->payload('10.0.0.0/8'))->assertNotFound();
        $this->actingAs($editor)->delete(route('address-blocks.destroy', 999999999))->assertNotFound();
    }
}
