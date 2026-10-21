<?php

// torsade/app/Services/Home/SitesModule.php

namespace App\Services\Home;

use App\Enums\Status;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The sites that are not retired, by name, each with how many VLANs,
 * subnets and devices it holds that are not retired, and the way to its
 * page and to its two diagrams. Past the limit, the way to every site.
 */
final class SitesModule extends HomeModule
{
    public function key(): string
    {
        return 'sites';
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-network');
    }

    public function settings(): array
    {
        return ['limit' => ['min' => 1, 'max' => 50, 'default' => 12]];
    }

    public function data(User $user, array $settings): array
    {
        $current = fn (Builder $query) => $query->where('status', '!=', Status::Retired->value);

        $sites = Site::query()
            ->where('status', '!=', Status::Retired->value)
            ->withCount(['vlans' => $current, 'subnets' => $current, 'devices' => $current])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($settings['limit'])
            ->get();

        return [
            'sites' => array_values($sites->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
                'status' => $site->status->value,
                'status_label' => __($site->status->label()),
                'vlans_count' => (int) $site->getAttribute('vlans_count'),
                'subnets_count' => (int) $site->getAttribute('subnets_count'),
                'devices_count' => (int) $site->getAttribute('devices_count'),
                'url' => route('sites.show', $site),
                'physical_diagram_url' => route('diagrams.physical', ['site' => $site->id]),
                'logical_diagram_url' => route('diagrams.logical', ['site' => $site->id]),
            ])->all()),
            'total' => Site::query()->where('status', '!=', Status::Retired->value)->count(),
            'url' => route('sites.index'),
        ];
    }
}
