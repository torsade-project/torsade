<?php

// torsade/tests/Feature/DatabaseSeederTest.php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rebuilds the schema the way an installation does. It deliberately does not
 * use RefreshDatabase: the point is to run the real command.
 */
class DatabaseSeederTest extends TestCase
{
    public function test_migrate_fresh_with_seed_creates_no_user(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

        // The schema is fresh and complete: later tests need not rebuild it.
        RefreshDatabaseState::$migrated = true;

        $this->assertSame(0, DB::table('users')->count());
    }
}
