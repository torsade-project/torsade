<?php

// torsade/config/activitylog.php

use App\Models\Activity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    /*
    | The activity log is part of the product: it cannot be switched
    | off through the environment.
    */
    'enabled' => true,

    /*
    | Only used by the package's "activitylog:clean" command, which Torsade
    | does not schedule: there is no automatic purge.
    */
    'clean_after_days' => 365,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    /*
    | Torsade's own model: nothing mass assignable, entries are read-only.
    */
    'activity_model' => Activity::class,

    'default_except_attributes' => [],

    /*
    | Entries are written immediately, never buffered: an entry must exist
    | even if the request fails afterwards.
    */
    'buffer' => [
        'enabled' => false,
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],

];
