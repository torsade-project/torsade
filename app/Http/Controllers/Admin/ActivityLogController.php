<?php

// torsade/app/Http/Controllers/Admin/ActivityLogController.php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Requests\Admin\ActivityLogIndexRequest;
use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The activity log page, behind the "view-activity-log" gate. It only reads:
 * there is no route to modify or delete an entry, so that the log remains a
 * reliable record of who changed what.
 */
class ActivityLogController extends Controller
{
    public function index(ActivityLogIndexRequest $request): Response
    {
        /** @var array{user?: int|string|null, event?: string|null, from?: string|null, to?: string|null} $filters */
        $filters = $request->validated();

        $timezone = (string) config('app.display_timezone');
        $userType = (new User)->getMorphClass();

        $entries = Activity::query()
            ->with(['causer', 'subject'])
            ->when($filters['user'] ?? null, fn (Builder $query, int|string $id) => $query->where(
                fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->where('causer_type', $userType)->where('causer_id', $id))
                    ->orWhere(fn (Builder $query) => $query->where('subject_type', $userType)->where('subject_id', $id)),
            ))
            ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->whereIn('event', $this->eventsNamed($event)))
            // The period is entered in the display timezone and compared in UTC.
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)?->utc(),
            ))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where(
                'created_at',
                '<',
                CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)?->addDay()->utc(),
            ))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Activity $activity): array => ActivityPresenter::entry($activity));

        return Inertia::render('activity/Index', [
            'entries' => $entries,
            'filters' => [
                'user' => isset($filters['user']) ? (string) $filters['user'] : '',
                'event' => $filters['event'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]),
            'events' => array_values(array_map(
                fn (ActivityEvent $event): array => ['value' => $event->value, 'label' => __($event->label())],
                // An event that is no longer written is found through the
                // one that replaced it.
                array_filter(ActivityEvent::cases(), fn (ActivityEvent $event): bool => $event->isWritten()),
            )),
        ]);
    }

    /**
     * The event asked for, and the one that was written for the same thing
     * before it: the ranges created include those created while they were
     * called DHCP ranges.
     *
     * @return list<string>
     */
    private function eventsNamed(string $event): array
    {
        $former = ActivityEvent::tryFrom($event)?->formerEvent();

        return $former === null ? [$event] : [$event, $former->value];
    }
}
