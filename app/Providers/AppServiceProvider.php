<?php

// torsade/app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Models\IpRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public const int SEARCHES_PER_MINUTE = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureActivityLogSubjects();

        // "php artisan about" is where an administrator reads the version.
        AboutCommand::add('Torsade', fn (): array => ['Version' => config('app.version')]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Same rule in every environment. No uncompromised() check: it calls
        // an external API and Torsade must work without Internet access.
        Password::defaults(fn (): Password => Password::min(12));
    }

    /**
     * The searches of a user, counted whether they were answered or given
     * up by the window before the answer came: typing at length never
     * comes near the limit, reading the documentation by a script does.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(self::SEARCHES_PER_MINUTE)
            ->by('search|'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * A range was called a DHCP range before it had a kind, and the entries
     * of the activity log written then name it so. They are left as they
     * were written: the old name is read as the range it has become.
     */
    protected function configureActivityLogSubjects(): void
    {
        Relation::morphMap([
            // First, so that new entries are written under the current name.
            IpRange::class => IpRange::class,
            'App\Models\DhcpRange' => IpRange::class,
        ]);
    }

    /**
     * The gates of the three fixed roles. A deactivated account is
     * granted nothing, whatever its role.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('manage-users', fn (User $user): bool => $user->is_active && $user->isAdmin());

        Gate::define('view-activity-log', fn (User $user): bool => $user->is_active && $user->isAdmin());

        Gate::define('edit-network', fn (User $user): bool => $user->is_active && $user->canEditNetwork());

        // Reading the documentation: every active account, whatever its role.
        Gate::define('view-network', fn (User $user): bool => $user->is_active);

        // Requiring or resetting the two-factor authentication of an account.
        // Never one's own: an administrator who has lost their device must not
        // be able to lift their own second factor from an open session.
        Gate::define(
            'manage-user-two-factor',
            fn (User $user, User $account): bool => $user->is_active && $user->isAdmin() && ! $user->is($account),
        );

        // Resetting the password of an account. Never one's own either: it
        // would close one's own session and hand over a temporary password;
        // one's own password is changed in one's settings.
        Gate::define(
            'reset-user-password',
            fn (User $user, User $account): bool => $user->is_active && $user->isAdmin() && ! $user->is($account),
        );
    }
}
