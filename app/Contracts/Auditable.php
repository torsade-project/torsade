<?php

// torsade/app/Contracts/Auditable.php

namespace App\Contracts;

/**
 * An object whose changes are written to the activity log with readable
 * values: names instead of identifiers, addresses as text.
 */
interface Auditable
{
    /**
     * How the activity log names the object. It is stored with each entry, so
     * that the log stays readable once the object is deleted.
     */
    public function auditLabel(): string;

    /**
     * The values compared before and after a change.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array;
}
