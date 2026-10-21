<?php

// torsade/app/Http/Controllers/Network/PhysicalDiagramController.php

namespace App\Http\Controllers\Network;

use App\Enums\DiagramView;
use App\Http\Requests\Network\PhysicalDiagramRequest;
use App\Models\Device;
use App\Models\Site;
use App\Services\Network\DiagramLayouts;
use App\Services\Network\PhysicalDiagram;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * The physical diagram of a site, worked out from what is declared: the
 * page lays it out and draws it, where devices were placed by hand if they
 * were.
 *
 * The number of queries does not depend on the number of devices nor of
 * links.
 */
class PhysicalDiagramController extends SiteDiagramController
{
    public function __invoke(PhysicalDiagramRequest $request, PhysicalDiagram $diagrams, DiagramLayouts $layouts): Response|RedirectResponse
    {
        return $this->page($request, 'diagrams.physical', 'diagrams/Physical', function (Site $site) use ($diagrams, $layouts): array {
            $diagram = $diagrams->of($site);

            return [
                'diagram' => $diagram,
                // Where the devices and the panels drawn were placed by
                // hand, if they were.
                'layout' => $layouts->ofNodes($site, DiagramView::Physical, DiagramLayouts::physicalNodes($diagram)),
            ];
        }, [
            // Where a device is created, from a site without any to draw.
            'canCreateDevice' => (bool) $request->user()?->can('create', Device::class),
        ]);
    }
}
