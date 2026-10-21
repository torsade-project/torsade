<?php

// torsade/app/Console/Commands/ResetTwoFactorCommand.php

namespace App\Console\Commands;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Console\Command;

/**
 * Removes the two-factor configuration of an account from the server. It is
 * the way out when the only administrator has lost both their device and
 * their recovery codes: nobody is left to do it from the interface.
 */
class ResetTwoFactorCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'torsade:reset-two-factor
        {email : E-mail address of the account}';

    /**
     * @var string
     */
    protected $description = 'Remove the two-factor configuration of an account and close its sessions';

    public function handle(UserAccounts $accounts, AuditLogger $audit): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // The typed address is not stored: only the failure is.
            $this->logExecution($audit, 'unknown_account');

            $this->components->error(__('No account uses this email address.'));

            return self::FAILURE;
        }

        $accounts->resetTwoFactor($user, Actor::console());

        $this->logExecution($audit, 'success', $user);

        $this->components->info(__('Two-factor authentication of :email reset. Its sessions are closed.', ['email' => $user->email]));

        if ($user->two_factor_required) {
            $this->components->warn(__('Two-factor authentication is required on this account: it will have to be set up again at the next login.'));
        }

        return self::SUCCESS;
    }

    private function logExecution(AuditLogger $audit, string $outcome, ?User $user = null): void
    {
        $audit->record(ActivityEvent::CommandExecuted, Actor::console(), $user, properties: [
            'command' => $this->getName(),
            'outcome' => $outcome,
        ]);
    }
}
