<?php

// torsade/app/Services/AuditLogger.php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Support\Actor;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * The single entry point for writing to the activity log: who did
 * what, to which object, with the values before and after.
 */
final class AuditLogger
{
    /**
     * Any key containing one of these words is dropped before writing: a
     * password, a token or a secret never reaches the log.
     */
    private const SECRET_MARKERS = ['password', 'token', 'secret', 'recovery'];

    /**
     * @param  array<string, mixed>  $old  Values before the change.
     * @param  array<string, mixed>  $new  Values after the change.
     * @param  array<string, mixed>  $properties  Extra context for the entry.
     */
    public function record(
        ActivityEvent $event,
        Actor $actor,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $properties = [],
    ): void {
        $logger = activity()->event($event->value);

        // The package defaults to the signed-in user: the actor decides instead.
        $actor->user === null
            ? $logger->byAnonymous()
            : $logger->causedBy($actor->user);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($old !== [] || $new !== []) {
            $logger->withChanges([
                'old' => $this->normalise($old),
                'attributes' => $this->normalise($new),
            ]);
        }

        $logger->withProperties(array_filter([
            'source' => $actor->source,
            'ip' => $actor->ip,
            ...$this->normalise($properties),
        ], fn (mixed $value): bool => $value !== null));

        $logger->log($event->value);
    }

    /**
     * Remove secrets and turn enums into their scalar value.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if ($this->isSecret($key)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->normalise($value),
                $value instanceof BackedEnum => $value->value,
                default => $value,
            };
        }

        return $clean;
    }

    private function isSecret(string $key): bool
    {
        return array_any(
            self::SECRET_MARKERS,
            fn (string $marker): bool => str_contains(strtolower($key), $marker),
        );
    }
}
