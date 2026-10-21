<?php

// torsade/tests/Feature/Admin/ActivityLogPageTest.php

namespace Tests\Feature\Admin;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivityLogPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Alix Admin']);
    }

    private function record(ActivityEvent $event, ?User $subject = null, ?string $at = null): void
    {
        if ($at !== null) {
            $this->travelTo($at);
        }

        app(AuditLogger::class)->record($event, Actor::console(), $subject);

        $this->travelBack();
    }

    public function test_the_page_lists_the_entries_most_recent_first(): void
    {
        $user = User::factory()->create(['name' => 'Camille Martin', 'email' => 'camille@example.com']);

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => 'Camille Dubois',
            'email' => 'camille@example.com',
            'role' => 'editor',
        ]);

        $this->actingAs($this->admin)
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('activity/Index')
                ->has('entries.data', 2)
                ->where('entries.data.0.event', 'user.role_changed')
                ->where('entries.data.0.event_label', 'Rôle modifié')
                ->where('entries.data.0.author.type', 'user')
                ->where('entries.data.0.author.name', 'Alix Admin')
                ->where('entries.data.0.subject.title', 'Camille Dubois')
                ->where('entries.data.0.subject.detail', 'camille@example.com')
                ->where('entries.data.0.changes.0.field', 'Rôle')
                ->where('entries.data.0.changes.0.old', 'Lecteur')
                ->where('entries.data.0.changes.0.new', 'Éditeur')
                ->where('entries.data.1.event', 'user.updated')
                ->where('entries.data.1.changes.0.field', 'Nom')
                ->where('entries.data.1.changes.0.old', 'Camille Martin')
                ->where('entries.data.1.changes.0.new', 'Camille Dubois')
                // Every event that is still written.
                ->has('events', count(array_filter(ActivityEvent::cases(), fn (ActivityEvent $event): bool => $event->isWritten())))
                ->has('users', 2),
            );
    }

    public function test_console_and_anonymous_authors_are_identified(): void
    {
        $this->record(ActivityEvent::CommandExecuted);
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $this->actingAs($this->admin)
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event', 'auth.login_failed')
                ->where('entries.data.0.author.type', 'anonymous')
                ->where('entries.data.0.subject', null)
                ->where('entries.data.0.details.0', 'Compte inconnu')
                ->where('entries.data.1.event', 'console.command')
                ->where('entries.data.1.author.type', 'console'),
            );
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 30) as $i) {
            $this->record(ActivityEvent::CommandExecuted);
        }

        $this->actingAs($this->admin)
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 25)
                ->where('entries.total', 30)
                ->where('entries.last_page', 2),
            );

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 5));
    }

    public function test_entries_can_be_filtered_by_user_as_author_or_as_account_concerned(): void
    {
        $camille = User::factory()->create();
        $other = User::factory()->create();

        // Camille as the account concerned, then as the author.
        $this->actingAs($this->admin)->post(route('users.deactivate', $camille));
        $this->actingAs($this->admin)->post(route('users.activate', $camille));
        $this->actingAs($camille)->patch(route('profile.update'), ['name' => 'Camille Renommée']);

        $this->actingAs($this->admin)->post(route('users.deactivate', $other));

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['user' => $camille->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 3)
                ->where('filters.user', (string) $camille->id),
            );
    }

    public function test_entries_can_be_filtered_by_event_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)->post(route('users.deactivate', $user));
        $this->actingAs($this->admin)->post(route('users.activate', $user));

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['event' => 'user.deactivated']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event', 'user.deactivated')
                ->where('filters.event', 'user.deactivated'),
            );
    }

    public function test_entries_can_be_filtered_by_period_in_the_display_timezone(): void
    {
        // Europe/Paris is UTC+1 in January: 23:30 UTC on the 14th is already
        // the 15th for the user.
        $this->record(ActivityEvent::CommandExecuted, at: '2026-01-14 22:30:00');
        $this->record(ActivityEvent::CommandExecuted, at: '2026-01-14 23:30:00');
        $this->record(ActivityEvent::CommandExecuted, at: '2026-01-15 12:00:00');
        $this->record(ActivityEvent::CommandExecuted, at: '2026-01-15 23:30:00');

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['from' => '2026-01-15', 'to' => '2026-01-15']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.created_at', '2026-01-15T12:00:00+00:00')
                ->where('entries.data.1.created_at', '2026-01-14T23:30:00+00:00'),
            );

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['from' => '2026-01-15']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 3));

        $this->actingAs($this->admin)
            ->get(route('activity-log.index', ['to' => '2026-01-14']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_filters_are_validated_on_the_server(): void
    {
        $this->actingAs($this->admin)
            ->from(route('activity-log.index'))
            ->get(route('activity-log.index', [
                'user' => 999999,
                'event' => 'not.an.event',
                'from' => '15/01/2026',
                'to' => 'yesterday',
            ]))
            ->assertSessionHasErrors(['user', 'event', 'from', 'to']);

        $this->actingAs($this->admin)
            ->from(route('activity-log.index'))
            ->get(route('activity-log.index', ['from' => '2026-02-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_dates_are_sent_in_utc_with_the_display_timezone(): void
    {
        $this->record(ActivityEvent::CommandExecuted, at: '2026-07-01 10:00:00');

        $this->actingAs($this->admin)
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timezone', 'Europe/Paris')
                ->where('entries.data.0.created_at', '2026-07-01T10:00:00+00:00'),
            );

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertDatabaseHas('activity_log', ['created_at' => '2026-07-01 10:00:00']);
    }

    public function test_the_activity_log_is_read_only(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'activity-log'),
        ));

        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes[0]->methods());

        $this->record(ActivityEvent::CommandExecuted);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($this->admin)->call($method, '/activity-log')->assertStatus(405);
            $this->actingAs($this->admin)->call($method, '/activity-log/1')->assertNotFound();
        }

        $this->assertDatabaseCount('activity_log', 1);
    }
}
