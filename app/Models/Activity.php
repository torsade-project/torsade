<?php

// torsade/app/Models/Activity.php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * An entry of the activity log. Entries are written by App\Services\AuditLogger
 * and are read-only afterwards: the log must remain a reliable record.
 */
class Activity extends BaseActivity
{
    /**
     * Nothing is mass assignable: the logger sets each attribute explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Overrides the package's empty guard list.
     *
     * @var array<string>
     */
    public $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Activity log entries cannot be modified.');
        });

        static::deleting(function (): never {
            throw new LogicException('Activity log entries cannot be deleted.');
        });
    }
}
