<?php

// torsade/app/Console/Commands/CreateAdminCommand.php

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Services\AuditLogger;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\text;

/**
 * Creates the first administrator. No default credentials exist: the
 * password is random, shown once and must be changed at the first login.
 */
class CreateAdminCommand extends Command
{
    use ProfileValidationRules;

    /**
     * @var string
     */
    protected $signature = 'torsade:create-admin
        {--name= : Full name of the administrator}
        {--email= : E-mail address the administrator will log in with}';

    /**
     * @var string
     */
    protected $description = 'Create the first administrator with a random password shown once';

    public function handle(UserAccounts $accounts, AuditLogger $audit): int
    {
        if ($accounts->hasActiveAdmin()) {
            $this->logExecution($audit, 'refused');

            $this->components->error(__('An active administrator already exists. Ask them to create your account, or use torsade:reset-password.'));

            return self::FAILURE;
        }

        $name = $this->option('name');
        $email = $this->option('email');

        if ($this->input->isInteractive()) {
            $name ??= text(label: __('Full name'), required: true);
            $email ??= text(label: __('Email address'), required: true);
        }

        $input = [
            'name' => is_string($name) ? trim($name) : null,
            'email' => is_string($email) ? strtolower(trim($email)) : null,
        ];

        $validator = Validator::make($input, [
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
        ]);

        if ($validator->fails()) {
            $this->logExecution($audit, 'invalid');

            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        [$user, $password] = $accounts->create(
            (string) $input['name'],
            (string) $input['email'],
            Role::Admin,
            Actor::console(),
        );

        $this->logExecution($audit, 'success');

        $this->components->info(__('Administrator :email created.', ['email' => $user->email]));
        $this->line('  '.__('Temporary password:').' '.$password);
        $this->newLine();
        $this->components->warn(__('This password will not be shown again. It must be changed at the first login.'));

        return self::SUCCESS;
    }

    private function logExecution(AuditLogger $audit, string $outcome): void
    {
        $audit->record(ActivityEvent::CommandExecuted, Actor::console(), properties: [
            'command' => $this->getName(),
            'outcome' => $outcome,
        ]);
    }
}
