<?php

// torsade/tests/Feature/Network/DeviceMemberTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\MemberRole;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Port;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use Database\Factories\PortFactory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The members of a device: the boxes it is made of, one for a switch alone,
 * several for a stack. A member made from a template gets its ports.
 */
class DeviceMemberTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Device $switch;

    private DeviceTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'type' => DeviceType::Switch]);
        $this->template = DeviceTemplate::factory()->switch(24, 4)->create(['name' => 'Switch 24 ports']);
    }

    /**
     * A room of a site, in a building and on a floor named after the site.
     */
    private function room(Site $site, string $name = 'Local technique'): Room
    {
        $building = Building::query()->firstOrCreate(['site_id' => $site->id, 'name' => 'Bâtiment principal']);
        $floor = Floor::query()->firstOrCreate(['building_id' => $building->id, 'level' => 0], ['name' => 'Rez-de-chaussée']);

        return Room::factory()->create(['floor_id' => $floor->id, 'name' => $name, 'is_technical' => true]);
    }

    /**
     * What the window of a member sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'position' => 1,
            'role' => 'member',
            'device_template_id' => null,
            'serial_number' => '',
            'room_id' => null,
            'description' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function add(array $overrides = [], ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('device-members.store', $device ?? $this->switch), $this->payload($overrides));
    }

    /**
     * @return TestResponse<Response>
     */
    private function choose(?int $template, ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('devices.template.store', $device ?? $this->switch), ['device_template_id' => $template]);
    }

    /**
     * The names of the ports of a device, member by member, in their order.
     *
     * @return array<int, list<string>>
     */
    private function portNames(?Device $device = null): array
    {
        return ($device ?? $this->switch)->members()->with('ports')->get()
            ->mapWithKeys(fn (DeviceMember $member): array => [$member->position => array_values($member->ports->pluck('name')->all())])
            ->all();
    }

    // --- Adding a member ------------------------------------------------------

    public function test_an_editor_adds_a_member_without_a_template_which_has_no_port(): void
    {
        $room = $this->room($this->office);

        $this->add(['position' => 2, 'role' => 'secondary', 'serial_number' => 'SN-0002', 'room_id' => $room->id, 'description' => 'Baie B'])
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Membre ajouté.');

        $member = DeviceMember::query()->sole();

        $this->assertSame(
            [$this->switch->id, 2, MemberRole::Secondary, null, 'SN-0002', $room->id, 'Baie B'],
            [$member->device_id, $member->position, $member->role, $member->device_template_id, $member->serial_number, $member->room_id, $member->description],
        );
        $this->assertDatabaseCount('ports', 0);
    }

    public function test_a_member_added_with_a_template_gets_its_ports_named_with_its_number(): void
    {
        $this->add(['position' => 1, 'role' => 'primary', 'device_template_id' => $this->template->id])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Membre ajouté avec 28 ports.');

        $this->add(['position' => 3, 'device_template_id' => $this->template->id])->assertSessionHasNoErrors();

        $names = $this->portNames();

        // The number of the member, not its rank: the third box of a stack
        // names its ports "3/…" whether the second one is declared or not.
        $this->assertSame(
            [...array_map(fn (int $n): string => "1/0/{$n}", range(1, 24)), '1/1/1', '1/1/2', '1/1/3', '1/1/4'],
            $names[1],
        );
        $this->assertSame(
            [...array_map(fn (int $n): string => "3/0/{$n}", range(1, 24)), '3/1/1', '3/1/2', '3/1/3', '3/1/4'],
            $names[3],
        );

        // Each port takes what its group says, in the order of the groups.
        $member = DeviceMember::query()->where('position', 3)->sole();
        $ports = $member->ports()->get();

        $this->assertSame(range(1, 28), $ports->pluck('position')->all());

        $copper = $ports[0];
        $fibre = $ports[24];

        $this->assertSame(
            ['3/0/1', 'rj45', '1g', '802.3at', null, true, null, null, null, $this->switch->id],
            [$copper->name, $copper->connector->value, $copper->max_speed->value, $copper->poe_type->value, $copper->poe_label, $copper->enabled, $copper->mode, $copper->port_aggregate_id, $copper->description, $copper->device_id],
        );
        $this->assertSame(
            ['3/1/1', 'sfp_plus', '10g', 'none'],
            [$fibre->name, $fibre->connector->value, $fibre->max_speed->value, $fibre->poe_type->value],
        );

        $this->assertDatabaseCount('ports', 56);
        $this->assertDatabaseCount('port_vlans', 0);
    }

    public function test_the_label_of_a_group_is_copied_to_its_ports(): void
    {
        $this->template->portGroups()->orderBy('position')->firstOrFail()->update(['poe_label' => 'PoE+']);

        $this->add(['device_template_id' => $this->template->id])->assertSessionHasNoErrors();

        $this->assertSame(24, Port::query()->where('poe_label', 'PoE+')->count());
        $this->assertSame(4, Port::query()->whereNull('poe_label')->count());
    }

    public function test_changing_a_template_afterwards_changes_no_port(): void
    {
        $this->add(['device_template_id' => $this->template->id]);

        $this->actingAs($this->editor)->put(route('device-templates.update', $this->template), [
            'name' => 'Switch 24 ports', 'device_type' => 'switch',
            'port_groups' => [['quantity' => 8, 'name_pattern' => 'ge{n}', 'first_number' => 0, 'connector' => 'rj45', 'max_speed' => '100m', 'poe_type' => 'none']],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ports', 28);
        $this->assertSame(0, Port::query()->where('name', 'like', 'ge%')->count());
        $this->assertSame(24, Port::query()->where('max_speed', '1g')->count());
    }

    public function test_the_shape_of_a_member_is_checked(): void
    {
        foreach ([0, 17, -1, 1.5, '', 'deux'] as $position) {
            $this->assertRefused($this->add(['position' => $position]), 'position');
        }

        $this->assertRefused($this->add(['role' => 'chef']), 'role');
        $this->assertRefused($this->add(['role' => '']), 'role');
        $this->assertRefused($this->add(['device_template_id' => 999999]), 'device_template_id');
        $this->assertRefused($this->add(['room_id' => 999999]), 'room_id');
        $this->assertRefused($this->add(['serial_number' => str_repeat('a', 101)]), 'serial_number');
        $this->assertRefused($this->add(['description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('device_members', 0);

        // At the limits, a member is accepted.
        $this->add(['position' => 16, 'serial_number' => str_repeat('a', 100), 'description' => str_repeat('b', 1000)])->assertSessionHasNoErrors();
        $this->add(['position' => 1])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('device_members', 2);
    }

    public function test_the_number_of_a_member_is_unique_within_its_device(): void
    {
        $this->add(['position' => 2])->assertSessionHasNoErrors();

        $this->assertRefused($this->add(['position' => 2]), 'position', 'Cet équipement a déjà un membre 2.');

        // Another device has its own numbers.
        $other = Device::factory()->create(['site_id' => $this->office->id]);

        $this->add(['position' => 2], $other)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('device_members', 2);
    }

    public function test_a_device_has_one_primary_member_and_one_secondary_member_at_most(): void
    {
        $this->add(['position' => 1, 'role' => 'primary'])->assertSessionHasNoErrors();
        $this->add(['position' => 2, 'role' => 'secondary'])->assertSessionHasNoErrors();

        // The refusal is told on the field of the role, and names the
        // member that has it.
        $this->assertRefused(
            $this->add(['position' => 3, 'role' => 'primary']),
            'role',
            'Cet équipement a déjà un membre principal, le membre 1 : il en a un au plus.',
        );
        $this->assertSame(['role'], $this->errorFields());

        $this->assertRefused(
            $this->add(['position' => 3, 'role' => 'secondary']),
            'role',
            'Cet équipement a déjà un membre secondaire, le membre 2 : il en a un au plus.',
        );

        // As many plain members as wanted.
        $this->add(['position' => 3])->assertSessionHasNoErrors();
        $this->add(['position' => 4])->assertSessionHasNoErrors();

        // Each device has its own primary.
        $other = Device::factory()->create(['site_id' => $this->office->id]);

        $this->add(['position' => 1, 'role' => 'primary'], $other)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('device_members', 5);
    }

    public function test_the_room_of_a_member_belongs_to_the_site_of_its_device(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $elsewhere = $this->room($workshop, 'Baie atelier');

        $this->assertRefused(
            $this->add(['room_id' => $elsewhere->id]),
            'room_id',
            "Le local Bâtiment principal › Rez-de-chaussée › Baie atelier appartient au site Atelier : choisissez un local du site de l'équipement.",
        );

        $this->assertDatabaseCount('device_members', 0);

        // Another room of the site than the one of the device: a member of
        // a stack kept in the next rack.
        $this->switch->update(['room_id' => $this->room($this->office, 'Baie A')->id]);

        $this->add(['room_id' => $this->room($this->office, 'Baie B')->id])->assertSessionHasNoErrors();

        $this->assertSame('Baie B', DeviceMember::query()->sole()->room?->name);
    }

    public function test_a_template_that_would_repeat_names_of_the_device_adds_nothing(): void
    {
        // A template whose names do not hold the number of the member.
        $plain = DeviceTemplate::factory()->create(['name' => 'Switch 8 ports']);
        $plain->portGroups()->create(['position' => 1, 'quantity' => 12, 'name_pattern' => '{n}', 'first_number' => 1, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none']);

        $this->add(['position' => 1, 'device_template_id' => $plain->id])->assertSessionHasNoErrors();

        // Ten names at most, then how many more.
        $this->assertRefused(
            $this->add(['position' => 2, 'device_template_id' => $plain->id]),
            'device_template_id',
            "Ce modèle donne des noms que l'équipement a déjà pour des ports : 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 et 2 de plus.",
        );

        // Neither the member nor any of its ports.
        $this->assertDatabaseCount('device_members', 1);
        $this->assertDatabaseCount('ports', 12);
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberCreated));
        $this->assertSame(12, ($this->lastEntry(ActivityEvent::DeviceMemberCreated)->attribute_changes?->toArray() ?? [])['attributes']['ports_count']);

        // One name only: the message is in the singular, and the name is
        // found whatever its case.
        Port::query()->delete();
        PortFactory::series(DeviceMember::query()->sole(), 1);
        Port::query()->sole()->update(['name' => 'GE1']);

        $ge = DeviceTemplate::factory()->create(['name' => 'Deux ports']);
        $ge->portGroups()->create(['position' => 1, 'quantity' => 2, 'name_pattern' => 'ge{n}', 'first_number' => 1, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none']);

        $this->assertRefused(
            $this->add(['position' => 2, 'device_template_id' => $ge->id]),
            'device_template_id',
            "Ce modèle donne un nom que l'équipement a déjà pour un port : GE1.",
        );

        // The same template on another device is fine.
        $other = Device::factory()->create(['site_id' => $this->office->id]);

        $this->add(['position' => 2, 'device_template_id' => $ge->id], $other)->assertSessionHasNoErrors();
    }

    public function test_a_template_whose_patterns_meet_on_the_number_of_the_member_adds_nothing(): void
    {
        // Distinct on the member 1, "111" and "211"; the same on the
        // member 2.
        $template = DeviceTemplate::factory()->create(['name' => 'Piège']);
        $template->portGroups()->create(['position' => 1, 'quantity' => 1, 'name_pattern' => '{m}{n}', 'first_number' => 11, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none']);
        $template->portGroups()->create(['position' => 2, 'quantity' => 1, 'name_pattern' => '2{n}', 'first_number' => 11, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none']);

        $this->add(['position' => 1, 'device_template_id' => $template->id])->assertSessionHasNoErrors();

        $this->assertRefused(
            $this->add(['position' => 2, 'device_template_id' => $template->id]),
            'device_template_id',
            'Pour le membre 2, ce modèle donne deux fois le nom 211.',
        );

        $this->assertSame([1 => ['111', '211']], $this->portNames());
    }

    // --- The first member -----------------------------------------------------

    public function test_choosing_a_template_for_a_device_without_member_creates_its_member_1_primary(): void
    {
        $this->choose($this->template->id)
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Membre ajouté avec 28 ports.');

        $member = DeviceMember::query()->sole();

        $this->assertSame(
            [$this->switch->id, 1, MemberRole::Primary, $this->template->id, null, null],
            [$member->device_id, $member->position, $member->role, $member->device_template_id, $member->serial_number, $member->room_id],
        );
        $this->assertSame(28, $member->ports()->count());
        $this->assertSame('1/0/1', $member->ports()->firstOrFail()->name);
    }

    public function test_a_template_is_only_chosen_for_a_device_that_has_no_member(): void
    {
        $this->assertRefused($this->choose(null), 'device_template_id');
        $this->assertRefused($this->choose(999999), 'device_template_id');

        $this->assertDatabaseCount('device_members', 0);

        $this->choose($this->template->id)->assertSessionHasNoErrors();

        $this->assertRefused(
            $this->choose($this->template->id),
            'device_template_id',
            'Cet équipement a déjà des membres : ajoutez-lui plutôt un membre.',
        );

        $this->assertDatabaseCount('device_members', 1);
        $this->assertDatabaseCount('ports', 28);
    }

    public function test_the_serial_number_of_a_device_goes_to_its_first_member_and_leaves_the_device(): void
    {
        $this->switch->update(['serial_number' => 'SN-BOITIER']);

        $this->choose($this->template->id)->assertSessionHasNoErrors();

        $member = DeviceMember::query()->sole();

        // The number exists in one place only.
        $this->assertSame('SN-BOITIER', $member->serial_number);
        $this->assertNull($this->switch->refresh()->serial_number);

        // Both sides are in the activity log: the member with the number,
        // the device without it.
        $created = ($this->lastEntry(ActivityEvent::DeviceMemberCreated)->attribute_changes?->toArray() ?? [])['attributes'];
        $updated = $this->lastEntry(ActivityEvent::DeviceUpdated);

        $this->assertSame('SN-BOITIER', $created['serial_number']);
        $this->assertTrue($this->editor->is($updated->causer));
        $this->assertSame('sw-coeur (Siège)', $updated->getProperty('label'));
        $this->assertSame(
            ['old' => ['serial_number' => 'SN-BOITIER'], 'attributes' => ['serial_number' => null]],
            $updated->attribute_changes?->toArray(),
        );

        // A second member takes nothing: the device has no number left.
        $this->add(['position' => 2])->assertSessionHasNoErrors();

        $this->assertNull(DeviceMember::query()->where('position', 2)->sole()->serial_number);
        $this->assertSame('SN-BOITIER', $member->refresh()->serial_number);
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceUpdated));

        // "Add ports" on a device without a member creates its member 1
        // too, and does the same.
        $firewall = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege', 'serial_number' => 'SN-PF']);

        $this->actingAs($this->editor)->post(route('ports.store', $firewall), [
            'device_member_id' => null, 'name_pattern' => 'port{n}', 'first_number' => 1, 'last_number' => 2,
            'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none',
        ])->assertSessionHasNoErrors();

        $this->assertSame('SN-PF', $firewall->members()->sole()->serial_number);
        $this->assertNull($firewall->refresh()->serial_number);
        $this->assertSame(2, $this->countEntries(ActivityEvent::DeviceUpdated));

        // A device without a serial number has nothing to give, and is
        // not written to the log.
        $bare = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-etage']);

        $this->choose($this->template->id, $bare)->assertSessionHasNoErrors();

        $this->assertNull($bare->members()->sole()->serial_number);
        $this->assertSame(2, $this->countEntries(ActivityEvent::DeviceUpdated));
    }

    public function test_a_first_member_given_a_serial_number_of_its_own_leaves_the_one_of_the_device(): void
    {
        $this->switch->update(['serial_number' => 'SN-BOITIER']);

        $this->add(['position' => 1, 'role' => 'primary', 'serial_number' => 'SN-MEMBRE'])->assertSessionHasNoErrors();

        // Nothing is lost: the device keeps the number it had.
        $this->assertSame('SN-MEMBRE', DeviceMember::query()->sole()->serial_number);
        $this->assertSame('SN-BOITIER', $this->switch->refresh()->serial_number);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceUpdated));
    }

    public function test_a_refused_first_member_leaves_the_serial_number_on_the_device(): void
    {
        $this->switch->update(['serial_number' => 'SN-BOITIER']);

        // A template that gives the same name twice, as none can be saved
        // from its form.
        $twice = DeviceTemplate::factory()->create(['name' => 'Deux fois']);

        foreach ([1, 2] as $position) {
            $twice->portGroups()->create(['position' => $position, 'quantity' => 1, 'name_pattern' => 'eth{n}', 'first_number' => 0, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none']);
        }

        $this->assertRefused($this->choose($twice->id), 'device_template_id', 'Pour le membre 1, ce modèle donne deux fois le nom eth0.');

        $this->assertDatabaseCount('device_members', 0);
        $this->assertSame('SN-BOITIER', $this->switch->refresh()->serial_number);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceUpdated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberCreated));
    }

    // --- Updating a member ----------------------------------------------------

    public function test_an_editor_updates_a_member_and_its_ports_stay_as_they_are(): void
    {
        $this->add(['position' => 1, 'role' => 'primary', 'device_template_id' => $this->template->id]);

        $member = DeviceMember::query()->sole();
        $room = $this->room($this->office);
        $other = DeviceTemplate::factory()->switch(48, 4)->create(['name' => 'Switch 48 ports']);

        // Another number, another role, another template, and a room.
        $this->actingAs($this->editor)
            ->put(route('device-members.update', $member), $this->payload([
                'position' => 4, 'role' => 'secondary', 'device_template_id' => $other->id,
                'serial_number' => 'SN-0004', 'room_id' => $room->id, 'description' => 'Remplacé en mars',
            ]))
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Membre modifié.');

        $member->refresh();

        $this->assertSame(
            [4, MemberRole::Secondary, $other->id, 'SN-0004', $room->id, 'Remplacé en mars'],
            [$member->position, $member->role, $member->device_template_id, $member->serial_number, $member->room_id, $member->description],
        );

        // The names of ports are free: a new number does not rename them,
        // and another template does not replace them.
        $names = $this->portNames();

        $this->assertCount(28, $names[4]);
        $this->assertSame('1/0/1', $names[4][0]);
        $this->assertSame('1/1/4', $names[4][27]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortDeleted));

        // The template can be taken away: the first one is then free to
        // be deleted.
        $this->actingAs($this->editor)
            ->put(route('device-members.update', $member), $this->payload(['position' => 4, 'role' => 'secondary']))
            ->assertSessionHasNoErrors();

        $this->assertNull($member->refresh()->device_template_id);
        $this->assertNull($member->serial_number);
        $this->assertDatabaseCount('ports', 28);
    }

    public function test_an_updated_member_keeps_its_own_number_and_role_and_cannot_take_those_of_another(): void
    {
        $first = DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1]);
        $second = DeviceMember::factory()->secondary()->create(['device_id' => $this->switch->id, 'position' => 2]);

        $update = fn (DeviceMember $member, array $data): TestResponse => $this->actingAs($this->editor)
            ->put(route('device-members.update', $member), $this->payload($data));

        $update($first, ['position' => 1, 'role' => 'primary', 'serial_number' => 'SN-1'])->assertSessionHasNoErrors();

        $this->assertRefused($update($second, ['position' => 1, 'role' => 'secondary']), 'position', 'Cet équipement a déjà un membre 1.');
        $this->assertRefused(
            $update($second, ['position' => 2, 'role' => 'primary']),
            'role',
            'Cet équipement a déjà un membre principal, le membre 1 : il en a un au plus.',
        );
        $this->assertRefused(
            $update($first, ['position' => 1, 'role' => 'secondary']),
            'role',
            'Cet équipement a déjà un membre secondaire, le membre 2 : il en a un au plus.',
        );

        $this->assertSame([1 => 'primary', 2 => 'secondary'], $this->switch->members()->get()->mapWithKeys(fn (DeviceMember $member): array => [$member->position => $member->role->value])->all());

        // The roles are swapped in two steps, through "member".
        $update($second, ['position' => 2, 'role' => 'member'])->assertSessionHasNoErrors();
        $update($first, ['position' => 1, 'role' => 'secondary'])->assertSessionHasNoErrors();
        $update($second, ['position' => 2, 'role' => 'primary'])->assertSessionHasNoErrors();

        $this->assertSame(MemberRole::Primary, $second->refresh()->role);
    }

    // --- Deleting a member ----------------------------------------------------

    public function test_deleting_a_member_deletes_its_ports_and_only_its_own(): void
    {
        $this->add(['position' => 1, 'role' => 'primary', 'device_template_id' => $this->template->id]);
        $this->add(['position' => 2, 'device_template_id' => $this->template->id]);

        $second = DeviceMember::query()->where('position', 2)->sole();

        $this->actingAs($this->editor)
            ->delete(route('device-members.destroy', $second))
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Membre supprimé avec ses 28 ports.');

        $this->assertSame([1], $this->switch->members()->pluck('position')->all());
        $this->assertDatabaseCount('ports', 28);
        $this->assertSame(0, Port::query()->where('name', 'like', '2/%')->count());

        // A member without a port, and a member with one.
        $empty = DeviceMember::factory()->create(['device_id' => $this->switch->id, 'position' => 5]);
        $single = DeviceMember::factory()->create(['device_id' => $this->switch->id, 'position' => 6]);
        PortFactory::series($single, 1);

        $this->actingAs($this->editor)->delete(route('device-members.destroy', $empty))->assertInertiaFlash('toast.message', 'Membre supprimé.');
        $this->actingAs($this->editor)->delete(route('device-members.destroy', $single))->assertInertiaFlash('toast.message', 'Membre supprimé avec son port.');

        // Without its last member, the device has no port again.
        $this->actingAs($this->editor)->delete(route('device-members.destroy', DeviceMember::query()->sole()));

        $this->assertDatabaseCount('device_members', 0);
        $this->assertDatabaseCount('ports', 0);
        $this->assertDatabaseHas('devices', ['id' => $this->switch->id]);
    }

    // --- The page of the device -----------------------------------------------

    public function test_the_page_of_a_device_lists_its_members_by_number(): void
    {
        $room = $this->room($this->office, 'Baie B');

        $second = DeviceMember::factory()->secondary()->create([
            'device_id' => $this->switch->id, 'position' => 2, 'device_template_id' => $this->template->id,
            'serial_number' => 'SN-0002', 'room_id' => $room->id, 'description' => 'Baie B, haut',
        ]);
        $first = DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1]);
        PortFactory::series($second, 3);

        // The member of another device is not listed.
        DeviceMember::factory()->create(['position' => 1]);

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->switch))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/Show')
                ->has('members', 2)
                ->where('members.0', [
                    'id' => $first->id, 'position' => 1, 'role' => 'primary', 'role_label' => 'Principal', 'template' => null,
                    'serial_number' => null, 'room' => null, 'description' => null, 'ports_count' => 0,
                ])
                ->where('members.1', [
                    'id' => $second->id, 'position' => 2, 'role' => 'secondary', 'role_label' => 'Secondaire',
                    'template' => ['id' => $this->template->id, 'name' => 'Switch 24 ports'],
                    'serial_number' => 'SN-0002',
                    'room' => ['id' => $room->id, 'name' => 'Baie B', 'path' => 'Bâtiment principal › Rez-de-chaussée › Baie B', 'is_technical' => true],
                    'description' => 'Baie B, haut',
                    'ports_count' => 3,
                ])
                // What the window of a member chooses among.
                ->where('memberRoles', [
                    ['value' => 'primary', 'label' => 'Principal'],
                    ['value' => 'secondary', 'label' => 'Secondaire'],
                    ['value' => 'member', 'label' => 'Membre'],
                ])
                ->where('memberLimits', ['min' => 1, 'max' => 16])
                ->where('templates', [[
                    'id' => $this->template->id,
                    'name' => 'Switch 24 ports',
                    'ports_count' => 28,
                    'port_groups' => [
                        ['quantity' => 24, 'name_pattern' => '{m}/0/{n}', 'first_number' => 1],
                        ['quantity' => 4, 'name_pattern' => '{m}/1/{n}', 'first_number' => 1],
                    ],
                ]])
                ->has('rooms', 1)
                ->where('rooms.0.path', 'Bâtiment principal › Rez-de-chaussée › Baie B'));
    }

    public function test_the_page_offers_the_rooms_of_the_site_of_the_device_only(): void
    {
        $this->room($this->office, 'Baie B');
        $this->room($this->office, 'Baie A');
        $this->room(Site::factory()->create(['name' => 'Atelier']), 'Baie atelier');

        $this->assertSame(
            ['Bâtiment principal › Rez-de-chaussée › Baie A', 'Bâtiment principal › Rez-de-chaussée › Baie B'],
            array_column((array) $this->actingAs($this->reader())->get(route('devices.show', $this->switch))->inertiaProps('rooms'), 'path'),
        );

        // A device without a member has none to list.
        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->switch))
            ->assertInertia(fn (Assert $page) => $page->where('members', []));
    }

    // --- The serial number of the device --------------------------------------

    public function test_the_form_of_a_device_with_members_no_longer_asks_for_its_serial_number(): void
    {
        $this->switch->update(['serial_number' => 'SN-ANCIEN']);

        $hasMembers = fn (): mixed => $this->actingAs($this->editor)->get(route('devices.edit', $this->switch))->assertOk()->inertiaProps('device.has_members');

        $this->assertFalse($hasMembers());

        DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'serial_number' => 'SN-MEMBRE']);

        $this->assertTrue($hasMembers());

        // The number typed before stays, and is still shown on the page.
        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->switch))
            ->assertInertia(fn (Assert $page) => $page
                ->where('device.serial_number', 'SN-ANCIEN')
                ->where('members.0.serial_number', 'SN-MEMBRE'));

        // The form sends none: the number is neither emptied nor changed,
        // whatever is sent.
        $payload = [
            'site_id' => $this->office->id, 'room_id' => null, 'name' => 'sw-coeur-01', 'type' => 'switch',
            'manufacturer' => '', 'model' => '', 'status' => 'active', 'description' => '',
        ];

        $this->actingAs($this->editor)->put(route('devices.update', $this->switch), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->put(route('devices.update', $this->switch), [...$payload, 'serial_number' => 'SN-AUTRE'])->assertSessionHasNoErrors();

        $this->switch->refresh();

        $this->assertSame(['sw-coeur-01', 'SN-ANCIEN'], [$this->switch->name, $this->switch->serial_number]);

        // Without its members, the device has a serial number of its own
        // again.
        DeviceMember::query()->delete();

        $this->actingAs($this->editor)->put(route('devices.update', $this->switch), [...$payload, 'serial_number' => 'SN-AUTRE'])->assertSessionHasNoErrors();

        $this->assertSame('SN-AUTRE', $this->switch->refresh()->serial_number);
    }

    // --- What happens to the members of a device ------------------------------

    public function test_deleting_a_device_deletes_its_members_and_their_ports(): void
    {
        $this->add(['position' => 1, 'role' => 'primary', 'device_template_id' => $this->template->id]);
        $this->add(['position' => 2]);

        $other = Device::factory()->create(['site_id' => $this->office->id]);
        PortFactory::series(DeviceMember::factory()->create(['device_id' => $other->id]), 2);

        // Still refused while an address is attached to it.
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet()->id, 'address' => '192.168.100.2', 'device_id' => $this->switch->id]);

        $this->assertRefused($this->actingAs($this->editor)->delete(route('devices.destroy', $this->switch)), 'device');
        $this->assertDatabaseCount('ports', 30);

        IpAddress::query()->whereKey($address->id)->update(['device_id' => null]);

        $this->actingAs($this->editor)->delete(route('devices.destroy', $this->switch))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('devices', ['id' => $this->switch->id]);
        $this->assertSame([$other->id], DeviceMember::query()->pluck('device_id')->all());
        $this->assertDatabaseCount('ports', 2);

        // One entry of the activity log for the device and everything
        // that went with it: its members, by their numbers, and its ports,
        // by their names.
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberDeleted));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortDeleted));
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceDeleted));

        $old = ($this->lastEntry(ActivityEvent::DeviceDeleted)->attribute_changes?->toArray() ?? [])['old'];

        $this->assertSame('sw-coeur', $old['name']);
        $this->assertSame('1, 2', $old['members']);
        $this->assertSame(28, $old['ports_count']);
        $this->assertSame(
            implode(', ', [...array_map(fn (int $n): string => "1/0/{$n}", range(1, 24)), '1/1/1', '1/1/2', '1/1/3', '1/1/4']),
            $old['ports'],
        );
        $this->assertArrayNotHasKey('aggregates', $old);

        // A device without a member tells nothing of the kind.
        $this->actingAs($this->editor)->delete(route('devices.destroy', Device::factory()->create(['site_id' => $this->office->id])))->assertSessionHasNoErrors();

        $bare = ($this->lastEntry(ActivityEvent::DeviceDeleted)->attribute_changes?->toArray() ?? [])['old'];

        $this->assertArrayNotHasKey('members', $bare);
        $this->assertArrayNotHasKey('ports_count', $bare);
        $this->assertArrayNotHasKey('ports', $bare);

        // The template is free again.
        $this->actingAs($this->editor)->delete(route('device-templates.destroy', $this->template))->assertSessionHasNoErrors();
    }

    public function test_the_members_of_a_device_moved_to_another_site_leave_their_rooms(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $room = $this->room($this->office, 'Baie B');

        $kept = DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1, 'room_id' => $room->id]);
        $free = DeviceMember::factory()->create(['device_id' => $this->switch->id, 'position' => 2]);

        $this->actingAs($this->editor)->put(route('devices.update', $this->switch), [
            'site_id' => $workshop->id, 'room_id' => null, 'name' => 'sw-coeur', 'type' => 'switch', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertNull($kept->refresh()->room_id);
        $this->assertNull($free->refresh()->room_id);

        // Told in the log, for the member that had a room.
        $entry = $this->lastEntry(ActivityEvent::DeviceMemberUpdated);

        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberUpdated));
        $this->assertSame('sw-coeur (Atelier) › Membre 1', $entry->getProperty('label'));
        $this->assertSame(
            ['old' => ['room' => 'Bâtiment principal › Rez-de-chaussée › Baie B'], 'attributes' => ['room' => null]],
            $entry->attribute_changes?->toArray(),
        );
    }

    public function test_a_room_is_not_deleted_while_a_member_is_kept_in_it(): void
    {
        $room = $this->room($this->office, 'Baie B');

        $members = DeviceMember::factory()->count(2)->sequence(['position' => 1], ['position' => 2])
            ->create(['device_id' => $this->switch->id, 'room_id' => $room->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $room)),
            'room',
            "Ce local contient encore 2 membres d'équipements : videz-le d'abord.",
        );

        $members[0]->update(['room_id' => null]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $room)),
            'room',
            "Ce local contient encore 1 membre d'équipement : videz-le d'abord.",
        );

        $members[1]->update(['room_id' => null]);

        $this->actingAs($this->editor)->delete(route('rooms.destroy', $room))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_cannot_write_members(): void
    {
        $reader = $this->reader();
        $member = DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id]);
        PortFactory::series($member, 2);

        $this->actingAs($reader)->post(route('device-members.store', $this->switch), $this->payload(['position' => 2]))->assertForbidden();
        $this->actingAs($reader)->post(route('devices.template.store', Device::factory()->create()), ['device_template_id' => $this->template->id])->assertForbidden();
        $this->actingAs($reader)->put(route('device-members.update', $member), $this->payload(['position' => 3]))->assertForbidden();
        $this->actingAs($reader)->delete(route('device-members.destroy', $member))->assertForbidden();

        $this->assertSame([1], DeviceMember::query()->pluck('position')->all());
        $this->assertDatabaseCount('ports', 2);
    }

    public function test_an_administrator_writes_members_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('device-members.store', $this->switch), $this->payload())->assertRedirect(route('login'));
        $this->post(route('devices.template.store', $this->switch), ['device_template_id' => $this->template->id])->assertRedirect(route('login'));

        $this->assertDatabaseCount('device_members', 0);

        $this->actingAs($this->admin())->post(route('device-members.store', $this->switch), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('device_members', 1);

        $this->actingAs($this->editor)->put(route('device-members.update', 999999), $this->payload())->assertNotFound();
        $this->actingAs($this->editor)->post(route('device-members.store', 999999), $this->payload())->assertNotFound();
    }

    // --- Activity log ---------------------------------------------------------

    public function test_the_life_of_a_member_is_logged_with_its_ports_in_its_own_entries(): void
    {
        $room = $this->room($this->office, 'Baie B');

        $this->add(['position' => 2, 'role' => 'secondary', 'device_template_id' => $this->template->id, 'serial_number' => 'SN-0002', 'room_id' => $room->id]);

        $member = DeviceMember::query()->sole();
        $names = implode(', ', [...array_map(fn (int $n): string => "2/0/{$n}", range(1, 24)), '2/1/1', '2/1/2', '2/1/3', '2/1/4']);
        $created = $this->lastEntry(ActivityEvent::DeviceMemberCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($member->is($created->subject));
        $this->assertSame('sw-coeur (Siège) › Membre 2', $created->getProperty('label'));
        $this->assertSame([
            'device' => 'sw-coeur (Siège)',
            'position' => 2,
            'role' => 'secondary',
            'template' => 'Switch 24 ports',
            'serial_number' => 'SN-0002',
            'room' => 'Bâtiment principal › Rez-de-chaussée › Baie B',
            'description' => null,
            // The ports copied from its template: how many, and their
            // names, in the entry of the member.
            'ports_count' => 28,
            'ports' => $names,
        ], ($created->attribute_changes?->toArray() ?? [])['attributes']);

        // One entry for the member and its 28 ports, none per port.
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberPortsAdded));

        // Only what changed.
        $this->actingAs($this->editor)->put(route('device-members.update', $member), $this->payload([
            'position' => 3, 'role' => 'secondary', 'device_template_id' => $this->template->id, 'serial_number' => 'SN-0002', 'room_id' => null,
        ]));

        $this->assertSame(
            ['old' => ['position' => 2, 'room' => 'Bâtiment principal › Rez-de-chaussée › Baie B'], 'attributes' => ['position' => 3, 'room' => null]],
            $this->lastEntry(ActivityEvent::DeviceMemberUpdated)->attribute_changes?->toArray(),
        );

        // An update that changes nothing, and a refused one, write nothing.
        $this->actingAs($this->editor)->put(route('device-members.update', $member), $this->payload([
            'position' => 3, 'role' => 'secondary', 'device_template_id' => $this->template->id, 'serial_number' => 'SN-0002',
        ]));
        $this->actingAs($this->editor)->put(route('device-members.update', $member), $this->payload(['position' => 0]));

        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberUpdated));

        // Deleted with its ports: one entry again, on the member, with
        // their number and their names.
        $this->actingAs($this->editor)->delete(route('device-members.destroy', $member));

        $deleted = $this->lastEntry(ActivityEvent::DeviceMemberDeleted);
        $old = ($deleted->attribute_changes?->toArray() ?? [])['old'];

        $this->assertNull($deleted->subject);
        $this->assertSame('sw-coeur (Siège) › Membre 3', $deleted->getProperty('label'));
        $this->assertSame(3, $old['position']);
        $this->assertSame(28, $old['ports_count']);
        $this->assertSame($names, $old['ports']);
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberDeleted));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortDeleted));
    }

    public function test_a_member_without_a_port_says_nothing_of_ports_in_the_log(): void
    {
        $this->add(['position' => 4]);

        $this->assertSame(
            ['device', 'position', 'role', 'template', 'serial_number', 'room', 'description'],
            array_keys(($this->lastEntry(ActivityEvent::DeviceMemberCreated)->attribute_changes?->toArray() ?? [])['attributes']),
        );

        $this->actingAs($this->editor)->delete(route('device-members.destroy', DeviceMember::query()->sole()));

        $this->assertSame(
            ['device', 'position', 'role', 'template', 'serial_number', 'room', 'description'],
            array_keys(($this->lastEntry(ActivityEvent::DeviceMemberDeleted)->attribute_changes?->toArray() ?? [])['old']),
        );
    }

    public function test_the_log_page_reads_a_member_and_its_ports_in_french(): void
    {
        $single = DeviceTemplate::factory()->create(['name' => 'Un port']);
        $single->portGroups()->create(['position' => 1, 'quantity' => 1, 'name_pattern' => '{m}/0/{n}', 'first_number' => 1, 'connector' => 'sfp_plus', 'max_speed' => '10g', 'poe_type' => '802.3at', 'poe_label' => 'PoE+']);

        $this->add(['position' => 1, 'role' => 'primary', 'device_template_id' => $single->id, 'serial_number' => 'SN-0001']);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                // The member and its port: one entry.
                ->where('entries.data.0.event_label', 'Membre créé')
                ->where('entries.data.0.subject.title', 'sw-coeur (Siège) › Membre 1')
                ->where('entries.data.0.changes', [
                    ['field' => 'Équipement', 'old' => null, 'new' => 'sw-coeur (Siège)'],
                    ['field' => 'Numéro', 'old' => null, 'new' => '1'],
                    ['field' => 'Rôle', 'old' => null, 'new' => 'Principal'],
                    ['field' => 'Modèle', 'old' => null, 'new' => 'Un port'],
                    ['field' => 'Numéro de série', 'old' => null, 'new' => 'SN-0001'],
                    ['field' => 'Nombre de ports', 'old' => null, 'new' => '1'],
                    ['field' => 'Ports', 'old' => null, 'new' => '1/0/1'],
                ]));

        // Its deletion, with the same port.
        $this->actingAs($this->editor)->delete(route('device-members.destroy', DeviceMember::query()->sole()));

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Membre supprimé')
                ->where('entries.data.0.changes.5', ['field' => 'Nombre de ports', 'old' => '1', 'new' => null])
                ->where('entries.data.0.changes.6', ['field' => 'Ports', 'old' => '1/0/1', 'new' => null]));
    }
}
