<?php

// torsade/app/Services/Home/GettingStartedModule.php

namespace App\Services\Home;

use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\Link;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;

/**
 * The first steps of a documentation, in the order of the menu, for those
 * who may write, as long as a site, a VLAN, a subnet or a device is
 * missing. Each step is done once each kind of object it is about has
 * one; declaring the standard is optional, done as soon as a block, the
 * rule or an entry of the catalogue exists.
 *
 * A reader only sees it on an installation without any site, and is then
 * told that an editor or an administrator has to create the first one.
 */
final class GettingStartedModule extends HomeModule
{
    public function key(): string
    {
        return 'getting-started';
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-network');
    }

    public function data(User $user, array $settings): ?array
    {
        $hasSite = Site::query()->exists();

        if (! $user->can('edit-network')) {
            return $hasSite ? null : ['steps' => null];
        }

        $has = [
            'site' => $hasSite,
            'vlan' => Vlan::query()->exists(),
            'subnet' => Subnet::query()->exists(),
            'device' => Device::query()->exists(),
        ];

        if (! in_array(false, $has, true)) {
            return null;
        }

        $standard = AddressBlock::query()->exists() || AddressingRule::query()->exists() || CatalogVlan::query()->exists();

        return [
            'steps' => [
                $this->step('site', __('Create a site'), $has['site'], [[__('Sites'), route('sites.index')]]),
                $this->step('standard', __('Declare the standard'), $standard, [
                    [__('Address blocks'), route('address-blocks.index')],
                    [__('Addressing rule'), route('addressing-rule.show')],
                    [__('VLAN catalogue'), route('catalog-vlans.index')],
                ], optional: true),
                $this->step('vlans', __('Create the VLANs and their subnets'), $has['vlan'] && $has['subnet'], [[__('VLANs'), route('vlans.index')]]),
                // There is no list of addresses: they are documented in
                // their subnet.
                $this->step('addresses', __('Document the IP addresses'), IpAddress::query()->exists(), [[__('Subnets'), route('subnets.index')]]),
                $this->step('devices', __('Create the devices'), $has['device'], [[__('Devices'), route('devices.index')]]),
                $this->step('links', __('Link the devices'), Link::query()->exists(), [[__('Links'), route('links.index')]]),
                $this->step('jacks', __('Document the jacks'), Jack::query()->exists(), [[__('Jacks'), route('jacks.index')]]),
            ],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $links  Label, address.
     * @return array{key: string, title: string, done: bool, optional: bool, links: list<array{label: string, url: string}>}
     */
    private function step(string $key, string $title, bool $done, array $links, bool $optional = false): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'done' => $done,
            'optional' => $optional,
            'links' => array_map(fn (array $link): array => ['label' => $link[0], 'url' => $link[1]], $links),
        ];
    }
}
