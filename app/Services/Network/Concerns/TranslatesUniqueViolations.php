<?php

// torsade/app/Services/Network/Concerns/TranslatesUniqueViolations.php

namespace App\Services\Network\Concerns;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Uniqueness is guaranteed by the database, which is the only place where
 * two simultaneous requests cannot both pass. A refused write is turned into
 * a validation message on the field concerned instead of a server error.
 */
trait TranslatesUniqueViolations
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @param  array<string, array{0: string, 1: string}>  $indexes  For each unique index, the field and its message.
     * @return TReturn
     *
     * @throws ValidationException
     */
    private function guardingUniqueness(Closure $write, array $indexes): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $exception) {
            foreach ($indexes as $index => [$field, $message]) {
                if ($exception->index === $index || str_contains($exception->getMessage(), $index)) {
                    throw ValidationException::withMessages([$field => $message]);
                }
            }

            throw $exception;
        }
    }
}
