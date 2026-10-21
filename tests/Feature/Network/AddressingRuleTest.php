<?php

// torsade/tests/Feature/Network/AddressingRuleTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\GatewayPosition;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The addressing rule of the organisation: one rule at most, checked as a
 * whole, that proposes and points out what differs without ever changing
 * another object.
 */
class AddressingRuleTest extends NetworkTestCase
{
    private AddressBlock $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->block = AddressBlock::factory()->cidr('10.0.0.0/8')->create(['name' => 'Adressage interne']);
    }

    /**
     * The usual rule: 10.<site>.<VLAN>.0/24.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'aggregate_block_id' => $this->block->id,
            'site_prefix_length' => 16,
            'vlan_prefix_length' => 24,
            'gateway_position' => 'first',
            'ranges' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function saveRule(array $overrides = []): AddressingRule
    {
        $this->actingAs($this->editor())
            ->put(route('addressing-rule.update'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return AddressingRule::query()->with('ranges')->sole();
    }

    // --- The page ---------------------------------------------------------------

    public function test_without_a_block_of_the_organisation_the_page_has_nothing_to_start_from(): void
    {
        $this->block->delete();
        AddressBlock::factory()->cidr('10.20.0.0/16')->forSite()->create();

        $this->actingAs($this->reader())
            ->get(route('addressing-rule.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('addressing-rule/Show')
                ->where('rule', null)
                // A block of a site is not one a rule can start from.
                ->where('blocks', [])
                ->where('application', []));
    }

    public function test_without_a_rule_the_page_offers_the_blocks_of_the_organisation(): void
    {
        AddressBlock::factory()->cidr('172.16.0.0/12')->create();
        AddressBlock::factory()->cidr('10.20.0.0/16')->forSite()->create();

        $this->actingAs($this->reader())
            ->get(route('addressing-rule.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule', null)
                // In the order of their addresses.
                ->where('blocks', [
                    ['id' => $this->block->id, 'cidr' => '10.0.0.0/8', 'network' => '10.0.0.0', 'prefix_length' => 8, 'name' => 'Adressage interne'],
                    ['id' => AddressBlock::query()->where('prefix_length', 12)->sole()->id, 'cidr' => '172.16.0.0/12', 'network' => '172.16.0.0', 'prefix_length' => 12, 'name' => null],
                ])
                ->has('gatewayPositions', 3)
                ->where('gatewayPositions.0', ['value' => 'first', 'label' => 'Première adresse utilisable'])
                ->where('gatewayPositions.1.label', 'Dernière adresse utilisable')
                ->where('gatewayPositions.2.label', 'Aucune passerelle')
                ->has('rangeKinds', 3)
                ->where('maxRanges', 20)
                ->where('application', []));
    }

    // --- Creating and changing the rule ---------------------------------------------

    public function test_an_editor_creates_the_rule_with_its_ranges(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->put(route('addressing-rule.update'), $this->payload(['ranges' => [
                // Not in order: they are kept from the lowest.
                ['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null],
                ['kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure'],
                ['kind' => 'static', 'start_offset' => 21, 'end_offset' => 99, 'name' => '  Adresses fixes  '],
            ]]))
            ->assertRedirect(route('addressing-rule.show'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', "Règle d'adressage enregistrée.");

        $rule = AddressingRule::query()->with('ranges')->sole();

        $this->assertSame([$this->block->id, 16, 24, GatewayPosition::First], [$rule->aggregate_block_id, $rule->site_prefix_length, $rule->vlan_prefix_length, $rule->gateway_position]);
        $this->assertSame(
            [['reserved', 2, 20, 'Infrastructure'], ['static', 21, 99, 'Adresses fixes'], ['dhcp', 100, 200, null]],
            $rule->ranges->map(fn ($range): array => [$range->kind->value, $range->start_offset, $range->end_offset, $range->name])->all(),
        );

        // The page shows it, with what the browser needs to work out what
        // it proposes.
        $this->actingAs($this->reader())
            ->get(route('addressing-rule.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule.aggregate_block_id', $this->block->id)
                ->where('rule.block', '10.0.0.0/8')
                ->where('rule.network', '10.0.0.0')
                ->where('rule.prefix_length', 8)
                ->where('rule.site_prefix_length', 16)
                ->where('rule.vlan_prefix_length', 24)
                ->where('rule.gateway_position', 'first')
                ->where('rule.gateway_label', 'Première adresse utilisable')
                ->where('rule.ranges.0', ['kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure'])
                ->has('rule.ranges', 3));

        // It is logged with its block, its lengths and its ranges.
        $created = $this->lastEntry(ActivityEvent::AddressingRuleCreated);

        $this->assertTrue($editor->is($created->causer));
        $this->assertTrue($rule->is($created->subject));
        $this->assertSame('10.0.0.0/8, /16, /24', $created->getProperty('label'));
        $this->assertSame(
            [
                'aggregate_block' => '10.0.0.0/8',
                'site_prefix_length' => 16,
                'vlan_prefix_length' => 24,
                'gateway_position' => 'first',
                'ranges' => 'reserved 2-20 Infrastructure ; static 21-99 Adresses fixes ; dhcp 100-200',
            ],
            $created->attribute_changes?->toArray()['attributes'],
        );
    }

    public function test_saving_again_changes_the_one_rule_and_logs_what_changed(): void
    {
        $rule = $this->saveRule(['ranges' => [['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null]]]);
        $other = AddressBlock::factory()->cidr('172.16.0.0/12')->create();

        $this->actingAs($this->admin())
            ->put(route('addressing-rule.update'), $this->payload([
                'aggregate_block_id' => $other->id,
                'site_prefix_length' => 20,
                'vlan_prefix_length' => 26,
                'gateway_position' => 'last',
                'ranges' => [['kind' => 'static', 'start_offset' => 1, 'end_offset' => 9, 'name' => 'Serveurs']],
            ]))
            ->assertRedirect(route('addressing-rule.show'))
            ->assertSessionHasNoErrors();

        // Still one rule, the same one.
        $this->assertDatabaseCount('addressing_rules', 1);
        $this->assertDatabaseCount('addressing_rule_ranges', 1);
        $this->assertSame($rule->id, AddressingRule::query()->sole()->id);

        $updated = $this->lastEntry(ActivityEvent::AddressingRuleUpdated);

        $this->assertSame(
            [
                'old' => ['aggregate_block' => '10.0.0.0/8', 'site_prefix_length' => 16, 'vlan_prefix_length' => 24, 'gateway_position' => 'first', 'ranges' => 'dhcp 100-200'],
                'attributes' => ['aggregate_block' => '172.16.0.0/12', 'site_prefix_length' => 20, 'vlan_prefix_length' => 26, 'gateway_position' => 'last', 'ranges' => 'static 1-9 Serveurs'],
            ],
            $updated->attribute_changes?->toArray(),
        );
        $this->assertSame('172.16.0.0/12, /20, /26', $updated->getProperty('label'));

        // Saving the same values writes nothing.
        $this->actingAs($this->admin())
            ->put(route('addressing-rule.update'), $this->payload([
                'aggregate_block_id' => $other->id,
                'site_prefix_length' => 20,
                'vlan_prefix_length' => 26,
                'gateway_position' => 'last',
                'ranges' => [['kind' => 'static', 'start_offset' => 1, 'end_offset' => 9, 'name' => 'Serveurs']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::AddressingRuleCreated));
        $this->assertSame(1, $this->countEntries(ActivityEvent::AddressingRuleUpdated));

        // Removing the ranges is a change of the rule.
        $this->saveRule(['aggregate_block_id' => $other->id, 'site_prefix_length' => 20, 'vlan_prefix_length' => 26, 'gateway_position' => 'last']);

        $this->assertDatabaseCount('addressing_rule_ranges', 0);
        $this->assertSame(
            ['old' => ['ranges' => 'static 1-9 Serveurs'], 'attributes' => ['ranges' => null]],
            $this->lastEntry(ActivityEvent::AddressingRuleUpdated)->attribute_changes?->toArray(),
        );
    }

    public function test_the_log_page_reads_the_rule_in_words(): void
    {
        $this->saveRule(['gateway_position' => 'last', 'ranges' => [['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null]]]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => 'addressing_rule.created']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', "Règle d'adressage créée")
                ->where('entries.data.0.subject.title', '10.0.0.0/8, /16, /24')
                ->where('entries.data.0.changes', [
                    ['field' => "Bloc de l'organisation", 'old' => null, 'new' => '10.0.0.0/8'],
                    ['field' => 'Niveau de site', 'old' => null, 'new' => '/16'],
                    ['field' => 'Longueur des sous-réseaux de VLAN', 'old' => null, 'new' => '/24'],
                    ['field' => 'Position de la passerelle', 'old' => null, 'new' => 'Dernière adresse utilisable'],
                    ['field' => 'Découpage type', 'old' => null, 'new' => 'dhcp 100-200'],
                ]));
    }

    // --- The rule is checked as a whole --------------------------------------------

    public function test_the_shape_of_the_rule_is_checked(): void
    {
        $editor = $this->editor();

        $this->assertRefused($this->actingAs($editor)->put(route('addressing-rule.update'), []), 'aggregate_block_id');
        $this->assertSame(['aggregate_block_id', 'site_prefix_length', 'vlan_prefix_length', 'gateway_position'], $this->errorFields());

        foreach ([
            ['aggregate_block_id' => 999999],
            ['site_prefix_length' => 0],
            ['site_prefix_length' => 33],
            ['site_prefix_length' => 'seize'],
            ['vlan_prefix_length' => 16.5],
            ['gateway_position' => 'middle'],
            ['ranges' => 'none'],
        ] as $wrong) {
            $field = array_key_first($wrong);

            $this->assertRefused($this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload($wrong)), $field);
        }

        $response = $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['ranges' => [
            ['kind' => 'wifi', 'start_offset' => -1, 'end_offset' => 'vingt', 'name' => str_repeat('a', 65)],
        ]]));

        $response->assertRedirect();
        $this->assertSame(['ranges.0.kind', 'ranges.0.start_offset', 'ranges.0.end_offset', 'ranges.0.name'], $this->errorFields());

        $this->assertDatabaseCount('addressing_rules', 0);
    }

    public function test_the_rule_starts_from_a_block_of_the_organisation_only(): void
    {
        $site = Site::factory()->create(['name' => 'Atelier']);
        $ofSite = AddressBlock::factory()->cidr('10.20.0.0/16')->forSite($site)->create();

        $this->assertRefused(
            $this->actingAs($this->editor())->put(route('addressing-rule.update'), $this->payload(['aggregate_block_id' => $ofSite->id, 'site_prefix_length' => 20])),
            'aggregate_block_id',
            "La règle part d'un bloc de l'organisation : 10.20.0.0/16 appartient au site Atelier.",
        );

        $this->assertDatabaseCount('addressing_rules', 0);
    }

    public function test_the_lengths_only_make_sense_together_and_each_one_is_refused_on_its_line(): void
    {
        $editor = $this->editor();

        // Blocks of sites no smaller than the block of the organisation.
        foreach ([8, 4] as $length) {
            $this->assertRefused(
                $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => $length])),
                'site_prefix_length',
                "Les blocs de site sont découpés dans le bloc de l'organisation : leur longueur doit être supérieure à 8.",
            );
            $this->assertSame(['site_prefix_length'], $this->errorFields());
        }

        // Subnets of VLANs no smaller than the blocks of sites.
        foreach ([16, 12] as $length) {
            $this->assertRefused(
                $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['vlan_prefix_length' => $length])),
                'vlan_prefix_length',
                'Les sous-réseaux de VLAN sont découpés dans les blocs de site : leur longueur doit être supérieure à 16.',
            );
        }

        // A subnet of a VLAN keeps usable addresses.
        foreach ([31, 32] as $length) {
            $this->assertRefused(
                $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['vlan_prefix_length' => $length])),
                'vlan_prefix_length',
                "Un sous-réseau de VLAN est au plus un /30 : au-delà, il n'a plus de place pour une passerelle et un poste.",
            );
        }

        // Both wrong: both told, each on its line.
        $response = $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => 8, 'vlan_prefix_length' => 8]));

        $response->assertRedirect();
        $this->assertSame(['site_prefix_length', 'vlan_prefix_length'], $this->errorFields());

        $this->assertDatabaseCount('addressing_rules', 0);

        // The bounds that are accepted: one bit for the sites, a /30.
        $this->saveRule(['site_prefix_length' => 9, 'vlan_prefix_length' => 30]);
        $this->saveRule(['site_prefix_length' => 29, 'vlan_prefix_length' => 30]);
    }

    public function test_each_range_is_checked_on_its_own_line(): void
    {
        $editor = $this->editor();

        $response = $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['ranges' => [
            // The network address.
            ['kind' => 'reserved', 'start_offset' => 0, 'end_offset' => 5, 'name' => null],
            // Upside down.
            ['kind' => 'static', 'start_offset' => 30, 'end_offset' => 20, 'name' => null],
            // Fine.
            ['kind' => 'static', 'start_offset' => 40, 'end_offset' => 99, 'name' => null],
            // Over the one above.
            ['kind' => 'dhcp', 'start_offset' => 99, 'end_offset' => 150, 'name' => null],
            // Over the broadcast address of a /24.
            ['kind' => 'reserved', 'start_offset' => 200, 'end_offset' => 255, 'name' => null],
        ]]));

        $response->assertRedirect();

        $this->assertSame(
            ['ranges.0.start_offset', 'ranges.1.end_offset', 'ranges.3.start_offset', 'ranges.4.end_offset'],
            $this->errorFields(),
        );
        $this->assertSame("Le début vaut au moins 1 : le décalage 0 est l'adresse du réseau.", $this->error('ranges.0.start_offset'));
        $this->assertSame('La fin de la plage ne peut pas être inférieure à son début.', $this->error('ranges.1.end_offset'));
        $this->assertSame('Cette plage chevauche la plage 40 – 99.', $this->error('ranges.3.start_offset'));
        $this->assertSame("La fin vaut au plus 254 dans un sous-réseau en /24 : sa dernière adresse est l'adresse de diffusion.", $this->error('ranges.4.end_offset'));

        $this->assertDatabaseCount('addressing_rules', 0);
        $this->assertDatabaseCount('addressing_rule_ranges', 0);

        // The whole usable space of a /24, in two ranges that touch.
        $this->saveRule(['gateway_position' => 'none', 'ranges' => [
            ['kind' => 'static', 'start_offset' => 1, 'end_offset' => 99, 'name' => null],
            ['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 254, 'name' => null],
        ]]);

        $this->assertDatabaseCount('addressing_rule_ranges', 2);
    }

    public function test_a_dhcp_range_of_the_rule_leaves_the_gateway_out(): void
    {
        $editor = $this->editor();
        $over = fn (string $position, int $start, int $end, string $kind = 'dhcp') => $this->actingAs($editor)->put(
            route('addressing-rule.update'),
            $this->payload(['gateway_position' => $position, 'ranges' => [['kind' => $kind, 'start_offset' => $start, 'end_offset' => $end, 'name' => null]]]),
        );

        $this->assertRefused($over('first', 1, 50), 'ranges.0.kind', 'Une plage DHCP ne peut pas contenir la passerelle de la règle, au décalage 1.');
        $this->assertRefused($over('last', 200, 254), 'ranges.0.kind', 'Une plage DHCP ne peut pas contenir la passerelle de la règle, au décalage 254.');

        $this->assertDatabaseCount('addressing_rules', 0);

        // Next to the gateway, with no gateway, or a range of another kind.
        $over('first', 2, 50)->assertSessionHasNoErrors();
        $over('last', 200, 253)->assertSessionHasNoErrors();
        $over('none', 1, 254)->assertSessionHasNoErrors();
        $over('first', 1, 50, 'static')->assertSessionHasNoErrors();
        $over('last', 200, 254, 'reserved')->assertSessionHasNoErrors();
    }

    public function test_shortening_the_subnets_under_a_range_is_refused_with_the_range_it_would_leave_out(): void
    {
        $ranges = [
            ['kind' => 'static', 'start_offset' => 2, 'end_offset' => 20, 'name' => null],
            ['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null],
        ];

        $this->saveRule(['ranges' => $ranges]);

        $response = $this->actingAs($this->editor())->put(route('addressing-rule.update'), $this->payload(['vlan_prefix_length' => 26, 'ranges' => $ranges]));

        $response->assertRedirect();

        // Told on the length that changed, and on the line of the range.
        $this->assertSame(['ranges.1.end_offset', 'vlan_prefix_length'], $this->errorFields());
        $this->assertSame('Dans un sous-réseau en /26, la plage 100 – 200 ne tient plus : un décalage vaut au plus 62.', $this->error('vlan_prefix_length'));

        $this->assertSame(24, AddressingRule::query()->sole()->vlan_prefix_length);

        // With the range adapted, the same change passes.
        $ranges[1] = ['kind' => 'dhcp', 'start_offset' => 30, 'end_offset' => 60, 'name' => null];

        $this->saveRule(['vlan_prefix_length' => 26, 'ranges' => $ranges]);

        $this->assertSame(26, AddressingRule::query()->sole()->vlan_prefix_length);
    }

    public function test_more_ranges_than_a_rule_can_hold_are_refused(): void
    {
        $ranges = array_map(
            fn (int $line): array => ['kind' => 'static', 'start_offset' => $line * 2, 'end_offset' => $line * 2 + 1, 'name' => null],
            range(1, 21),
        );

        $this->assertRefused(
            $this->actingAs($this->editor())->put(route('addressing-rule.update'), $this->payload(['ranges' => $ranges])),
            'ranges',
        );

        $this->saveRule(['ranges' => array_slice($ranges, 0, 20)]);

        $this->assertDatabaseCount('addressing_rule_ranges', 20);
    }

    // --- The block the rule starts from ----------------------------------------------

    public function test_the_block_of_the_rule_is_not_deleted_under_it(): void
    {
        $editor = $this->editor();
        $other = AddressBlock::factory()->cidr('172.16.0.0/12')->create();

        $this->saveRule();

        $this->assertRefused(
            $this->actingAs($editor)->delete(route('address-blocks.destroy', $this->block)),
            'address_block',
            "La règle d'adressage part de ce bloc : modifiez la règle ou supprimez-la d'abord.",
        );

        $this->assertDatabaseHas('address_blocks', ['id' => $this->block->id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::AddressBlockDeleted));

        // Any other block is deleted as before.
        $this->actingAs($editor)->delete(route('address-blocks.destroy', $other))->assertSessionHasNoErrors();

        // Once the rule starts from another block, this one can go.
        $replacement = AddressBlock::factory()->cidr('192.168.0.0/16')->create();

        $this->saveRule(['aggregate_block_id' => $replacement->id, 'site_prefix_length' => 20]);

        $this->actingAs($editor)->delete(route('address-blocks.destroy', $this->block))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('address_blocks', ['id' => $this->block->id]);
    }

    public function test_the_block_of_the_rule_is_not_shrunk_or_given_to_a_site_under_it(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create();

        $this->saveRule();

        $update = fn (string $cidr, ?int $siteId = null) => $this->actingAs($editor)->put(
            route('address-blocks.update', $this->block),
            ['network' => $cidr, 'site_id' => $siteId, 'status' => 'active'],
        );

        // As long as the blocks of sites, or longer: no site could be cut out.
        foreach (['10.0.0.0/16', '10.0.0.0/20'] as $cidr) {
            $this->assertRefused(
                $update($cidr),
                'prefix_length',
                "La règle d'adressage découpe ce bloc en blocs de site en /16 : son préfixe doit rester inférieur à 16.",
            );
        }

        $this->assertRefused(
            $update('10.0.0.0/8', $site->id),
            'site_id',
            "La règle d'adressage part de ce bloc : il reste un bloc de l'organisation.",
        );

        $this->assertSame('10.0.0.0/8', $this->block->refresh()->cidr());
        $this->assertNull($this->block->site_id);

        // Shrunk less than that, or moved: the rule still makes sense, and
        // only proposes other addresses.
        $update('10.0.0.0/15')->assertSessionHasNoErrors();
        $update('172.16.0.0/12')->assertSessionHasNoErrors();

        $this->assertSame('172.16.0.0/12', $this->block->refresh()->cidr());

        $this->actingAs($this->reader())
            ->get(route('addressing-rule.show'))
            ->assertInertia(fn (Assert $page) => $page->where('rule.block', '172.16.0.0/12')->where('rule.network', '172.16.0.0'));

        // A block the rule does not start from is free of all that.
        $free = AddressBlock::factory()->cidr('192.168.0.0/16')->create();

        $this->actingAs($editor)
            ->put(route('address-blocks.update', $free), ['network' => '192.168.0.0/24', 'site_id' => $site->id, 'status' => 'active'])
            ->assertSessionHasNoErrors();
    }

    // --- Deleting the rule ---------------------------------------------------------

    public function test_deleting_the_rule_deletes_its_ranges_and_nothing_else(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        $ofSite = AddressBlock::factory()->cidr('10.20.0.0/16')->forSite($site)->create();
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10]);
        $subnet = Subnet::factory()->cidr('10.20.10.0/24')->create(['vlan_id' => $vlan->id]);

        $rule = $this->saveRule(['ranges' => [['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null]]]);

        $this->actingAs($editor)
            ->delete(route('addressing-rule.destroy'))
            ->assertRedirect(route('addressing-rule.show'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', "Règle d'adressage supprimée.");

        $this->assertDatabaseCount('addressing_rules', 0);
        $this->assertDatabaseCount('addressing_rule_ranges', 0);

        // Everything else is as it was.
        $this->assertDatabaseHas('address_blocks', ['id' => $this->block->id]);
        $this->assertDatabaseHas('address_blocks', ['id' => $ofSite->id]);
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id]);
        $this->assertSame(20, $site->refresh()->number);

        $deleted = $this->lastEntry(ActivityEvent::AddressingRuleDeleted);

        $this->assertTrue($editor->is($deleted->causer));
        $this->assertSame($rule->id, $deleted->subject_id);
        $this->assertSame('10.0.0.0/8, /16, /24', $deleted->getProperty('label'));
        $this->assertSame('dhcp 100-200', $deleted->attribute_changes?->toArray()['old']['ranges']);

        // Deleting what is not there is no error, and logs nothing.
        $this->actingAs($editor)->delete(route('addressing-rule.destroy'))->assertRedirect(route('addressing-rule.show'));
        $this->assertSame(1, $this->countEntries(ActivityEvent::AddressingRuleDeleted));

        // The block is free again.
        $this->actingAs($editor)->delete(route('address-blocks.destroy', $this->block))->assertSessionHasNoErrors();
    }

    // --- How the rule applies to the sites -------------------------------------------

    public function test_the_table_tells_the_state_of_each_site(): void
    {
        $this->saveRule();

        $declared = Site::factory()->create(['name' => 'Siège', 'number' => 10]);
        $block = AddressBlock::factory()->cidr('10.10.0.0/16')->forSite($declared)->create();
        // Another block than the expected one is not the expected one.
        $missing = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        AddressBlock::factory()->cidr('10.21.0.0/16')->forSite($missing)->create();
        $outside = Site::factory()->create(['name' => 'Dépôt', 'number' => 300]);
        $unnumbered = Site::factory()->create(['name' => 'Annexe']);
        $first = Site::factory()->create(['name' => 'Agence', 'number' => 0]);

        $this->actingAs($this->reader())
            ->get(route('addressing-rule.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // The numbered sites by number, then the others.
                ->where('application', [
                    [
                        'id' => $first->id, 'name' => 'Agence', 'number' => 0, 'expected_block' => '10.0.0.0/16',
                        'state' => 'missing', 'block_id' => null,
                        'deviation' => ['kind' => 'site_block_missing', 'reason' => 'Bloc attendu par la règle : 10.0.0.0/16'],
                    ],
                    [
                        'id' => $declared->id, 'name' => 'Siège', 'number' => 10, 'expected_block' => '10.10.0.0/16',
                        'state' => 'declared', 'block_id' => $block->id, 'deviation' => null,
                    ],
                    [
                        'id' => $missing->id, 'name' => 'Atelier', 'number' => 20, 'expected_block' => '10.20.0.0/16',
                        'state' => 'missing', 'block_id' => null,
                        'deviation' => ['kind' => 'site_block_missing', 'reason' => 'Bloc attendu par la règle : 10.20.0.0/16'],
                    ],
                    [
                        'id' => $outside->id, 'name' => 'Dépôt', 'number' => 300, 'expected_block' => null,
                        'state' => 'outside', 'block_id' => null,
                        'deviation' => ['kind' => 'site_number_outside_rule', 'reason' => 'Numéro 300 : la règle en permet de 0 à 255'],
                    ],
                    [
                        'id' => $unnumbered->id, 'name' => 'Annexe', 'number' => null, 'expected_block' => null,
                        'state' => 'unnumbered', 'block_id' => null, 'deviation' => null,
                    ],
                ]));
    }

    public function test_the_expected_block_of_a_site_is_created_in_one_action(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);

        $this->saveRule();

        // From the page of the rule.
        $this->actingAs($editor)
            ->post(route('sites.expected-block.store', $site), ['from_rule' => true])
            ->assertRedirect(route('addressing-rule.show'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Bloc 10.20.0.0/16 créé.');

        $block = AddressBlock::query()->where('site_id', $site->id)->sole();

        $this->assertSame('10.20.0.0/16', $block->cidr());
        $this->assertSame('active', $block->status->value);

        $created = $this->lastEntry(ActivityEvent::AddressBlockCreated);

        $this->assertTrue($block->is($created->subject));
        $this->assertTrue($editor->is($created->causer));
        $this->assertSame('Atelier', $created->attribute_changes?->toArray()['attributes']['site']);

        // The table then says so.
        $this->actingAs($editor)
            ->get(route('addressing-rule.show'))
            ->assertInertia(fn (Assert $page) => $page->where('application.0.state', 'declared')->where('application.0.block_id', $block->id));

        // From the page of the site, the answer goes back there.
        $other = Site::factory()->create(['name' => 'Siège', 'number' => 10]);

        $this->actingAs($editor)
            ->post(route('sites.expected-block.store', $other))
            ->assertRedirect(route('sites.show', $other));

        $this->assertSame('10.10.0.0/16', AddressBlock::query()->where('site_id', $other->id)->sole()->cidr());
    }

    public function test_an_expected_block_that_cannot_be_created_says_what_is_in_the_way(): void
    {
        $editor = $this->editor();
        $site = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        $neighbour = Site::factory()->create(['name' => 'Siège']);

        $this->saveRule();

        // The block of another site is over it.
        AddressBlock::factory()->cidr('10.20.0.0/15')->forSite($neighbour)->create();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.expected-block.store', $site), ['from_rule' => true]),
            'block',
            'Le bloc 10.20.0.0/16 ne peut pas être créé. Ce bloc chevauche le bloc 10.20.0.0/15 du site Siège.',
        );

        // No number, a number the rule does not reach, no rule.
        $unnumbered = Site::factory()->create();
        $outside = Site::factory()->create(['number' => 300]);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.expected-block.store', $unnumbered)),
            'block',
            "Ce site n'a pas de numéro : la règle n'attend aucun bloc pour lui.",
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.expected-block.store', $outside)),
            'block',
            "La règle permet les numéros 0 à 255 : elle n'attend aucun bloc pour le site 300.",
        );

        $this->actingAs($editor)->delete(route('addressing-rule.destroy'));

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.expected-block.store', $site)),
            'block',
            "Il n'y a pas de règle d'adressage : aucun bloc n'est attendu pour ce site.",
        );

        $this->assertSame(1, AddressBlock::query()->whereNotNull('site_id')->count());
        $this->assertSame(0, $this->countEntries(ActivityEvent::AddressBlockCreated));
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_reads_the_rule_but_cannot_write_it(): void
    {
        $reader = $this->reader();
        $site = Site::factory()->create(['number' => 20]);

        $this->saveRule();

        $this->actingAs($reader)->get(route('addressing-rule.show'))->assertOk();
        $this->actingAs($reader)->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => 20]))->assertForbidden();
        $this->actingAs($reader)->delete(route('addressing-rule.destroy'))->assertForbidden();
        $this->actingAs($reader)->post(route('sites.expected-block.store', $site), ['from_rule' => true])->assertForbidden();

        $this->assertSame(16, AddressingRule::query()->sole()->site_prefix_length);
        $this->assertDatabaseCount('address_blocks', 1);
        $this->assertSame(0, $this->countEntries(ActivityEvent::AddressingRuleUpdated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::AddressingRuleDeleted));
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $site = Site::factory()->create();

        $this->get(route('addressing-rule.show'))->assertRedirect(route('login'));
        $this->put(route('addressing-rule.update'), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('addressing-rule.destroy'))->assertRedirect(route('login'));
        $this->post(route('sites.expected-block.store', $site))->assertRedirect(route('login'));

        $this->assertDatabaseCount('addressing_rules', 0);
    }
}
