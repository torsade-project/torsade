<?php

// torsade/tests/Feature/Network/PortTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\MemberRole;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Port;
use App\Models\Site;
use App\Models\User;
use App\Services\Network\Ports;
use Database\Factories\PortFactory;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ports of a device: added in series to a member, modified one by one,
 * enabled, disabled and deleted one by one or several at a time.
 */
class PortTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Device $switch;

    private DeviceMember $first;

    private DeviceMember $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'type' => DeviceType::Switch]);
        $this->first = DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1]);
        $this->second = DeviceMember::factory()->secondary()->create(['device_id' => $this->switch->id, 'position' => 2]);
    }

    /**
     * What the window "Add ports" sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function series(array $overrides = []): array
    {
        return [
            'device_member_id' => $this->first->id,
            'name_pattern' => '{m}/0/{n}',
            'first_number' => 1,
            'last_number' => 4,
            'connector' => 'rj45',
            'max_speed' => '1g',
            'poe_type' => 'none',
            'poe_label' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function addSeries(array $overrides = [], ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('ports.store', $device ?? $this->switch), $this->series($overrides));
    }

    /**
     * What the window of a port sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => '1/0/1',
            'position' => 1,
            'connector' => 'rj45',
            'max_speed' => '1g',
            'poe_type' => 'none',
            'poe_label' => '',
            'enabled' => true,
            'description' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function update(Port $port, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->put(route('ports.update', $port), $this->payload($overrides));
    }

    /**
     * @param  list<int>  $ports
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $ports, ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->patch(route('ports.update-many', $device ?? $this->switch), ['ports' => $ports, 'action' => $action]);
    }

    /**
     * @param  list<int>  $ports
     * @return TestResponse<Response>
     */
    private function deleteMany(array $ports, ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->delete(route('ports.destroy-many', $device ?? $this->switch), ['ports' => $ports]);
    }

    /**
     * The names of the ports of a member, in their order.
     *
     * @return list<string>
     */
    private function names(DeviceMember $member): array
    {
        return array_values($member->ports()->pluck('name')->all());
    }

    /**
     * The identifiers of ports.
     *
     * @param  list<Port>  $ports
     * @return list<int>
     */
    private function ids(array $ports): array
    {
        return array_map(fn (Port $port): int => $port->id, $ports);
    }

    // --- A series of ports ----------------------------------------------------

    public function test_an_editor_adds_a_series_of_ports_to_a_member_named_with_its_number(): void
    {
        $this->addSeries(['device_member_id' => $this->second->id, 'first_number' => 1, 'last_number' => 24, 'poe_type' => '802.3at', 'poe_label' => 'PoE+'])
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '24 ports ajoutés.');

        $this->assertSame(array_map(fn (int $n): string => "2/0/{$n}", range(1, 24)), $this->names($this->second));
        $this->assertSame([], $this->names($this->first));

        $port = $this->second->ports()->firstOrFail();

        $this->assertSame(
            ['2/0/1', 1, 'rj45', '1g', '802.3at', 'PoE+', true, null, null, null, $this->switch->id],
            [$port->name, $port->position, $port->connector->value, $port->max_speed->value, $port->poe_type->value, $port->poe_label, $port->enabled, $port->mode, $port->port_aggregate_id, $port->description, $port->device_id],
        );

        // A second series comes after the first one, in the order of the
        // member.
        $this->addSeries(['device_member_id' => $this->second->id, 'name_pattern' => '{m}/1/{n}', 'first_number' => 1, 'last_number' => 1, 'connector' => 'sfp_plus', 'max_speed' => '10g'])
            ->assertInertiaFlash('toast.message', '1 port ajouté.');

        $uplink = $this->second->ports()->where('name', '2/1/1')->sole();

        $this->assertSame([25, 'sfp_plus', '10g', 'none', null], [$uplink->position, $uplink->connector->value, $uplink->max_speed->value, $uplink->poe_type->value, $uplink->poe_label]);
        $this->assertDatabaseCount('ports', 25);
    }

    public function test_adding_ports_to_a_device_without_member_creates_its_member_1_primary(): void
    {
        $firewall = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall]);

        $this->addSeries(['device_member_id' => null, 'name_pattern' => 'port{n}', 'first_number' => 1, 'last_number' => 8], $firewall)
            ->assertRedirect(route('devices.show', $firewall))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '8 ports ajoutés.');

        $member = $firewall->members()->sole();

        $this->assertSame([1, MemberRole::Primary, null], [$member->position, $member->role, $member->device_template_id]);
        $this->assertSame(array_map(fn (int $n): string => "port{$n}", range(1, 8)), $this->names($member));

        // A refused series leaves the device without a member.
        $server = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'srv-01']);

        $this->assertRefused($this->addSeries(['device_member_id' => null, 'name_pattern' => 'eth'], $server), 'name_pattern');
        $this->assertSame(0, $server->members()->count());
    }

    public function test_the_member_of_a_series_is_one_of_the_device(): void
    {
        // A device with members says which one.
        $this->assertRefused($this->addSeries(['device_member_id' => null]), 'device_member_id', 'Choisissez le membre auquel les ports sont ajoutés.');

        // The member of another device, or one that is gone.
        $other = DeviceMember::factory()->create(['position' => 1]);

        $this->assertRefused($this->addSeries(['device_member_id' => $other->id]), 'device_member_id', "Ce membre n'existe plus.");
        $this->assertRefused($this->addSeries(['device_member_id' => 999999]), 'device_member_id', "Ce membre n'existe plus.");

        $this->assertDatabaseCount('ports', 0);
        $this->assertDatabaseCount('device_members', 3);
    }

    public function test_the_shape_of_a_series_is_checked(): void
    {
        // The pattern follows the rules of the patterns of a template.
        $this->assertRefused($this->addSeries(['name_pattern' => '']), 'name_pattern');
        $this->assertRefused(
            $this->addSeries(['name_pattern' => 'port']),
            'name_pattern',
            'Pour plusieurs ports, un motif contient {n}, le numéro du port, une fois : « {m}/0/{n} » ou « port{n} ». Un port seul peut s\'en passer : « MGMT ».',
        );
        $this->assertRefused($this->addSeries(['name_pattern' => '{m}/{m}/{n}']), 'name_pattern', 'Un motif contient {m}, le numéro du membre, une fois au plus.');
        $this->assertRefused($this->addSeries(['name_pattern' => 'port {n}']), 'name_pattern');
        $this->assertRefused($this->addSeries(['name_pattern' => str_repeat('a', 30).'{n}']), 'name_pattern');

        foreach ([-1, 1000, 1.5, '', 'un'] as $first) {
            $this->assertRefused($this->addSeries(['first_number' => $first]), 'first_number');
        }

        // The last number is the first one or after it.
        $this->assertRefused($this->addSeries(['first_number' => 5, 'last_number' => 4]), 'last_number');
        $this->assertRefused($this->addSeries(['last_number' => '']), 'last_number');
        $this->assertRefused($this->addSeries(['last_number' => 1.5]), 'last_number');
        $this->assertRefused($this->addSeries(['first_number' => 999, 'last_number' => 1127]), 'last_number');

        $this->assertRefused($this->addSeries(['connector' => 'usb']), 'connector');
        $this->assertRefused($this->addSeries(['max_speed' => '1000g']), 'max_speed');
        $this->assertRefused($this->addSeries(['poe_type' => 'poe']), 'poe_type');
        $this->assertRefused($this->addSeries(['poe_label' => str_repeat('a', 33)]), 'poe_label');

        $this->assertDatabaseCount('ports', 0);
    }

    public function test_one_port_alone_is_added_without_the_number_of_the_port_in_its_pattern(): void
    {
        // The first and the last number are the same: one port, named as
        // it is written.
        $this->addSeries(['name_pattern' => 'MGMT', 'first_number' => 1, 'last_number' => 1])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 port ajouté.');
        $this->addSeries(['name_pattern' => '{m}/WAN', 'first_number' => 0, 'last_number' => 0, 'device_member_id' => $this->second->id])->assertSessionHasNoErrors();
        // With the number of the port, as before.
        $this->addSeries(['name_pattern' => 'console{n}', 'first_number' => 7, 'last_number' => 7])->assertSessionHasNoErrors();

        $this->assertSame(['MGMT', 'console7'], $this->names($this->first));
        $this->assertSame(['2/WAN'], $this->names($this->second));

        // Several ports cannot all bear the same name: the number of the
        // port is needed, and the refusal says when it is not.
        $this->assertRefused(
            $this->addSeries(['name_pattern' => 'LAN', 'first_number' => 1, 'last_number' => 2]),
            'name_pattern',
            'Pour plusieurs ports, un motif contient {n}, le numéro du port, une fois : « {m}/0/{n} » ou « port{n} ». Un port seul peut s\'en passer : « MGMT ».',
        );
        $this->assertRefused($this->addSeries(['name_pattern' => '{m}/LAN', 'first_number' => 0, 'last_number' => 127]), 'name_pattern');

        // The other rules of a pattern still hold for one port.
        $this->assertRefused(
            $this->addSeries(['name_pattern' => 'MG MT', 'first_number' => 1, 'last_number' => 1]),
            'name_pattern',
            'En dehors de {m} et de {n}, un motif ne contient que des lettres, des chiffres et les caractères / - . et le deux-points.',
        );
        $this->assertRefused($this->addSeries(['name_pattern' => '{n}/{n}', 'first_number' => 1, 'last_number' => 1]), 'name_pattern');
        $this->assertRefused($this->addSeries(['name_pattern' => '{m}{m}', 'first_number' => 1, 'last_number' => 1]), 'name_pattern', 'Un motif contient {m}, le numéro du membre, une fois au plus.');
        $this->assertRefused($this->addSeries(['name_pattern' => '', 'first_number' => 1, 'last_number' => 1]), 'name_pattern');

        // A name the device already has is refused as for any port,
        // whatever its case.
        $this->assertRefused(
            $this->addSeries(['name_pattern' => 'mgmt', 'first_number' => 3, 'last_number' => 3, 'device_member_id' => $this->second->id]),
            'name_pattern',
            "L'équipement a déjà un port nommé MGMT.",
        );

        $this->assertDatabaseCount('ports', 3);
    }

    public function test_a_series_holds_128_ports_at_most_and_names_of_32_characters_at_most(): void
    {
        $this->assertRefused(
            $this->addSeries(['first_number' => 1, 'last_number' => 129]),
            'last_number',
            'Une série contient 128 ports au plus.',
        );

        // 29 characters and a number of four digits.
        $this->assertRefused(
            $this->addSeries(['name_pattern' => str_repeat('a', 29).'{n}', 'first_number' => 999, 'last_number' => 1000]),
            'name_pattern',
            'Les noms que donne ce motif dépassent 32 caractères.',
        );

        $this->assertDatabaseCount('ports', 0);

        // At the limits, the series is added.
        $this->addSeries(['first_number' => 0, 'last_number' => 127])->assertSessionHasNoErrors();
        $this->addSeries(['name_pattern' => str_repeat('a', 29).'{n}', 'first_number' => 999, 'last_number' => 999])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ports', 129);
    }

    public function test_a_series_that_would_repeat_names_of_the_device_adds_nothing(): void
    {
        PortFactory::series($this->first, 12);
        // On another member, under another case: the names are those of
        // the device.
        Port::factory()->create(['device_member_id' => $this->second->id, 'name' => 'UPLINK1', 'position' => 1]);

        $this->assertRefused(
            $this->addSeries(['first_number' => 10, 'last_number' => 14]),
            'name_pattern',
            "L'équipement a déjà des ports nommés 1/0/10, 1/0/11, 1/0/12.",
        );
        $this->assertRefused(
            $this->addSeries(['first_number' => 12, 'last_number' => 20]),
            'name_pattern',
            "L'équipement a déjà un port nommé 1/0/12.",
        );
        $this->assertRefused(
            $this->addSeries(['name_pattern' => 'uplink{n}', 'first_number' => 1, 'last_number' => 2]),
            'name_pattern',
            "L'équipement a déjà un port nommé UPLINK1.",
        );

        // Ten names at most, then how many more.
        $this->assertRefused(
            $this->addSeries(['first_number' => 1, 'last_number' => 12]),
            'name_pattern',
            "L'équipement a déjà des ports nommés 1/0/1, 1/0/2, 1/0/3, 1/0/4, 1/0/5, 1/0/6, 1/0/7, 1/0/8, 1/0/9, 1/0/10 et 2 de plus.",
        );

        $this->assertDatabaseCount('ports', 13);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberPortsAdded));

        // The same names on another device are free.
        $other = Device::factory()->create(['site_id' => $this->office->id]);
        $member = DeviceMember::factory()->create(['device_id' => $other->id, 'position' => 1]);

        $this->addSeries(['device_member_id' => $member->id, 'first_number' => 1, 'last_number' => 12], $other)->assertSessionHasNoErrors();
    }

    public function test_a_series_that_would_go_past_the_last_place_of_the_order_of_its_member_adds_nothing(): void
    {
        [$port] = PortFactory::series($this->first, 1);

        // The last place a port can be given, from its window.
        $this->update($port, ['position' => Port::MAX_POSITION])->assertSessionHasNoErrors();

        $this->assertSame(9999, $port->refresh()->position);

        // Told on the member, whose order has no room left: neither a
        // server error, nor ports stored beyond the order.
        $this->assertRefused(
            $this->addSeries(['first_number' => 10, 'last_number' => 11]),
            'device_member_id',
            "Les ports de ce membre sont ordonnés jusqu'à 9999 au plus, et son dernier port a l'ordre 9999 : il ne reste pas assez de place pour 2 ports. Donnez d'abord un ordre plus petit à ses derniers ports.",
        );
        $this->assertSame(['device_member_id'], $this->errorFields());

        $this->assertRefused(
            $this->addSeries(['first_number' => 10, 'last_number' => 10]),
            'device_member_id',
            "Les ports de ce membre sont ordonnés jusqu'à 9999 au plus, et son dernier port a l'ordre 9999 : il ne reste pas assez de place pour 1 port. Donnez d'abord un ordre plus petit à ses derniers ports.",
        );

        $this->assertDatabaseCount('ports', 1);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberPortsAdded));

        // Another member of the device has its own order.
        $this->addSeries(['device_member_id' => $this->second->id, 'first_number' => 10, 'last_number' => 11])->assertSessionHasNoErrors();

        $this->assertSame([1, 2], $this->second->ports()->pluck('position')->all());

        // As many ports as there are places left, and not one more: the
        // series is added whole or not at all.
        $this->update($port, ['position' => Port::MAX_POSITION - 2])->assertSessionHasNoErrors();

        $this->assertRefused(
            $this->addSeries(['first_number' => 20, 'last_number' => 22]),
            'device_member_id',
            "Les ports de ce membre sont ordonnés jusqu'à 9999 au plus, et son dernier port a l'ordre 9997 : il ne reste pas assez de place pour 3 ports. Donnez d'abord un ordre plus petit à ses derniers ports.",
        );
        $this->assertSame(['1/0/1'], $this->names($this->first));

        $this->addSeries(['first_number' => 20, 'last_number' => 21])->assertSessionHasNoErrors();

        $this->assertSame([9997, 9998, 9999], $this->first->ports()->pluck('position')->all());
        $this->assertSame(0, Port::query()->where('position', '>', Port::MAX_POSITION)->count());

        // The last port is saved from its window as it is: its place is
        // one the window accepts.
        $last = $this->first->ports()->get()->last();

        $this->assertNotNull($last);
        $this->update($last, ['name' => $last->name, 'position' => $last->position, 'description' => 'Dernier port'])->assertSessionHasNoErrors();

        // A lower place for the last ports makes room again.
        foreach ($this->first->ports()->get() as $place => $one) {
            $this->update($one, ['name' => $one->name, 'position' => $place + 1])->assertSessionHasNoErrors();
        }

        $this->addSeries(['first_number' => 30, 'last_number' => 31])->assertSessionHasNoErrors();

        $this->assertSame([1, 2, 3, 4, 5], $this->first->ports()->pluck('position')->all());
    }

    public function test_the_ports_of_a_template_are_refused_as_well_when_the_order_of_the_member_has_no_room_for_them(): void
    {
        Port::factory()->create(['device_member_id' => $this->first->id, 'name' => 'eth0', 'position' => Port::MAX_POSITION - 1]);

        $pair = DeviceTemplate::factory()->switch(2, 0)->create();
        $single = DeviceTemplate::factory()->switch(1, 0)->create();

        // A member gets the ports of its template when it is added, before
        // it has any, and a template gives 2 560 ports at most: the pages
        // cannot reach this. The rule is enforced where the ports are
        // written, for whatever calls it.
        try {
            app(Ports::class)->generate($this->first, $pair->load('portGroups'));

            $this->fail('The ports were written past the last place of the order.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'device_template_id' => [
                    "Les ports de ce membre sont ordonnés jusqu'à 9999 au plus, et son dernier port a l'ordre 9998 : il ne reste pas assez de place pour 2 ports. Donnez d'abord un ordre plus petit à ses derniers ports.",
                ],
            ], $exception->errors());
        }

        $this->assertSame(['eth0'], $this->names($this->first));

        // The one port that fits is written, at the last place.
        $this->assertSame(['1/0/1'], app(Ports::class)->generate($this->first, $single->load('portGroups')));

        $this->assertSame([9998, 9999], $this->first->ports()->pluck('position')->all());
        $this->assertSame(['eth0', '1/0/1'], $this->names($this->first));
    }

    public function test_the_factory_of_ports_draws_a_place_a_port_can_have(): void
    {
        // The tests that leave the place of a port to its factory must not
        // depend on the number drawn: every one of them fits the column,
        // and the order a port can be given.
        foreach (range(1, 2000) as $draw) {
            $position = Port::factory()->raw(['device_member_id' => $this->first->id])['position'];

            $this->assertGreaterThanOrEqual(1, $position, "draw {$draw}");
            $this->assertLessThanOrEqual(Port::MAX_POSITION, $position, "draw {$draw}");
        }

        // Stored as it is drawn, whatever the draw.
        foreach (range(1, 25) as $draw) {
            $this->assertLessThanOrEqual(Port::MAX_POSITION, Port::factory()->create(['device_member_id' => $this->first->id])->position);
        }
    }

    // --- One port -------------------------------------------------------------

    public function test_an_editor_updates_every_field_of_a_port(): void
    {
        [$port] = PortFactory::series($this->first, 2);

        $this->update($port, [
            'name' => ' Gi1/0/1 ', 'position' => 40, 'connector' => 'sfp28', 'max_speed' => '25g', 'poe_type' => '802.3bt_type3',
            'poe_label' => 'PoE++', 'enabled' => false, 'description' => 'Hors service : câble coupé',
        ])
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Port modifié.');

        $port->refresh();

        $this->assertSame(
            ['Gi1/0/1', 40, 'sfp28', '25g', '802.3bt_type3', 'PoE++', false, 'Hors service : câble coupé', $this->first->id, $this->switch->id],
            [$port->name, $port->position, $port->connector->value, $port->max_speed->value, $port->poe_type->value, $port->poe_label, $port->enabled, $port->description, $port->device_member_id, $port->device_id],
        );

        // Its new place in the order of its member.
        $this->assertSame(['1/0/2', 'Gi1/0/1'], $this->names($this->first));

        // Emptied fields are stored empty.
        $this->update($port, ['name' => 'Gi1/0/1', 'position' => 40])->assertSessionHasNoErrors();

        $port->refresh();

        $this->assertSame([null, null, true], [$port->poe_label, $port->description, $port->enabled]);
    }

    public function test_the_shape_of_a_port_is_checked(): void
    {
        [$port] = PortFactory::series($this->first, 1);

        $this->assertRefused($this->update($port, ['name' => '']), 'name');
        $this->assertRefused($this->update($port, ['name' => str_repeat('a', 33)]), 'name');

        foreach ([0, 10000, 1.5, ''] as $position) {
            $this->assertRefused($this->update($port, ['position' => $position]), 'position');
        }

        $this->assertRefused($this->update($port, ['connector' => 'usb']), 'connector');
        $this->assertRefused($this->update($port, ['max_speed' => '']), 'max_speed');
        $this->assertRefused($this->update($port, ['poe_type' => 'poe']), 'poe_type');
        $this->assertRefused($this->update($port, ['poe_label' => str_repeat('a', 33)]), 'poe_label');
        $this->assertRefused($this->update($port, ['enabled' => 'peut-être']), 'enabled');
        $this->assertRefused($this->update($port, ['description' => str_repeat('a', 256)]), 'description');

        $this->assertSame('1/0/1', $port->refresh()->name);

        // A name is free text, at the limits too.
        $this->update($port, ['name' => 'Port façade n° 1 (baie A, haut)!', 'position' => 9999, 'description' => str_repeat('b', 255)])->assertSessionHasNoErrors();

        $this->assertSame('Port façade n° 1 (baie A, haut)!', $port->refresh()->name);
    }

    public function test_the_name_of_a_port_is_unique_within_its_device_whatever_its_case(): void
    {
        [$port] = PortFactory::series($this->first, 1);
        $uplink = Port::factory()->create(['device_member_id' => $this->second->id, 'name' => 'Uplink', 'position' => 1]);

        // On another member of the same device.
        $this->assertRefused($this->update($port, ['name' => 'UPLINK']), 'name', "L'équipement a déjà un port nommé Uplink.");

        // A port keeps its own name, and may change its case.
        $this->update($uplink, ['name' => 'UPLINK'])->assertSessionHasNoErrors();

        $this->assertSame('UPLINK', $uplink->refresh()->name);

        // Another device has its own names.
        $other = DeviceMember::factory()->create(['position' => 1]);
        $elsewhere = Port::factory()->create(['device_member_id' => $other->id, 'name' => 'eth0', 'position' => 1]);

        $this->update($elsewhere, ['name' => 'Uplink'])->assertSessionHasNoErrors();

        $this->assertSame(['1/0/1'], $this->names($this->first));
    }

    public function test_an_editor_deletes_a_port(): void
    {
        [$kept, $deleted] = PortFactory::series($this->first, 2);

        $this->actingAs($this->editor)
            ->delete(route('ports.destroy', $deleted))
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Port supprimé.');

        $this->assertSame([$kept->id], Port::query()->pluck('id')->all());
    }

    // --- Several ports at once ------------------------------------------------

    public function test_the_ports_ticked_are_disabled_and_enabled_together(): void
    {
        $ports = PortFactory::series($this->first, 4);
        $others = PortFactory::series($this->second, 2);

        // Ports of two members, in one action.
        $ticked = $this->ids([$ports[0], $ports[1], $others[0]]);

        $this->bulk('disable', $ticked)
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 ports désactivés.');

        $this->assertSame($ticked, Port::query()->where('enabled', false)->orderBy('id')->pluck('id')->all());

        // A port that is already in the state asked for is left alone, and
        // is not counted.
        $this->bulk('disable', $this->ids([$ports[1], $ports[2]]))->assertInertiaFlash('toast.message', '1 port désactivé.');
        $this->bulk('disable', $this->ids([$ports[1], $ports[2]]))->assertInertiaFlash('toast.message', 'Ces ports étaient déjà désactivés.');

        $this->bulk('enable', $this->ids([...$ports, ...$others]))->assertInertiaFlash('toast.message', '4 ports activés.');
        $this->bulk('enable', $this->ids($ports))->assertInertiaFlash('toast.message', 'Ces ports étaient déjà activés.');

        $this->assertSame(0, Port::query()->where('enabled', false)->count());

        // One entry of the activity log per port that changed: three, one,
        // then four.
        $this->assertSame(8, $this->countEntries(ActivityEvent::PortUpdated));
    }

    public function test_the_ports_ticked_are_deleted_together(): void
    {
        $ports = PortFactory::series($this->first, 4);
        $others = PortFactory::series($this->second, 2);

        $this->deleteMany($this->ids([$ports[1], $ports[2], $others[1]]))
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 ports supprimés.');

        $this->assertSame(['1/0/1', '1/0/4'], $this->names($this->first));
        $this->assertSame(['2/0/1'], $this->names($this->second));
        $this->assertSame(3, $this->countEntries(ActivityEvent::PortDeleted));

        $this->deleteMany([$ports[0]->id])->assertInertiaFlash('toast.message', '1 port supprimé.');
    }

    public function test_an_action_on_several_ports_is_done_for_all_of_them_or_for_none(): void
    {
        $ports = PortFactory::series($this->first, 3);
        $foreign = Port::factory()->create(['device_member_id' => DeviceMember::factory()->create(['position' => 1])->id, 'name' => 'eth0', 'position' => 1]);

        // A port of another device among them, or a port that is gone.
        foreach ([$foreign->id, 999999] as $intruder) {
            $ticked = [...$this->ids($ports), $intruder];

            $this->assertRefused($this->bulk('disable', $ticked), 'ports', "Certains de ces ports n'existent plus : rechargez la page.");
            $this->assertRefused($this->deleteMany($ticked), 'ports', "Certains de ces ports n'existent plus : rechargez la page.");
        }

        $this->assertDatabaseCount('ports', 4);
        $this->assertSame(0, Port::query()->where('enabled', false)->count());
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortUpdated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortDeleted));

        // The ports of a device are only reached through that device.
        $other = Device::factory()->create(['site_id' => $this->office->id]);

        $this->assertRefused($this->bulk('disable', $this->ids($ports), $other), 'ports');
        $this->assertRefused($this->deleteMany($this->ids($ports), $other), 'ports');

        $this->assertDatabaseCount('ports', 4);
    }

    public function test_the_shape_of_an_action_on_several_ports_is_checked(): void
    {
        [$port] = PortFactory::series($this->first, 1);

        $this->assertRefused($this->bulk('disable', []), 'ports');
        $this->assertRefused($this->bulk('explode', [$port->id]), 'action');
        $this->assertRefused($this->bulk('', [$port->id]), 'action');
        $this->assertRefused($this->actingAs($this->editor)->patch(route('ports.update-many', $this->switch), ['ports' => 'tous', 'action' => 'disable']), 'ports');
        $this->assertRefused($this->actingAs($this->editor)->patch(route('ports.update-many', $this->switch), ['ports' => ['un'], 'action' => 'disable']), 'ports.0');
        $this->assertRefused($this->bulk('disable', [$port->id, $port->id]), 'ports.0');

        $this->assertRefused($this->deleteMany([]), 'ports');
        $this->assertRefused($this->actingAs($this->editor)->delete(route('ports.destroy-many', $this->switch), ['ports' => ['un']]), 'ports.0');

        $this->assertTrue($port->refresh()->enabled);
    }

    // --- The page of the device -----------------------------------------------

    public function test_the_page_of_a_device_lists_its_ports_in_the_order_of_each_member(): void
    {
        $ports = PortFactory::series($this->first, 2);
        $ports[0]->update(['position' => 9, 'enabled' => false, 'description' => 'Hors service', 'poe_type' => '802.3at', 'poe_label' => 'PoE+']);
        $fibre = Port::factory()->create(['device_member_id' => $this->second->id, 'name' => '2/1/1', 'position' => 1, 'connector' => 'sfp_plus', 'max_speed' => '10g']);

        // The port of another device is not listed.
        Port::factory()->create();

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->switch))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ports', 3)
                // By place within their member: the page groups them by
                // member.
                ->where('ports.0', [
                    'id' => $fibre->id, 'member_id' => $this->second->id, 'name' => '2/1/1', 'position' => 1,
                    'connector' => 'sfp_plus', 'connector_label' => 'SFP+', 'max_speed' => '10g', 'max_speed_label' => '10 Gbit/s',
                    'poe_type' => 'none', 'poe_type_label' => 'Aucun', 'poe_label' => null, 'enabled' => true,
                    'mode' => null, 'summary' => null, 'untagged_vlan_id' => null, 'tagged_vlan_ids' => [],
                    'aggregate' => null, 'description' => null, 'link' => null, 'cord' => null, 'jack_cord' => null, 'deviations' => [],
                ])
                ->where('ports.1.name', '1/0/2')
                ->where('ports.2', [
                    'id' => $ports[0]->id, 'member_id' => $this->first->id, 'name' => '1/0/1', 'position' => 9,
                    'connector' => 'rj45', 'connector_label' => 'RJ45', 'max_speed' => '1g', 'max_speed_label' => '1 Gbit/s',
                    'poe_type' => '802.3at', 'poe_type_label' => '802.3at', 'poe_label' => 'PoE+', 'enabled' => false,
                    'mode' => null, 'summary' => null, 'untagged_vlan_id' => null, 'tagged_vlan_ids' => [],
                    'aggregate' => null, 'description' => 'Hors service', 'link' => null, 'cord' => null, 'jack_cord' => null, 'deviations' => [],
                ])
                ->where('members.0.ports_count', 2)
                ->where('members.1.ports_count', 1)
                ->has('portOptions.connectors', 7)
                ->has('portOptions.speeds', 8)
                ->has('portOptions.poeTypes', 5)
                ->where('portOptions.limits', ['name' => 32, 'position' => 9999, 'first_number' => 999, 'quantity' => 128]));

        // A device without a member has no port.
        $this->actingAs($this->reader())
            ->get(route('devices.show', Device::factory()->create(['site_id' => $this->office->id])))
            ->assertInertia(fn (Assert $page) => $page->where('ports', [])->where('members', []));
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_cannot_write_ports(): void
    {
        $reader = $this->reader();
        $ports = PortFactory::series($this->first, 2);

        $this->actingAs($reader)->post(route('ports.store', $this->switch), $this->series(['first_number' => 10, 'last_number' => 11]))->assertForbidden();
        $this->actingAs($reader)->put(route('ports.update', $ports[0]), $this->payload(['name' => 'Renommé']))->assertForbidden();
        $this->actingAs($reader)->delete(route('ports.destroy', $ports[0]))->assertForbidden();
        $this->actingAs($reader)->patch(route('ports.update-many', $this->switch), ['ports' => $this->ids($ports), 'action' => 'disable'])->assertForbidden();
        $this->actingAs($reader)->delete(route('ports.destroy-many', $this->switch), ['ports' => $this->ids($ports)])->assertForbidden();

        $this->assertSame(['1/0/1', '1/0/2'], $this->names($this->first));
        $this->assertSame(2, Port::query()->where('enabled', true)->count());
    }

    public function test_an_administrator_writes_ports_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        [$port] = PortFactory::series($this->first, 1);

        $this->post(route('ports.store', $this->switch), $this->series())->assertRedirect(route('login'));
        $this->put(route('ports.update', $port), $this->payload(['name' => 'Renommé']))->assertRedirect(route('login'));
        $this->delete(route('ports.destroy-many', $this->switch), ['ports' => [$port->id]])->assertRedirect(route('login'));

        $this->assertSame(['1/0/1'], $this->names($this->first));

        $admin = $this->admin();

        $this->actingAs($admin)->post(route('ports.store', $this->switch), $this->series(['first_number' => 2, 'last_number' => 3]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('ports.update', $port), $this->payload(['description' => 'Borne Wi-Fi']))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ports', 3);

        $this->actingAs($this->editor)->put(route('ports.update', 999999), $this->payload())->assertNotFound();
        $this->actingAs($this->editor)->post(route('ports.store', 999999), $this->series())->assertNotFound();
    }

    // --- Activity log ---------------------------------------------------------

    public function test_the_life_of_a_port_is_logged(): void
    {
        $this->addSeries(['first_number' => 1, 'last_number' => 3, 'poe_type' => '802.3at']);

        // One entry for the whole series, on the member: how many ports,
        // and their names. None per port.
        $added = $this->lastEntry(ActivityEvent::DeviceMemberPortsAdded);
        $port = Port::query()->where('name', '1/0/3')->sole();

        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberPortsAdded));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortCreated));
        $this->assertTrue($this->editor->is($added->causer));
        $this->assertTrue($this->first->is($added->subject));
        $this->assertSame('sw-coeur (Siège) › Membre 1', $added->getProperty('label'));
        $this->assertSame(
            ['attributes' => ['ports_count' => 3, 'ports' => '1/0/1, 1/0/2, 1/0/3']],
            array_filter($added->attribute_changes?->toArray() ?? []),
        );

        // A second series has its own entry, with its own ports.
        $this->addSeries(['name_pattern' => 'uplink{n}', 'first_number' => 1, 'last_number' => 1]);

        $this->assertSame(2, $this->countEntries(ActivityEvent::DeviceMemberPortsAdded));
        $this->assertSame(
            ['ports_count' => 1, 'ports' => 'uplink1'],
            ($this->lastEntry(ActivityEvent::DeviceMemberPortsAdded)->attribute_changes?->toArray() ?? [])['attributes'],
        );

        // Only what changed.
        $this->update($port, ['name' => '1/0/3', 'position' => 7, 'poe_type' => '802.3at', 'enabled' => false, 'description' => 'Hors service']);

        $this->assertSame(
            ['old' => ['order' => 3, 'enabled' => true, 'description' => null], 'attributes' => ['order' => 7, 'enabled' => false, 'description' => 'Hors service']],
            $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray(),
        );

        // An update that changes nothing, and a refused one, write nothing.
        $this->update($port, ['name' => '1/0/3', 'position' => 7, 'poe_type' => '802.3at', 'enabled' => false, 'description' => 'Hors service']);
        $this->update($port, ['name' => '']);

        $this->assertSame(1, $this->countEntries(ActivityEvent::PortUpdated));

        // Enabled again with others: its own entry says so.
        $this->bulk('enable', Port::query()->pluck('id')->all());

        $this->assertSame(2, $this->countEntries(ActivityEvent::PortUpdated));
        $this->assertSame(
            ['old' => ['enabled' => false], 'attributes' => ['enabled' => true]],
            $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray(),
        );

        $this->actingAs($this->editor)->delete(route('ports.destroy', $port));

        $deleted = $this->lastEntry(ActivityEvent::PortDeleted);

        $this->assertNull($deleted->subject);
        $this->assertSame('sw-coeur (Siège) › 1/0/3', $deleted->getProperty('label'));
        $this->assertSame('Hors service', ($deleted->attribute_changes?->toArray() ?? [])['old']['description']);
    }

    public function test_a_first_member_created_with_ports_is_one_entry_of_the_log(): void
    {
        $firewall = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege']);

        $this->addSeries(['device_member_id' => null, 'name_pattern' => 'port{n}', 'first_number' => 1, 'last_number' => 2], $firewall);

        $created = $this->lastEntry(ActivityEvent::DeviceMemberCreated);
        $values = ($created->attribute_changes?->toArray() ?? [])['attributes'];

        // The member and its first ports together: not one entry for the
        // member and another for the series.
        $this->assertSame('fw-siege (Siège) › Membre 1', $created->getProperty('label'));
        $this->assertSame([1, 'primary', 2, 'port1, port2'], [$values['position'], $values['role'], $values['ports_count'], $values['ports']]);
        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceMemberCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberPortsAdded));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortCreated));
    }

    public function test_the_log_page_reads_a_series_of_ports_in_french(): void
    {
        $this->addSeries(['first_number' => 1, 'last_number' => 3]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Ports ajoutés')
                ->where('entries.data.0.subject.title', 'sw-coeur (Siège) › Membre 1')
                ->where('entries.data.0.changes', [
                    ['field' => 'Nombre de ports', 'old' => null, 'new' => '3'],
                    ['field' => 'Ports', 'old' => null, 'new' => '1/0/1, 1/0/2, 1/0/3'],
                ]));
    }

    public function test_the_log_page_reads_a_change_of_a_port_in_french(): void
    {
        [$port] = PortFactory::series($this->first, 1);

        $this->update($port, ['name' => 'Gi1/0/1', 'position' => 5, 'connector' => 'sfp', 'max_speed' => '2_5g', 'poe_type' => '802.3bt_type4', 'enabled' => false]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Port modifié')
                ->where('entries.data.0.subject.title', 'sw-coeur (Siège) › Gi1/0/1')
                ->where('entries.data.0.changes', [
                    ['field' => 'Nom', 'old' => '1/0/1', 'new' => 'Gi1/0/1'],
                    ['field' => 'Ordre', 'old' => '1', 'new' => '5'],
                    ['field' => 'Connecteur', 'old' => 'RJ45', 'new' => 'SFP'],
                    ['field' => 'Débit', 'old' => '1 Gbit/s', 'new' => '2,5 Gbit/s'],
                    ['field' => 'PoE', 'old' => 'Aucun', 'new' => '802.3bt Type 4'],
                    ['field' => 'État', 'old' => 'Activé', 'new' => 'Désactivé'],
                ]));
    }
}
