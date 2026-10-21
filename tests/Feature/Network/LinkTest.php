<?php

// torsade/tests/Feature/Network/LinkTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\LinkMedium;
use App\Enums\Status;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Link;
use App\Models\LinkEnd;
use App\Models\Port;
use App\Models\Site;
use App\Models\User;
use Database\Factories\LinkFactory;
use Database\Factories\PortFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Links between devices: two ports of two different devices, one link per
 * port at most, created one by one or as a series, changed, deleted, and
 * what they keep from being deleted.
 */
class LinkTest extends NetworkTestCase
{
    private User $editor;

    /** A stack of two members, four ports each. */
    private Device $stack;

    /** A switch alone, six ports. */
    private Device $core;

    /** A switch of another site, two ports. */
    private Device $workshop;

    /** @var array<string, Port> The ports of the stack, by name. */
    private array $near = [];

    /** @var array<string, Port> The ports of the core switch, by name. */
    private array $far = [];

    /** @var array<string, Port> The ports of the switch of the workshop, by name. */
    private array $away = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();

        $office = Site::factory()->create(['name' => 'Siège']);

        $this->stack = Device::factory()->create(['site_id' => $office->id, 'name' => 'sw-serveurs', 'type' => DeviceType::Switch]);
        $this->core = Device::factory()->create(['site_id' => $office->id, 'name' => 'sw-coeur-01', 'type' => DeviceType::Switch]);
        $this->workshop = Device::factory()->create(['site_id' => Site::factory()->create(['name' => 'Atelier'])->id, 'name' => 'sw-atelier', 'type' => DeviceType::Switch]);

        $this->near = $this->named([
            ...PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->stack->id, 'position' => 1]), 4),
            ...PortFactory::series(DeviceMember::factory()->secondary()->create(['device_id' => $this->stack->id, 'position' => 2]), 4),
        ]);
        $this->far = $this->named(PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->core->id, 'position' => 1]), 6));
        $this->away = $this->named(PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->workshop->id, 'position' => 1]), 2));
    }

    /**
     * @param  list<Port>  $ports
     * @return array<string, Port>
     */
    private function named(array $ports): array
    {
        $named = [];

        foreach ($ports as $port) {
            $named[$port->name] = $port;
        }

        return $named;
    }

    /**
     * What the window "Link…" sends.
     *
     * @param  list<Port>  $ports
     * @param  list<Port>  $opposite
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function link(array $ports, array $opposite, array $overrides = [], ?Device $device = null, ?Device $facing = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('links.store', $device ?? $this->stack), [
            'port_ids' => array_map(fn (Port $port): int => $port->id, $ports),
            'opposite_device_id' => ($facing ?? $this->core)->id,
            'opposite_port_ids' => array_map(fn (Port $port): int => $port->id, $opposite),
            'medium' => '',
            'status' => 'active',
            'description' => '',
            ...$overrides,
        ]);
    }

    /**
     * Every link, as its two ends: "sw-serveurs › 1/0/1 ↔ sw-coeur-01 ›
     * 1/0/1", in the order they were created.
     *
     * @return list<string>
     */
    private function links(): array
    {
        return array_values(Link::query()
            ->with('ends.port.device')
            ->orderBy('id')
            ->get()
            ->map(fn (Link $link): string => $link->ends
                ->map(fn (LinkEnd $end): string => $end->port->device->name.' › '.$end->port->name)
                ->implode(' ↔ '))
            ->all());
    }

    // --- Creation -------------------------------------------------------------

    public function test_an_editor_links_a_port_to_a_port_of_another_device(): void
    {
        $this->link([$this->near['1/0/1']], [$this->far['1/0/3']], ['medium' => 'fiber_multimode', 'status' => 'planned', 'description' => 'Baie A, tiroir optique 2'])
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 liaison créée.');

        $link = Link::query()->with('ends')->sole();

        $this->assertSame([LinkMedium::FiberMultimode, Status::Planned, 'Baie A, tiroir optique 2'], [$link->medium, $link->status, $link->description]);

        // The port the link was asked from is its end 1.
        $this->assertSame(
            [[1, $this->near['1/0/1']->id], [2, $this->far['1/0/3']->id]],
            $link->ends->map(fn (LinkEnd $end): array => [$end->end, $end->port_id])->all(),
        );
        $this->assertSame(['sw-serveurs › 1/0/1 ↔ sw-coeur-01 › 1/0/3'], $this->links());

        // Each port knows what it faces.
        $this->assertSame('1/0/3', $this->near['1/0/1']->fresh()?->linkedPort()?->name);
        $this->assertSame('1/0/1', $this->far['1/0/3']->fresh()?->linkedPort()?->name);
        $this->assertNull($this->near['1/0/2']->fresh()?->linkedPort());
    }

    public function test_only_the_ports_and_the_status_are_required_and_a_link_may_join_two_sites(): void
    {
        $this->actingAs($this->editor)
            ->post(route('links.store', $this->stack), [
                'port_ids' => [$this->near['2/0/4']->id],
                'opposite_device_id' => $this->workshop->id,
                'opposite_port_ids' => [$this->away['1/0/1']->id],
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $link = Link::query()->sole();

        $this->assertSame([null, Status::Active, null], [$link->medium, $link->status, $link->description]);
        $this->assertSame(['sw-serveurs › 2/0/4 ↔ sw-atelier › 1/0/1'], $this->links());

        $this->actingAs($this->editor)
            ->post(route('links.store', $this->stack), [])
            ->assertSessionHasErrors(['port_ids', 'opposite_device_id', 'opposite_port_ids', 'status']);

        $this->assertDatabaseCount('links', 1);
    }

    public function test_the_shape_of_a_link_is_checked(): void
    {
        $near = [$this->near['1/0/1']];
        $far = [$this->far['1/0/1']];

        $this->assertRefused($this->link($near, $far, ['port_ids' => 'tous']), 'port_ids');
        $this->assertRefused($this->link($near, $far, ['port_ids' => []]), 'port_ids');
        $this->assertRefused($this->link($near, $far, ['port_ids' => ['un']]), 'port_ids.0');
        $this->assertRefused($this->link($near, $far, ['port_ids' => [$near[0]->id, $near[0]->id]]), 'port_ids.0');
        $this->assertRefused($this->link($near, $far, ['opposite_device_id' => 'sw-coeur-01']), 'opposite_device_id');
        $this->assertRefused($this->link($near, $far, ['opposite_port_ids' => []]), 'opposite_port_ids');
        $this->assertRefused($this->link($near, $far, ['opposite_port_ids' => [$far[0]->id, $far[0]->id]]), 'opposite_port_ids.0');
        $this->assertRefused($this->link($near, $far, ['medium' => 'wifi']), 'medium');
        $this->assertRefused($this->link($near, $far, ['status' => 'broken']), 'status');
        $this->assertRefused($this->link($near, $far, ['status' => '']), 'status');
        $this->assertRefused($this->link($near, $far, ['description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('links', 0);
        $this->assertDatabaseCount('link_ends', 0);

        // At the limit, a link is accepted, with each medium.
        $this->link($near, $far, ['description' => str_repeat('b', 1000), 'medium' => 'other'])->assertSessionHasNoErrors();

        foreach (['copper', 'fiber_multimode', 'fiber_singlemode', 'direct_attach'] as $index => $medium) {
            $this->link([array_values($this->near)[$index + 1]], [$this->far['1/0/'.($index + 2)]], ['medium' => $medium])->assertSessionHasNoErrors();
        }

        $this->assertSame(['other', 'copper', 'fiber_multimode', 'fiber_singlemode', 'direct_attach'], Link::query()->orderBy('id')->pluck('medium')->map(fn (LinkMedium $medium): string => $medium->value)->all());
    }

    // --- Series ---------------------------------------------------------------

    public function test_ports_are_linked_as_a_series_in_the_order_of_each_device(): void
    {
        // Ticked in any order: each side is taken in the order of its
        // device, member by member.
        $this->link(
            [$this->near['2/0/1'], $this->near['1/0/4'], $this->near['1/0/2']],
            [$this->far['1/0/6'], $this->far['1/0/1'], $this->far['1/0/5']],
            ['medium' => 'direct_attach', 'description' => 'Vers le cœur'],
        )
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 liaisons créées.');

        $this->assertSame(
            ['sw-serveurs › 1/0/2 ↔ sw-coeur-01 › 1/0/1', 'sw-serveurs › 1/0/4 ↔ sw-coeur-01 › 1/0/5', 'sw-serveurs › 2/0/1 ↔ sw-coeur-01 › 1/0/6'],
            $this->links(),
        );

        // Every link of the series is given the same medium, status and
        // description.
        $this->assertSame(
            [['direct_attach', 'active', 'Vers le cœur']],
            Link::query()->get()->map(fn (Link $link): array => [$link->medium?->value, $link->status->value, $link->description])->unique()->values()->all(),
        );

        // One entry of the activity log per link.
        $this->assertSame(3, $this->countEntries(ActivityEvent::LinkCreated));
    }

    public function test_a_series_with_more_ports_on_one_side_is_refused_before_anything_is_written(): void
    {
        $this->assertRefused(
            $this->link([$this->near['1/0/1'], $this->near['1/0/2']], [$this->far['1/0/1']]),
            'opposite_port_ids',
            'Il faut autant de ports de chaque côté : 2 sur sw-serveurs, 1 sur sw-coeur-01.',
        );
        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->far['1/0/1'], $this->far['1/0/2'], $this->far['1/0/3']]),
            'opposite_port_ids',
            'Il faut autant de ports de chaque côté : 1 sur sw-serveurs, 3 sur sw-coeur-01.',
        );

        $this->assertDatabaseCount('links', 0);
        $this->assertDatabaseCount('link_ends', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::LinkCreated));
    }

    public function test_a_series_is_made_for_all_its_ports_or_for_none(): void
    {
        LinkFactory::between($this->far['1/0/2'], $this->away['1/0/1']);

        // The second port of the opposite side is already linked: the first
        // one is not linked either.
        $this->assertRefused(
            $this->link([$this->near['1/0/1'], $this->near['1/0/2'], $this->near['1/0/3']], [$this->far['1/0/1'], $this->far['1/0/2'], $this->far['1/0/3']]),
            'opposite_port_ids',
            'sw-coeur-01 › 1/0/2 est déjà relié à sw-atelier › 1/0/1.',
        );

        $this->assertDatabaseCount('links', 1);
        $this->assertDatabaseCount('link_ends', 2);
        $this->assertSame(0, $this->countEntries(ActivityEvent::LinkCreated));
    }

    // --- Refusals -------------------------------------------------------------

    public function test_a_port_already_linked_is_refused_whichever_end_it_is(): void
    {
        LinkFactory::between($this->near['1/0/1'], $this->far['1/0/1']);

        // The port the link is asked from is the end 1 of a link.
        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->far['1/0/2']]),
            'port_ids',
            '1/0/1 est déjà relié à sw-coeur-01 › 1/0/1.',
        );
        // It is the end 2 of a link: asked from the other device.
        $this->assertRefused(
            $this->link([$this->far['1/0/1']], [$this->away['1/0/1']], [], $this->core, $this->workshop),
            'port_ids',
            '1/0/1 est déjà relié à sw-serveurs › 1/0/1.',
        );
        // The opposite port is the end 2 of a link, then the end 1.
        $this->assertRefused(
            $this->link([$this->near['1/0/2']], [$this->far['1/0/1']]),
            'opposite_port_ids',
            'sw-coeur-01 › 1/0/1 est déjà relié à sw-serveurs › 1/0/1.',
        );
        $this->assertRefused(
            $this->link([$this->away['1/0/1']], [$this->near['1/0/1']], [], $this->workshop, $this->stack),
            'opposite_port_ids',
            'sw-serveurs › 1/0/1 est déjà relié à sw-coeur-01 › 1/0/1.',
        );

        $this->assertDatabaseCount('links', 1);

        // Several ports in the way are all named.
        LinkFactory::between($this->near['2/0/1'], $this->far['1/0/6']);

        $this->assertRefused(
            $this->link([$this->near['2/0/1'], $this->near['1/0/1']], [$this->far['1/0/2'], $this->far['1/0/3']]),
            'port_ids',
            '1/0/1 est déjà relié à sw-coeur-01 › 1/0/1, 2/0/1 est déjà relié à sw-coeur-01 › 1/0/6.',
        );

        $this->assertDatabaseCount('links', 2);
        $this->assertSame(0, $this->countEntries(ActivityEvent::LinkCreated));
    }

    public function test_two_ports_of_the_same_device_are_not_linked(): void
    {
        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->near['2/0/1']], [], $this->stack, $this->stack),
            'opposite_device_id',
            'Une liaison relie deux équipements différents : choisissez un autre équipement que sw-serveurs.',
        );

        // The opposite ports are those of the opposite device, and of no
        // other: a port of the device itself is not one of them.
        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->near['2/0/1']]),
            'opposite_port_ids',
            'Certains de ces ports ne sont plus des ports de sw-coeur-01 : choisissez-les de nouveau.',
        );
        $this->assertRefused($this->link([$this->near['1/0/1']], [$this->away['1/0/1']]), 'opposite_port_ids');

        $this->assertDatabaseCount('links', 0);
    }

    public function test_a_port_or_a_device_that_is_gone_is_refused(): void
    {
        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->far['1/0/1']], ['opposite_device_id' => 999999]),
            'opposite_device_id',
            "Cet équipement n'existe plus.",
        );

        // A port deleted since the window was opened, on one side then on
        // the other.
        $gone = $this->far['1/0/2']->id;

        $this->far['1/0/2']->delete();
        $this->near['1/0/2']->delete();

        $this->assertRefused(
            $this->link([$this->near['1/0/1'], $this->near['1/0/2']], [$this->far['1/0/1'], $this->far['1/0/3']]),
            'port_ids',
            "Certains de ces ports n'existent plus : rechargez la page.",
        );
        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->far['1/0/1']], ['opposite_port_ids' => [$gone]]),
            'opposite_port_ids',
            'Certains de ces ports ne sont plus des ports de sw-coeur-01 : choisissez-les de nouveau.',
        );

        // A port of another device is not a port of the device of the page.
        $this->assertRefused($this->link([$this->away['1/0/1']], [$this->far['1/0/1']]), 'port_ids');

        $this->get(route('devices.show', 999999))->assertNotFound();
        $this->actingAs($this->editor)->post(route('links.store', 999999), [])->assertNotFound();

        $this->assertDatabaseCount('links', 0);
    }

    public function test_the_database_is_the_last_net_and_what_it_refuses_is_told_as_a_message(): void
    {
        // Another request links the opposite port at the very moment this
        // one writes its own end: every check has passed, and only the
        // unique index of the database stands in the way.
        $raced = false;

        LinkEnd::creating(function (LinkEnd $end) use (&$raced): void {
            if ($raced || $end->end !== 2) {
                return;
            }

            $raced = true;

            $stamps = ['created_at' => now(), 'updated_at' => now()];
            $other = (int) DB::table('links')->insertGetId(['status' => 'active', ...$stamps]);

            DB::table('link_ends')->insert(['link_id' => $other, 'port_id' => $end->port_id, 'end' => 1, ...$stamps]);
        });

        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->far['1/0/1']]),
            'opposite_port_ids',
            "L'un de ces ports a été relié entre-temps : rechargez la page.",
        );

        $this->assertTrue($raced);

        // Nothing of the refused link is left, nor of what raced with it:
        // both were written in the transaction that was undone.
        $this->assertDatabaseCount('links', 0);
        $this->assertDatabaseCount('link_ends', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::LinkCreated));
    }

    /**
     * Have the database give up the writes of a link, as it gives up one of
     * two writes that cross: the first ones only, or every one.
     *
     * @return \Closure(): int How many times a write was given up.
     */
    private function crossing(int $times): \Closure
    {
        $given = 0;

        // The link is the first row a creation writes: given up there, an
        // attempt has written nothing.
        Link::creating(function () use (&$given, $times): void {
            if ($given >= $times) {
                return;
            }

            $given++;

            throw new QueryException('mariadb', 'insert into links', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        });

        return function () use (&$given): int {
            return $given;
        };
    }

    public function test_a_link_given_up_for_a_crossing_write_is_written_again(): void
    {
        $given = $this->crossing(2);

        $this->link([$this->near['1/0/1']], [$this->far['1/0/1']])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 liaison créée.');

        // Given up twice, written at the third attempt: once.
        $this->assertSame(2, $given());
        $this->assertSame(['sw-serveurs › 1/0/1 ↔ sw-coeur-01 › 1/0/1'], $this->links());
        $this->assertSame(1, $this->countEntries(ActivityEvent::LinkCreated));
    }

    public function test_a_link_given_up_at_every_attempt_is_a_message_never_a_server_error(): void
    {
        $given = $this->crossing(PHP_INT_MAX);

        $this->assertRefused(
            $this->link([$this->near['1/0/1']], [$this->far['1/0/1']]),
            'port_ids',
            "L'enregistrement a croisé une autre modification : réessayez.",
        );

        $this->assertSame(3, $given());
        $this->assertDatabaseCount('links', 0);
        $this->assertDatabaseCount('link_ends', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::LinkCreated));
    }

    // --- Update and deletion ----------------------------------------------------

    public function test_an_editor_changes_the_medium_the_status_and_the_description_of_a_link(): void
    {
        $link = LinkFactory::between($this->near['1/0/1'], $this->far['1/0/1'], ['medium' => LinkMedium::Copper, 'description' => 'Provisoire']);

        // The answer goes back to the page the window was opened on.
        $this->actingAs($this->editor)
            ->from(route('devices.show', $this->core))
            ->put(route('links.update', $link), ['medium' => 'fiber_singlemode', 'status' => 'retired', 'description' => ''])
            ->assertRedirect(route('devices.show', $this->core))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Liaison modifiée.');

        $link->refresh();

        $this->assertSame([LinkMedium::FiberSinglemode, Status::Retired, null], [$link->medium, $link->status, $link->description]);

        // Its ends are what they were: they are not part of what is sent,
        // and nothing sent changes them.
        $this->actingAs($this->editor)
            ->put(route('links.update', $link), ['medium' => '', 'status' => 'active', 'port_ids' => [$this->near['1/0/2']->id], 'opposite_port_ids' => [$this->far['1/0/2']->id]])
            ->assertSessionHasNoErrors();

        $this->assertNull($link->refresh()->medium);
        $this->assertSame(['sw-serveurs › 1/0/1 ↔ sw-coeur-01 › 1/0/1'], $this->links());

        $this->assertRefused($this->actingAs($this->editor)->put(route('links.update', $link), ['medium' => 'copper']), 'status');
        $this->assertRefused($this->actingAs($this->editor)->put(route('links.update', $link), ['status' => 'active', 'medium' => 'wifi']), 'medium');
        $this->assertRefused($this->actingAs($this->editor)->put(route('links.update', $link), ['status' => 'active', 'description' => str_repeat('a', 1001)]), 'description');

        $this->actingAs($this->editor)->put(route('links.update', 999999), ['status' => 'active'])->assertNotFound();
    }

    public function test_a_link_is_deleted_and_its_ports_stay(): void
    {
        $link = LinkFactory::between($this->near['1/0/1'], $this->far['1/0/1']);
        $kept = LinkFactory::between($this->near['1/0/2'], $this->far['1/0/2']);

        $this->actingAs($this->editor)
            ->from(route('devices.show', $this->stack))
            ->delete(route('links.destroy', $link))
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Liaison supprimée.');

        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->assertDatabaseMissing('link_ends', ['link_id' => $link->id]);
        $this->assertDatabaseHas('links', ['id' => $kept->id]);
        $this->assertDatabaseCount('link_ends', 2);

        // The two ports are there, as they were, and free again.
        $this->assertDatabaseCount('ports', 16);
        $this->assertNull($this->near['1/0/1']->fresh()?->linkedPort());

        $this->link([$this->near['1/0/1']], [$this->far['1/0/1']])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->delete(route('links.destroy', $link))->assertNotFound();
    }

    // --- What a link keeps from being deleted -----------------------------------

    public function test_a_linked_port_is_not_deleted_whichever_end_it_is(): void
    {
        $link = LinkFactory::between($this->near['1/0/1'], $this->far['1/0/3']);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy', $this->near['1/0/1'])),
            'port',
            "1/0/1 est relié à sw-coeur-01 › 1/0/3 : supprimez d'abord la liaison.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy', $this->far['1/0/3'])),
            'port',
            "1/0/3 est relié à sw-serveurs › 1/0/1 : supprimez d'abord la liaison.",
        );

        // Among the ports ticked, one linked port keeps all of them.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy-many', $this->stack), ['ports' => [$this->near['1/0/2']->id, $this->near['1/0/1']->id, $this->near['2/0/1']->id]]),
            'ports',
            "1/0/1 est relié à sw-coeur-01 › 1/0/3 : supprimez d'abord la liaison.",
        );

        $this->assertDatabaseCount('ports', 16);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortDeleted));

        // A port that is not linked is deleted as before, next to a linked
        // one.
        $this->actingAs($this->editor)->delete(route('ports.destroy', $this->near['1/0/2']))->assertSessionHasNoErrors();

        // Once the link is deleted, so are its ports.
        $this->actingAs($this->editor)->delete(route('links.destroy', $link))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('ports.destroy', $this->near['1/0/1']))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('ports.destroy-many', $this->core), ['ports' => [$this->far['1/0/3']->id, $this->far['1/0/4']->id]])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ports', 12);
    }

    public function test_a_member_and_a_device_with_a_linked_port_are_not_deleted(): void
    {
        $first = LinkFactory::between($this->near['1/0/2'], $this->far['1/0/1']);
        $second = LinkFactory::between($this->away['1/0/2'], $this->near['2/0/3']);

        $members = $this->stack->members()->get();

        // The member of the linked port, whichever end the port is.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-members.destroy', $members[0])),
            'member',
            "1/0/2 est relié à sw-coeur-01 › 1/0/1 : supprimez d'abord la liaison.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-members.destroy', $members[1])),
            'member',
            "2/0/3 est relié à sw-atelier › 1/0/2 : supprimez d'abord la liaison.",
        );

        // The device names its links, member by member.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $this->stack)),
            'links',
            "1/0/2 est relié à sw-coeur-01 › 1/0/1, 2/0/3 est relié à sw-atelier › 1/0/2 : supprimez d'abord ces liaisons.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $this->workshop)),
            'links',
            "1/0/2 est relié à sw-serveurs › 2/0/3 : supprimez d'abord la liaison.",
        );

        $this->assertDatabaseCount('devices', 3);
        $this->assertDatabaseCount('device_members', 4);
        $this->assertDatabaseCount('ports', 16);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceMemberDeleted) + $this->countEntries(ActivityEvent::DeviceDeleted));

        // Once its links are deleted, a member goes with its ports, and
        // the device with what is left of it.
        $this->actingAs($this->editor)->delete(route('links.destroy', $second))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('device-members.destroy', $members[1]))->assertSessionHasNoErrors();

        $this->assertRefused($this->actingAs($this->editor)->delete(route('devices.destroy', $this->stack)), 'links');

        $this->actingAs($this->editor)->delete(route('links.destroy', $first))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('devices.destroy', $this->stack))->assertRedirect(route('devices.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseCount('ports', 8);
        $this->assertDatabaseCount('links', 0);
    }

    public function test_a_refusal_names_ten_links_at_most(): void
    {
        $large = Device::factory()->create(['site_id' => $this->stack->site_id, 'name' => 'sw-etage', 'type' => DeviceType::Switch]);
        $ports = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $large->id, 'position' => 1]), 12);

        $facing = Device::factory()->create(['site_id' => $this->stack->site_id, 'name' => 'panneau', 'type' => DeviceType::Switch]);
        $others = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $facing->id, 'position' => 1]), 12);

        foreach ($ports as $index => $port) {
            LinkFactory::between($port, $others[$index]);
        }

        $named = implode(', ', array_map(fn (int $number): string => '1/0/'.$number.' est relié à panneau › 1/0/'.$number, range(1, 10)));

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $large)),
            'links',
            $named." et 2 de plus : supprimez d'abord ces liaisons.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-members.destroy', $large->members()->sole())),
            'member',
            $named." et 2 de plus : supprimez d'abord ces liaisons.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy-many', $large), ['ports' => array_map(fn (Port $port): int => $port->id, $ports)]),
            'ports',
            $named." et 2 de plus : supprimez d'abord ces liaisons.",
        );

        // And a series refused for ports already linked names as many.
        $this->assertRefused(
            $this->link($ports, array_values($this->far), [], $large),
            'opposite_port_ids',
            'Il faut autant de ports de chaque côté : 12 sur sw-etage, 6 sur sw-coeur-01.',
        );

        $spare = PortFactory::series(DeviceMember::factory()->create(['device_id' => $this->core->id, 'position' => 2]), 12);

        $this->assertRefused(
            $this->link($ports, $spare, [], $large),
            'port_ids',
            implode(', ', array_map(fn (int $number): string => '1/0/'.$number.' est déjà relié à panneau › 1/0/'.$number, range(1, 10))).' et 2 de plus.',
        );

        $this->assertDatabaseCount('links', 12);
    }

    // --- Rights and activity log ------------------------------------------------

    public function test_a_reader_cannot_write_a_link(): void
    {
        $link = LinkFactory::between($this->near['1/0/1'], $this->far['1/0/1']);
        $reader = $this->reader();

        $this->actingAs($reader)->post(route('links.store', $this->stack), [
            'port_ids' => [$this->near['1/0/2']->id],
            'opposite_device_id' => $this->core->id,
            'opposite_port_ids' => [$this->far['1/0/2']->id],
            'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($reader)->put(route('links.update', $link), ['status' => 'retired'])->assertForbidden();
        $this->actingAs($reader)->delete(route('links.destroy', $link))->assertForbidden();

        $this->assertDatabaseCount('links', 1);
        $this->assertSame(Status::Active, $link->refresh()->status);
        $this->assertSame(0, $this->countEntries(ActivityEvent::LinkCreated) + $this->countEntries(ActivityEvent::LinkUpdated) + $this->countEntries(ActivityEvent::LinkDeleted));
    }

    public function test_an_administrator_writes_links_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        $link = LinkFactory::between($this->near['1/0/1'], $this->far['1/0/1']);

        $this->post(route('links.store', $this->stack), [])->assertRedirect(route('login'));
        $this->put(route('links.update', $link), [])->assertRedirect(route('login'));
        $this->delete(route('links.destroy', $link))->assertRedirect(route('login'));

        $this->assertDatabaseCount('links', 1);

        $admin = $this->admin();

        $this->actingAs($admin)->post(route('links.store', $this->stack), [
            'port_ids' => [$this->near['1/0/2']->id],
            'opposite_device_id' => $this->core->id,
            'opposite_port_ids' => [$this->far['1/0/2']->id],
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('links.update', $link), ['status' => 'retired'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('links.destroy', $link))->assertSessionHasNoErrors();

        $this->assertSame(['sw-serveurs › 1/0/2 ↔ sw-coeur-01 › 1/0/2'], $this->links());
    }

    public function test_the_activity_log_tells_each_change_of_a_link_with_its_two_ends(): void
    {
        $this->link([$this->near['1/0/1']], [$this->far['1/0/3']], ['medium' => 'copper', 'description' => 'Cordon bleu'])->assertSessionHasNoErrors();

        $label = 'sw-serveurs (Siège) › 1/0/1 ↔ sw-coeur-01 (Siège) › 1/0/3';
        $link = Link::query()->sole();

        $created = $this->lastEntry(ActivityEvent::LinkCreated);

        $this->assertSame($label, $created->getProperty('label'));
        $this->assertTrue($created->subject_id === $link->id && $created->causer_id === $this->editor->id);
        $this->assertSame(
            ['end_1' => 'sw-serveurs (Siège) › 1/0/1', 'end_2' => 'sw-coeur-01 (Siège) › 1/0/3', 'medium' => 'copper', 'status' => 'active', 'description' => 'Cordon bleu'],
            ($created->attribute_changes?->toArray() ?? [])['attributes'],
        );

        // A change tells what changed, on the link named by its two ends.
        $this->actingAs($this->editor)->put(route('links.update', $link), ['medium' => 'copper', 'status' => 'retired', 'description' => 'Cordon bleu'])->assertSessionHasNoErrors();

        $updated = $this->lastEntry(ActivityEvent::LinkUpdated);

        $this->assertSame($label, $updated->getProperty('label'));
        $this->assertSame(['old' => ['status' => 'active'], 'attributes' => ['status' => 'retired']], $updated->attribute_changes?->toArray());

        // Saved as it is, a link writes nothing.
        $this->actingAs($this->editor)->put(route('links.update', $link), ['medium' => 'copper', 'status' => 'retired', 'description' => 'Cordon bleu'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::LinkUpdated));

        // A deletion keeps its last values, the two ends among them.
        $this->actingAs($this->editor)->delete(route('links.destroy', $link))->assertSessionHasNoErrors();

        $deleted = $this->lastEntry(ActivityEvent::LinkDeleted);

        $this->assertSame($label, $deleted->getProperty('label'));
        $this->assertSame(
            ['end_1' => 'sw-serveurs (Siège) › 1/0/1', 'end_2' => 'sw-coeur-01 (Siège) › 1/0/3', 'medium' => 'copper', 'status' => 'retired', 'description' => 'Cordon bleu'],
            ($deleted->attribute_changes?->toArray() ?? [])['old'],
        );

        // The log shows them in words, the latest first, the status agreed
        // with a link.
        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 3)
                ->where('entries.data.0.event_label', 'Liaison supprimée')
                ->where('entries.data.0.subject.title', $label)
                ->where('entries.data.1.event_label', 'Liaison modifiée')
                ->where('entries.data.1.changes', [['field' => 'Statut', 'old' => 'Active', 'new' => 'Retirée']])
                ->where('entries.data.2.event_label', 'Liaison créée')
                ->where('entries.data.2.subject.title', $label)
                ->where('entries.data.2.changes', [
                    ['field' => 'Bout 1', 'old' => null, 'new' => 'sw-serveurs (Siège) › 1/0/1'],
                    ['field' => 'Bout 2', 'old' => null, 'new' => 'sw-coeur-01 (Siège) › 1/0/3'],
                    ['field' => 'Support', 'old' => null, 'new' => 'Cuivre'],
                    ['field' => 'Statut', 'old' => null, 'new' => 'Active'],
                    ['field' => 'Description', 'old' => null, 'new' => 'Cordon bleu'],
                ]));
    }
}
