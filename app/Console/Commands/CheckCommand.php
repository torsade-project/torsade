<?php

// torsade/app/Console/Commands/CheckCommand.php

namespace App\Console\Commands;

use App\Services\ProductionChecks;
use Illuminate\Console\Command;

/**
 * Checks a production installation, one line per check, passed or failed,
 * with what to correct. It changes nothing, never prints a secret value,
 * runs every check, and ends with a code other than zero when one fails,
 * so that a script can rely on it.
 */
class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'torsade:check';

    /**
     * @var string
     */
    protected $description = 'Check the configuration of a production installation';

    public function handle(ProductionChecks $checks): int
    {
        $failed = 0;

        foreach ($checks->all() as $check) {
            $this->components->twoColumnDetail(
                $check->name,
                $check->passed ? '<fg=green;options=bold>'.__('passed').'</>' : '<fg=red;options=bold>'.__('failed').'</>',
            );

            if (! $check->passed) {
                $failed++;
                $this->components->bulletList([$check->correction]);
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error(trans_choice(':count check failed.|:count checks failed.', $failed));

            return self::FAILURE;
        }

        $this->components->info(__('Every check passed.'));

        return self::SUCCESS;
    }
}
