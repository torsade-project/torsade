<?php

// torsade/tests/Feature/Network/DeviceTemplateTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\DeviceTemplatePortGroup;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device templates: what a kind of machine is made of, its ports by groups
 * named after a pattern, and the generic templates added on demand.
 */
class DeviceTemplateTest extends NetworkTestCase
{
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
    }

    /**
     * One group of ports, as the form sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function group(array $overrides = []): array
    {
        return [
            'quantity' => 24,
            'name_pattern' => '{m}/0/{n}',
            'first_number' => 1,
            'connector' => 'rj45',
            'max_speed' => '1g',
            'poe_type' => '802.3at',
            'poe_label' => null,
            ...$overrides,
        ];
    }

    /**
     * What the form of a template sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Switch 24 ports',
            'device_type' => 'switch',
            'manufacturer' => '',
            'model' => '',
            'poe_budget_watts' => null,
            'description' => '',
            'port_groups' => [$this->group()],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function store(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('device-templates.store'), $this->payload($overrides));
    }

    /**
     * The groups of a template as they are stored, in their order.
     *
     * @return list<array<string, mixed>>
     */
    private function groupsOf(DeviceTemplate $template): array
    {
        return array_values($template->portGroups()->get()->map(fn (DeviceTemplatePortGroup $group): array => [
            $group->position, $group->quantity, $group->name_pattern, $group->first_number,
            $group->connector->value, $group->max_speed->value, $group->poe_type->value, $group->poe_label,
        ])->all());
    }

    // --- List -----------------------------------------------------------------

    public function test_the_list_shows_each_template_with_its_ports_and_the_members_that_use_it(): void
    {
        $switch = DeviceTemplate::factory()->switch(24, 4)->create([
            'name' => 'Switch 24 ports', 'manufacturer' => 'Trois Brins Matériel', 'model' => 'SW-24', 'poe_budget_watts' => 370,
        ]);
        DeviceTemplate::factory()->create(['name' => 'Pare-feu', 'device_type' => DeviceType::Firewall]);

        DeviceMember::factory()->count(2)->sequence(['position' => 1], ['position' => 2])->create(['device_template_id' => $switch->id]);

        $this->actingAs($this->reader())
            ->get(route('device-templates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('device-templates/Index')
                ->has('templates', 2)
                // By name.
                ->where('templates.0.name', 'Pare-feu')
                ->where('templates.0.device_type_label', 'Pare-feu')
                ->where('templates.0.ports_count', 0)
                ->where('templates.0.members_count', 0)
                ->where('templates.0.port_groups', [])
                ->where('templates.1', [
                    'id' => $switch->id,
                    'name' => 'Switch 24 ports',
                    'device_type' => 'switch',
                    'device_type_label' => 'Switch',
                    'manufacturer' => 'Trois Brins Matériel',
                    'model' => 'SW-24',
                    'poe_budget_watts' => 370,
                    'description' => null,
                    'ports_count' => 28,
                    'members_count' => 2,
                    // In their order: what the list shows the names from.
                    'port_groups' => [
                        ['quantity' => 24, 'name_pattern' => '{m}/0/{n}', 'first_number' => 1, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => '802.3at', 'poe_label' => null],
                        ['quantity' => 4, 'name_pattern' => '{m}/1/{n}', 'first_number' => 1, 'connector' => 'sfp_plus', 'max_speed' => '10g', 'poe_type' => 'none', 'poe_label' => null],
                    ],
                ])
                ->where('missingGenerics', 5));
    }

    // --- Creation and update --------------------------------------------------

    public function test_an_editor_creates_a_template_with_its_groups_of_ports(): void
    {
        $this->store([
            'manufacturer' => 'Trois Brins Matériel',
            'model' => 'SW-24',
            'poe_budget_watts' => 370,
            'description' => 'Switch de baie',
            'port_groups' => [
                $this->group(['poe_label' => 'PoE+']),
                $this->group(['quantity' => 4, 'name_pattern' => '{m}/1/{n}', 'connector' => 'sfp_plus', 'max_speed' => '10g', 'poe_type' => 'none']),
            ],
        ])
            ->assertRedirect(route('device-templates.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Modèle créé.');

        $template = DeviceTemplate::query()->sole();

        $this->assertSame(
            ['Switch 24 ports', DeviceType::Switch, 'Trois Brins Matériel', 'SW-24', 370, 'Switch de baie'],
            [$template->name, $template->device_type, $template->manufacturer, $template->model, $template->poe_budget_watts, $template->description],
        );
        $this->assertSame([
            [1, 24, '{m}/0/{n}', 1, 'rj45', '1g', '802.3at', 'PoE+'],
            [2, 4, '{m}/1/{n}', 1, 'sfp_plus', '10g', 'none', null],
        ], $this->groupsOf($template));
    }

    public function test_only_the_name_and_the_type_are_required_and_a_template_may_have_no_port(): void
    {
        $this->actingAs($this->editor)
            ->post(route('device-templates.store'), ['name' => 'Onduleur', 'device_type' => 'other', 'port_groups' => []])
            ->assertSessionHasNoErrors();

        $template = DeviceTemplate::query()->sole();

        $this->assertSame([null, null, null, null], [$template->manufacturer, $template->model, $template->poe_budget_watts, $template->description]);
        $this->assertSame([], $this->groupsOf($template));

        $this->actingAs($this->editor)
            ->post(route('device-templates.store'), [])
            ->assertSessionHasErrors(['name', 'device_type', 'port_groups']);

        $this->assertDatabaseCount('device_templates', 1);
    }

    public function test_the_shape_of_a_template_and_of_its_groups_is_checked(): void
    {
        $this->assertRefused($this->store(['name' => str_repeat('a', 101)]), 'name');
        $this->assertRefused($this->store(['device_type' => 'toaster']), 'device_type');
        $this->assertRefused($this->store(['manufacturer' => str_repeat('a', 101)]), 'manufacturer');
        $this->assertRefused($this->store(['model' => str_repeat('a', 101)]), 'model');
        $this->assertRefused($this->store(['description' => str_repeat('a', 1001)]), 'description');

        foreach ([0, -5, 100001, 1.5, 'beaucoup'] as $budget) {
            $this->assertRefused($this->store(['poe_budget_watts' => $budget]), 'poe_budget_watts');
        }

        // Each field of a group, on its line.
        $second = fn (array $group): TestResponse => $this->store(['port_groups' => [$this->group(), $this->group(['name_pattern' => '{m}/1/{n}', ...$group])]]);

        foreach ([0, 129, 1.5, '', 'douze'] as $quantity) {
            $this->assertRefused($second(['quantity' => $quantity]), 'port_groups.1.quantity');
        }

        foreach ([-1, 1000, 1.5, ''] as $first) {
            $this->assertRefused($second(['first_number' => $first]), 'port_groups.1.first_number');
        }

        $this->assertRefused($second(['connector' => 'usb']), 'port_groups.1.connector');
        $this->assertRefused($second(['max_speed' => '1000g']), 'port_groups.1.max_speed');
        $this->assertRefused($second(['poe_type' => 'poe']), 'port_groups.1.poe_type');
        $this->assertRefused($second(['poe_label' => str_repeat('a', 33)]), 'port_groups.1.poe_label');
        $this->assertRefused($second(['name_pattern' => '']), 'port_groups.1.name_pattern');
        $this->assertRefused($second(['name_pattern' => str_repeat('a', 30).'{n}']), 'port_groups.1.name_pattern');

        // Twenty groups at most.
        $many = array_map(fn (int $index): array => $this->group(['quantity' => 1, 'name_pattern' => "g{$index}-{n}"]), range(1, 21));

        $this->assertRefused($this->store(['port_groups' => $many]), 'port_groups');

        $this->assertDatabaseCount('device_templates', 0);

        // At the limits, everything is accepted.
        $this->store([
            'name' => str_repeat('a', 100),
            'poe_budget_watts' => 100000,
            'port_groups' => [
                $this->group(['quantity' => 128, 'first_number' => 0]),
                $this->group(['quantity' => 1, 'first_number' => 999, 'name_pattern' => 'x{n}', 'poe_label' => str_repeat('b', 32)]),
            ],
        ])->assertSessionHasNoErrors();
    }

    public function test_a_pattern_holds_the_number_of_the_port_once_and_the_number_of_the_member_once_at_most(): void
    {
        $with = fn (string $pattern): TestResponse => $this->store(['port_groups' => [$this->group(['name_pattern' => $pattern])]]);

        // The number of the port: once.
        foreach (['port', '{m}/0/', '{n}/{n}', '{m}{n}{n}'] as $pattern) {
            $this->assertRefused(
                $with($pattern),
                'port_groups.0.name_pattern',
                'Un motif contient {n}, le numéro du port, une fois : « {m}/0/{n} » ou « port{n} ».',
            );
        }

        // The number of the member: once at most.
        $this->assertRefused(
            $with('{m}/{m}/{n}'),
            'port_groups.0.name_pattern',
            'Un motif contient {m}, le numéro du membre, une fois au plus.',
        );

        // Around them: letters, digits and a few characters.
        foreach (['port {n}', '{n}é', '{n}_a', '{x}{n}', 'p{n}*'] as $pattern) {
            $this->assertRefused(
                $with($pattern),
                'port_groups.0.name_pattern',
                'En dehors de {m} et de {n}, un motif ne contient que des lettres, des chiffres et les caractères / - . et le deux-points.',
            );
        }

        $this->assertDatabaseCount('device_templates', 0);

        // The usual notations are all accepted.
        foreach (['{m}/0/{n}', '{n}', 'port{n}', 'Gi{m}/0/{n}', 'ge-{m}/0/{n}', '{m}:{n}', 'Te{m}/1/{n}.1'] as $index => $pattern) {
            $this->store(['name' => "Modèle {$index}", 'port_groups' => [$this->group(['name_pattern' => $pattern])]])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('device_templates', 7);
    }

    public function test_two_groups_that_would_give_the_same_name_are_refused_on_the_second_one(): void
    {
        // The second group starts where the first one ends.
        $this->assertRefused(
            $this->store(['port_groups' => [$this->group(), $this->group(['quantity' => 4, 'first_number' => 24])]]),
            'port_groups.1.name_pattern',
            'Ce groupe donne le nom 1/0/24, que le groupe 1 donne déjà.',
        );

        // Each group that repeats a name is told, with the group it
        // repeats.
        $response = $this->store(['port_groups' => [
            $this->group(['quantity' => 2, 'name_pattern' => 'a{n}']),
            $this->group(['quantity' => 2, 'name_pattern' => 'b{n}']),
            $this->group(['quantity' => 2, 'name_pattern' => 'a{n}', 'first_number' => 2]),
            $this->group(['quantity' => 2, 'name_pattern' => 'b{n}', 'first_number' => 0]),
        ]]);

        $response->assertSessionHasErrors(['port_groups.2.name_pattern', 'port_groups.3.name_pattern']);

        $this->assertSame(['port_groups.2.name_pattern', 'port_groups.3.name_pattern'], $this->errorFields());
        $this->assertSame('Ce groupe donne le nom a2, que le groupe 1 donne déjà.', $this->error('port_groups.2.name_pattern'));
        $this->assertSame('Ce groupe donne le nom b1, que le groupe 2 donne déjà.', $this->error('port_groups.3.name_pattern'));

        // Names that only meet on the member 1: the pattern without the
        // number of the member gives "111", and so does the other one.
        $this->assertRefused(
            $this->store(['port_groups' => [
                $this->group(['quantity' => 1, 'name_pattern' => '{m}{n}', 'first_number' => 11]),
                $this->group(['quantity' => 1, 'name_pattern' => '{n}', 'first_number' => 111]),
            ]]),
            'port_groups.1.name_pattern',
            'Ce groupe donne le nom 111, que le groupe 1 donne déjà.',
        );

        $this->assertDatabaseCount('device_templates', 0);

        // Side by side, the same pattern is fine.
        $this->store(['port_groups' => [$this->group(), $this->group(['quantity' => 4, 'first_number' => 25])]])->assertSessionHasNoErrors();
    }

    public function test_names_too_long_for_a_port_are_refused(): void
    {
        // A pattern as long as a name can be: the mark of the number takes
        // three characters, and so does the number 999.
        $pattern = str_repeat('a', 29).'{n}';

        $this->store(['port_groups' => [$this->group(['quantity' => 1, 'first_number' => 999, 'name_pattern' => $pattern])]])->assertSessionHasNoErrors();

        // One port more: its number has four digits, its name 33 characters.
        $this->assertRefused(
            $this->store(['name' => 'Autre', 'port_groups' => [$this->group(['quantity' => 2, 'first_number' => 999, 'name_pattern' => $pattern])]]),
            'port_groups.0.name_pattern',
            'Les noms que donne ce motif dépassent 32 caractères.',
        );

        // The number of the member counts too, with its two digits: the
        // names are measured on the last member a device can have.
        $withMember = str_repeat('a', 26).'{m}{n}';

        $this->store(['name' => 'Autre', 'port_groups' => [$this->group(['quantity' => 128, 'first_number' => 999, 'name_pattern' => $withMember])]])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('device_templates', 2);
    }

    public function test_the_name_of_a_template_is_unique(): void
    {
        DeviceTemplate::factory()->create(['name' => 'Switch 24 ports']);

        $this->assertRefused($this->store(['name' => 'switch 24 PORTS']), 'name', 'Un autre modèle porte déjà ce nom.');

        $this->assertDatabaseCount('device_templates', 1);
    }

    public function test_an_editor_updates_a_template_and_its_groups_are_written_again_in_their_new_order(): void
    {
        $template = DeviceTemplate::factory()->switch(24, 4)->create(['name' => 'Switch 24 ports']);

        // The two groups swapped, one changed, a third one added.
        $this->actingAs($this->editor)
            ->put(route('device-templates.update', $template), $this->payload([
                'name' => 'Switch 24 ports',
                'model' => 'SW-24X',
                'port_groups' => [
                    $this->group(['quantity' => 2, 'name_pattern' => '{m}/1/{n}', 'connector' => 'sfp28', 'max_speed' => '25g', 'poe_type' => 'none']),
                    $this->group(),
                    $this->group(['quantity' => 1, 'name_pattern' => 'mgmt{n}', 'first_number' => 0, 'poe_type' => 'none']),
                ],
            ]))
            ->assertRedirect(route('device-templates.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Modèle modifié.');

        $this->assertSame('SW-24X', $template->refresh()->model);
        $this->assertSame([
            [1, 2, '{m}/1/{n}', 1, 'sfp28', '25g', 'none', null],
            [2, 24, '{m}/0/{n}', 1, 'rj45', '1g', '802.3at', null],
            [3, 1, 'mgmt{n}', 0, 'rj45', '1g', 'none', null],
        ], $this->groupsOf($template));
        $this->assertDatabaseCount('device_template_port_groups', 3);

        // A template keeps its own name, and a refused update changes
        // nothing.
        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('device-templates.update', $template), $this->payload(['port_groups' => [$this->group(['name_pattern' => 'port'])]])),
            'port_groups.0.name_pattern',
        );

        $this->assertDatabaseCount('device_template_port_groups', 3);
    }

    public function test_the_form_of_a_template_is_filled_in_and_offers_what_a_port_can_be(): void
    {
        $template = DeviceTemplate::factory()->switch(24, 4)->create(['name' => 'Switch 24 ports']);

        $this->actingAs($this->editor)
            ->get(route('device-templates.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('device-templates/Form')
                ->where('template', null)
                ->has('types', count(DeviceType::cases()))
                ->has('connectors', count(PortConnector::cases()))
                ->has('speeds', count(PortSpeed::cases()))
                ->has('poeTypes', count(PoeType::cases()))
                ->where('limits', ['groups' => 20, 'quantity' => 128, 'first_number' => 999, 'pattern' => 32]));

        $this->actingAs($this->editor)
            ->get(route('device-templates.edit', $template))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('template.id', $template->id)
                ->where('template.name', 'Switch 24 ports')
                ->has('template.port_groups', 2)
                ->where('template.port_groups.1.name_pattern', '{m}/1/{n}'));
    }

    // --- Deletion -------------------------------------------------------------

    public function test_a_template_no_member_uses_is_deleted_with_its_groups(): void
    {
        $template = DeviceTemplate::factory()->switch()->create();

        $this->actingAs($this->editor)
            ->delete(route('device-templates.destroy', $template))
            ->assertRedirect(route('device-templates.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Modèle supprimé.');

        $this->assertDatabaseCount('device_templates', 0);
        $this->assertDatabaseCount('device_template_port_groups', 0);
    }

    public function test_a_template_is_not_deleted_while_a_member_uses_it(): void
    {
        $template = DeviceTemplate::factory()->switch()->create();
        $members = DeviceMember::factory()->count(2)->sequence(['position' => 1], ['position' => 2])->create(['device_template_id' => $template->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-templates.destroy', $template)),
            'device_template',
            "Des membres d'équipements utilisent encore ce modèle (2) : retirez-leur ce modèle d'abord.",
        );

        $members[0]->update(['device_template_id' => null]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-templates.destroy', $template)),
            'device_template',
            "Un membre d'équipement utilise encore ce modèle : retirez-lui ce modèle d'abord.",
        );

        $this->assertDatabaseHas('device_templates', ['id' => $template->id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceTemplateDeleted));

        $members[1]->update(['device_template_id' => null]);

        $this->actingAs($this->editor)->delete(route('device-templates.destroy', $template))->assertSessionHasNoErrors();

        // The members are still there, without a template.
        $this->assertDatabaseCount('device_templates', 0);
        $this->assertDatabaseCount('device_members', 2);
    }

    // --- Generic templates ----------------------------------------------------

    public function test_the_generic_templates_are_added_on_demand(): void
    {
        $this->actingAs($this->editor)
            ->post(route('device-templates.generics.store'))
            ->assertRedirect(route('device-templates.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '5 modèles génériques ajoutés.');

        // The five the application comes with, each with its type and its
        // ports.
        $this->assertSame(
            [
                'Pare-feu 8 ports' => ['firewall', [[1, 8, 'port{n}', 1, 'rj45', '1g', 'none', null]]],
                'Serveur 2 ports' => ['server', [[1, 2, 'eth{n}', 0, 'rj45', '1g', 'none', null]]],
                'Switch 24 ports PoE+ et 4 SFP+' => ['switch', [
                    [1, 24, '{m}/0/{n}', 1, 'rj45', '1g', '802.3at', null],
                    [2, 4, '{m}/1/{n}', 1, 'sfp_plus', '10g', 'none', null],
                ]],
                'Switch 48 ports PoE+ et 4 SFP+' => ['switch', [
                    [1, 48, '{m}/0/{n}', 1, 'rj45', '1g', '802.3at', null],
                    [2, 4, '{m}/1/{n}', 1, 'sfp_plus', '10g', 'none', null],
                ]],
                'Switch 8 ports' => ['switch', [[1, 8, '{n}', 1, 'rj45', '1g', 'none', null]]],
            ],
            DeviceTemplate::query()->orderBy('name')->get()
                ->mapWithKeys(fn (DeviceTemplate $template): array => [$template->name => [$template->device_type->value, $this->groupsOf($template)]])
                ->all(),
        );

        // Without a brand.
        $this->assertSame(0, DeviceTemplate::query()->whereNotNull('manufacturer')->orWhereNotNull('model')->count());
        $this->assertSame(5, $this->countEntries(ActivityEvent::DeviceTemplateCreated));

        // Nothing is missing any more: the page no longer offers the action.
        $this->actingAs($this->editor)
            ->get(route('device-templates.index'))
            ->assertInertia(fn (Assert $page) => $page->has('templates', 5)->where('missingGenerics', 0));
    }

    public function test_adding_the_generic_templates_again_creates_no_duplicate_and_touches_no_existing_one(): void
    {
        $this->actingAs($this->editor)->post(route('device-templates.generics.store'));

        // One of them is changed, one renamed, one deleted, and a template
        // of the organisation is added.
        $changed = DeviceTemplate::query()->where('name', 'Switch 8 ports')->sole();

        $this->actingAs($this->editor)->put(route('device-templates.update', $changed), $this->payload([
            'name' => 'Switch 8 ports',
            'manufacturer' => 'Trois Brins Matériel',
            'port_groups' => [$this->group(['quantity' => 10, 'name_pattern' => 'ge{n}', 'poe_type' => 'none'])],
        ]))->assertSessionHasNoErrors();

        DeviceTemplate::query()->where('name', 'Serveur 2 ports')->sole()->update(['name' => 'Serveur lame']);
        DeviceTemplate::query()->where('name', 'Pare-feu 8 ports')->sole()->delete();
        DeviceTemplate::factory()->create(['name' => 'Borne Wi-Fi']);

        $this->actingAs($this->editor)
            ->get(route('device-templates.index'))
            ->assertInertia(fn (Assert $page) => $page->where('missingGenerics', 2));

        // Those that are missing by their name come back; nothing else moves.
        $this->actingAs($this->editor)
            ->post(route('device-templates.generics.store'))
            ->assertInertiaFlash('toast.message', '2 modèles génériques ajoutés.');

        $this->assertSame(
            ['Borne Wi-Fi', 'Pare-feu 8 ports', 'Serveur 2 ports', 'Serveur lame', 'Switch 24 ports PoE+ et 4 SFP+', 'Switch 48 ports PoE+ et 4 SFP+', 'Switch 8 ports'],
            DeviceTemplate::query()->orderBy('name')->pluck('name')->all(),
        );

        $changed->refresh();

        $this->assertSame('Trois Brins Matériel', $changed->manufacturer);
        $this->assertSame([[1, 10, 'ge{n}', 1, 'rj45', '1g', 'none', null]], $this->groupsOf($changed));

        // A third time: nothing to add, and the page says so.
        $this->actingAs($this->editor)
            ->post(route('device-templates.generics.store'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Les modèles génériques sont déjà tous là.');

        $this->assertDatabaseCount('device_templates', 7);
        $this->assertSame(7, $this->countEntries(ActivityEvent::DeviceTemplateCreated));

        // With one template missing, the message is in the singular.
        DeviceTemplate::query()->where('name', 'Switch 8 ports')->sole()->delete();

        $this->actingAs($this->editor)
            ->post(route('device-templates.generics.store'))
            ->assertInertiaFlash('toast.message', '1 modèle générique ajouté.');
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_reads_the_templates_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $template = DeviceTemplate::factory()->switch()->create(['name' => 'Switch 24 ports']);

        $this->actingAs($reader)->get(route('device-templates.index'))->assertOk();

        $this->actingAs($reader)->get(route('device-templates.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('device-templates.store'), $this->payload(['name' => 'Autre']))->assertForbidden();
        $this->actingAs($reader)->post(route('device-templates.generics.store'))->assertForbidden();
        $this->actingAs($reader)->get(route('device-templates.edit', $template))->assertForbidden();
        $this->actingAs($reader)->put(route('device-templates.update', $template), $this->payload(['name' => 'Renommé']))->assertForbidden();
        $this->actingAs($reader)->delete(route('device-templates.destroy', $template))->assertForbidden();

        $this->assertSame(['Switch 24 ports'], DeviceTemplate::query()->pluck('name')->all());
        $this->assertDatabaseCount('device_template_port_groups', 2);
    }

    public function test_an_administrator_writes_templates_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('device-templates.index'))->assertRedirect(route('login'));
        $this->post(route('device-templates.store'), $this->payload())->assertRedirect(route('login'));
        $this->post(route('device-templates.generics.store'))->assertRedirect(route('login'));

        $this->actingAs($this->admin())->post(route('device-templates.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('device_templates', ['name' => 'Switch 24 ports']);

        $this->actingAs($this->editor)->get(route('device-templates.edit', 999999))->assertNotFound();
    }

    // --- Activity log ---------------------------------------------------------

    public function test_the_life_of_a_template_is_logged_with_its_groups_on_one_line(): void
    {
        $this->store(['model' => 'SW-24', 'port_groups' => [
            $this->group(['poe_label' => 'PoE+']),
            $this->group(['quantity' => 4, 'name_pattern' => '{m}/1/{n}', 'connector' => 'sfp_plus', 'max_speed' => '10g', 'poe_type' => 'none']),
        ]]);

        $template = DeviceTemplate::query()->sole();
        $created = $this->lastEntry(ActivityEvent::DeviceTemplateCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($template->is($created->subject));
        $this->assertSame('Switch 24 ports', $created->getProperty('label'));
        $this->assertSame([
            'name' => 'Switch 24 ports',
            'device_type' => 'switch',
            'manufacturer' => null,
            'model' => 'SW-24',
            'poe_budget_watts' => null,
            'port_groups' => '24 x {m}/0/{n} from 1, rj45 1g 802.3at PoE+ ; 4 x {m}/1/{n} from 1, sfp_plus 10g none',
            'description' => null,
        ], ($created->attribute_changes?->toArray() ?? [])['attributes']);

        // Only what changed: here, the groups and the budget.
        $this->actingAs($this->editor)->put(route('device-templates.update', $template), $this->payload([
            'model' => 'SW-24', 'poe_budget_watts' => 370, 'port_groups' => [$this->group(['quantity' => 48])],
        ]));

        $updated = $this->lastEntry(ActivityEvent::DeviceTemplateUpdated)->attribute_changes?->toArray() ?? [];

        $this->assertSame(['poe_budget_watts', 'port_groups'], array_keys($updated['attributes']));
        $this->assertSame('48 x {m}/0/{n} from 1, rj45 1g 802.3at', $updated['attributes']['port_groups']);
        $this->assertSame(370, $updated['attributes']['poe_budget_watts']);

        // An update that changes nothing, and a refused one, write nothing.
        $this->actingAs($this->editor)->put(route('device-templates.update', $template), $this->payload([
            'model' => 'SW-24', 'poe_budget_watts' => 370, 'port_groups' => [$this->group(['quantity' => 48])],
        ]));
        $this->actingAs($this->editor)->put(route('device-templates.update', $template), $this->payload(['port_groups' => [$this->group(['name_pattern' => 'port'])]]));

        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceTemplateUpdated));

        $this->actingAs($this->editor)->delete(route('device-templates.destroy', $template));

        $deleted = $this->lastEntry(ActivityEvent::DeviceTemplateDeleted);

        $this->assertNull($deleted->subject);
        $this->assertSame('Switch 24 ports', $deleted->getProperty('label'));
        $this->assertSame('48 x {m}/0/{n} from 1, rj45 1g 802.3at', ($deleted->attribute_changes?->toArray() ?? [])['old']['port_groups']);
    }

    public function test_the_log_page_reads_a_template_in_french(): void
    {
        $this->store(['poe_budget_watts' => 370]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', "Modèle d'équipement créé")
                ->where('entries.data.0.subject.title', 'Switch 24 ports')
                ->where('entries.data.0.changes', [
                    ['field' => 'Nom', 'old' => null, 'new' => 'Switch 24 ports'],
                    ['field' => 'Type', 'old' => null, 'new' => 'Switch'],
                    ['field' => 'Budget PoE (W)', 'old' => null, 'new' => '370'],
                    ['field' => 'Groupes de ports', 'old' => null, 'new' => '24 x {m}/0/{n} from 1, rj45 1g 802.3at'],
                ]));
    }
}
