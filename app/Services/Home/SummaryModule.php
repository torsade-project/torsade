<?php

// torsade/app/Services/Home/SummaryModule.php

namespace App\Services\Home;

use App\Enums\Status;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\Link;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Model;

/**
 * How much is documented: the sites, VLANs, subnets, IP addresses,
 * devices, links and jacks that are not retired, each with how many of
 * them are planned, and a link to its list. The addresses have no list:
 * they are read in their subnet.
 */
final class SummaryModule extends HomeModule
{
    public function key(): string
    {
        return 'summary';
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-network');
    }

    public function data(User $user, array $settings): array
    {
        return [
            'counters' => [
                $this->counter('sites', Site::class, route('sites.index')),
                $this->counter('vlans', Vlan::class, route('vlans.index')),
                $this->counter('subnets', Subnet::class, route('subnets.index')),
                $this->counter('addresses', IpAddress::class, null),
                $this->counter('devices', Device::class, route('devices.index')),
                $this->counter('links', Link::class, route('links.index')),
                $this->counter('jacks', Jack::class, route('jacks.index')),
            ],
        ];
    }

    /**
     * The objects of a kind that are not retired, and how many of them are
     * planned.
     *
     * @param  class-string<Model>  $model
     * @return array{key: string, count: int, label: string, planned: int, planned_label: string|null, url: string|null}
     */
    private function counter(string $key, string $model, ?string $url): array
    {
        $count = $model::query()->where('status', '!=', Status::Retired->value)->count();
        $planned = $model::query()->where('status', Status::Planned->value)->count();

        return [
            'key' => $key,
            'count' => $count,
            'label' => $this->label($key, $count),
            'planned' => $planned,
            'planned_label' => $planned === 0 ? null : $this->plannedLabel($key, $planned),
            'url' => $url,
        ];
    }

    /**
     * The name of the objects, in agreement with their number. "VLAN" is
     * the same in French whatever the number.
     */
    private function label(string $key, int $count): string
    {
        return match ($key) {
            'sites' => trans_choice('Site|Sites', $count),
            'vlans' => (string) __('VLANs'),
            'subnets' => trans_choice('Subnet|Subnets', $count),
            'addresses' => trans_choice('IP address|IP addresses', $count),
            'devices' => trans_choice('Device|Devices', $count),
            'links' => trans_choice('Link|Links', $count),
            default => trans_choice('Jack|Jacks', $count),
        };
    }

    /**
     * How many are planned, in agreement with the name of the objects: an
     * address, a link and a jack are feminine in French.
     */
    private function plannedLabel(string $key, int $planned): string
    {
        return in_array($key, ['addresses', 'links', 'jacks'], true)
            ? trans_choice('of which :count planned (feminine)|of which :count planned (feminine)', $planned)
            : trans_choice('of which :count planned|of which :count planned', $planned);
    }
}
