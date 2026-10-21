<?php

// torsade/app/Http/Controllers/Network/HostnameDeviceController.php

namespace App\Http\Controllers\Network;

use App\Enums\DeviceType;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Network\HostnameDevicesRequest;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Site;
use App\Services\Network\HostnameDevices;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The devices of a site created from the host names of its addresses, in
 * one action. Both routes of this controller are behind the right to
 * create devices (see routes/network.php): editors and administrators.
 */
class HostnameDeviceController extends Controller
{
    /**
     * How many addresses a line shows before it only counts them.
     */
    private const int ADDRESSES_SHOWN = 3;

    /**
     * The host names of the site that belong to no device yet, each one
     * ready to become a device or to join the device that bears its name.
     */
    public function create(Site $site, HostnameDevices $hostnames): Response
    {
        return Inertia::render('sites/HostnameDevices', [
            'site' => ['id' => $site->id, 'name' => $site->name],
            'candidates' => array_values(array_map(
                fn (array $candidate): array => [
                    'hostname' => $candidate['hostname'],
                    'addresses_count' => count($candidate['addresses']),
                    'addresses' => array_map(
                        fn (IpAddress $address): string => $address->address,
                        array_slice($candidate['addresses'], 0, self::ADDRESSES_SHOWN),
                    ),
                    'type' => $candidate['type']->value,
                    // Planned on a planned site or for planned addresses,
                    // retired for retired addresses: proposed, and free.
                    'status' => $candidate['status']->value,
                    // The device of the site that already bears the name:
                    // the addresses are attached to it.
                    'device' => $candidate['device'] === null ? null : [
                        'id' => $candidate['device']->id,
                        'name' => $candidate['device']->name,
                        'type_label' => __($candidate['device']->type->label()),
                        'status_label' => __($candidate['device']->status->label()),
                    ],
                    // A host name may be longer than the name of a device.
                    'too_long' => $candidate['device'] === null && mb_strlen($candidate['hostname']) > Device::MAX_NAME_LENGTH,
                ],
                $hostnames->candidatesFor($site),
            )),
            'types' => DeviceType::options(),
            'statuses' => Status::options(),
            'maxLines' => HostnameDevices::MAX_LINES,
            'maxNameLength' => Device::MAX_NAME_LENGTH,
        ]);
    }

    public function store(HostnameDevicesRequest $request, Site $site, HostnameDevices $hostnames): RedirectResponse
    {
        $done = $hostnames->apply($site, $request->lines(), Actor::fromRequest($request));

        // "12 devices created, 3 attachments, 27 addresses attached."
        $summary = array_filter([
            $done['devices'] > 0 ? trans_choice(':count device created|:count devices created', $done['devices']) : null,
            $done['attachments'] > 0 ? trans_choice(':count attachment|:count attachments', $done['attachments']) : null,
            trans_choice(':count address attached|:count addresses attached', $done['addresses']),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => implode(', ', $summary).'.']);

        return to_route('sites.show', $site);
    }
}
