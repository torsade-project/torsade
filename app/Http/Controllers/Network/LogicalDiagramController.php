<?php

// torsade/app/Http/Controllers/Network/LogicalDiagramController.php

namespace App\Http\Controllers\Network;

use App\Enums\DiagramView;
use App\Http\Requests\Network\LogicalDiagramRequest;
use App\Models\Site;
use App\Services\Network\DiagramLayouts;
use App\Services\Network\LogicalDiagram;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * The logical diagram of a site, worked out from what is declared: its
 * subnets, the internet, and the devices plugged into them. The page lays
 * it out and draws it, where its nodes were placed by hand if they were.
 *
 * The number of queries does not depend on the number of subnets, of
 * devices nor of addresses.
 */
class LogicalDiagramController extends SiteDiagramController
{
    public function __invoke(LogicalDiagramRequest $request, LogicalDiagram $diagrams, DiagramLayouts $layouts): Response|RedirectResponse
    {
        return $this->page($request, 'diagrams.logical', 'diagrams/Logical', function (Site $site) use ($diagrams, $layouts): array {
            $diagram = $diagrams->of($site);

            return [
                'diagram' => $diagram,
                // Where the nodes drawn were placed by hand, if they were.
                'layout' => $layouts->ofNodes($site, DiagramView::Logical, LogicalDiagram::nodes($diagram)),
            ];
        });
    }
}
