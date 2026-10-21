<?php

// torsade/app/Console/Commands/ResetPasswordCommand.php

namespace App\Console\Commands;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Console\Command;

/**
 * Resets the password of an account from the server. There is no reset by
 * e-mail: an internal server often has no mail relay. The temporary password
 * is shown once, must be changed at the next login and every session of the
 * account is closed.
 */
class ResetPasswordCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'torsade:reset-password
        {email : E-mail address of the account}';

    /**
     * @var string
     */
    protected $description = 'Give an account a temporary password shown once';

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

        $password = $accounts->resetPassword($user, Actor::console());

        $this->logExecution($audit, 'success', $user);

        $this->components->info(__('Password of :email reset.', ['email' => $user->email]));
        $this->line('  '.__('Temporary password:').' '.$password);
        $this->newLine();
        $this->components->warn(__('This password will not be shown again. It must be changed at the first login.'));

        if (! $user->is_active) {
            $this->components->warn(__('This account is deactivated: it cannot log in until an administrator reactivates it.'));
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
