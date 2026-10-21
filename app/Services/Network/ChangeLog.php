<?php

// torsade/app/Services/Network/ChangeLog.php

namespace App\Services\Network;

use App\Contracts\Auditable;
use App\Enums\ActivityEvent;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the creations, updates and deletions of network objects to the
 * activity log, in the same shape for every kind of object.
 */
final class ChangeLog
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, scalar|null>  $with  What was created with the object and is told in its entry: the ports of a member.
     */
    public function created(ActivityEvent $event, Model&Auditable $subject, Actor $actor, array $with = []): void
    {
        $this->audit->record(
            $event,
            $actor,
            $subject,
            new: [...$subject->auditValues(), ...$with],
            properties: ['label' => $subject->auditLabel()],
        );
    }

    /**
     * Record what differs from the values taken before the change. Nothing
     * is written when nothing changed.
     *
     * @param  array<string, scalar|null>  $before
     * @param  list<string>  $except  The values whose change is told by an event of its own.
     */
    public function updated(ActivityEvent $event, Model&Auditable $subject, array $before, Actor $actor, array $except = []): void
    {
        $after = $subject->auditValues();

        $changed = array_filter(
            array_keys($after),
            fn (string $key): bool => ! in_array($key, $except, true) && ($before[$key] ?? null) !== $after[$key],
        );

        if ($changed === []) {
            return;
        }

        $this->audit->record(
            $event,
            $actor,
            $subject,
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
            ['label' => $subject->auditLabel()],
        );
    }

    /**
     * Record something that happened to an object and is neither its
     * creation, a change of its own values nor its deletion: an address
     * attached to a device. The entry holds the values the event names.
     *
     * @param  array<string, scalar|null>  $old
     * @param  array<string, scalar|null>  $new
     */
    public function noted(ActivityEvent $event, Model&Auditable $subject, array $old, array $new, Actor $actor): void
    {
        $this->audit->record($event, $actor, $subject, $old, $new, ['label' => $subject->auditLabel()]);
    }

    /**
     * To be called before the object is deleted, in the same transaction:
     * the entry keeps its last values.
     *
     * @param  array<string, scalar|null>  $with  What is deleted with the object and is told in its entry: the ports of a member, of a device.
     */
    public function deleted(ActivityEvent $event, Model&Auditable $subject, Actor $actor, array $with = []): void
    {
        $this->audit->record(
            $event,
            $actor,
            $subject,
            old: [...$subject->auditValues(), ...$with],
            properties: ['label' => $subject->auditLabel()],
        );
    }
}
