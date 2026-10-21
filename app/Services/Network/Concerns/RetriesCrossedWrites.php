<?php

// torsade/app/Services/Network/Concerns/RetriesCrossedWrites.php

namespace App\Services\Network\Concerns;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A write that locks rows can cross another one that locks the same rows in
 * another order: the database then gives up one of the two, a deadlock.
 * Nothing is wrong with what was asked, so the write is replayed, from the
 * start, a few times; if every attempt is given up, the user is told to try
 * again, on a field, rather than shown a server error.
 */
trait RetriesCrossedWrites
{
    use DetectsConcurrencyErrors;

    /**
     * How many times a write is attempted before the user is told.
     */
    private const int ATTEMPTS = 3;

    /**
     * Run a write in a transaction, replayed while the database gives it up
     * for another one.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @param  string  $field  Where the user is told when every attempt was given up.
     * @return TReturn
     *
     * @throws ValidationException
     */
    private function replayingCrossedWrites(Closure $write, string $field): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($write);
            } catch (Throwable $exception) {
                if (! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                if ($attempt >= self::ATTEMPTS) {
                    throw ValidationException::withMessages([$field => __('The save crossed another change: try again.')]);
                }
            }
        }
    }
}
