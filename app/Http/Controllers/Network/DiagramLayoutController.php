<?php

// torsade/app/Http/Controllers/Network/DiagramLayoutController.php

namespace App\Http\Controllers\Network;

use App\Enums\DiagramView;
use App\Http\Controllers\Controller;
use App\Http\Requests\Network\SaveDiagramLayoutRequest;
use App\Http\Requests\Network\SaveLogicalLayoutRequest;
use App\Models\Site;
use App\Services\Network\DiagramLayouts;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The layout of a diagram of a site, placed by hand: saved all at once, or
 * given back to the automatic layout. The answer goes back to the diagram
 * of the site.
 */
class DiagramLayoutController extends Controller
{
    public function update(SaveDiagramLayoutRequest $request, Site $site, DiagramLayouts $layouts): RedirectResponse
    {
        $placed = $layouts->saveNodes($site, DiagramView::Physical, $request->positions(), $request->version(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Layout saved: :count element placed.|Layout saved: :count elements placed.', $placed)]);

        return to_route('diagrams.physical', ['site' => $site->id]);
    }

    public function destroy(Request $request, Site $site, DiagramLayouts $layouts): RedirectResponse
    {
        return $this->reset($request, $site, $layouts, DiagramView::Physical, 'diagrams.physical');
    }

    public function updateLogical(SaveLogicalLayoutRequest $request, Site $site, DiagramLayouts $layouts): RedirectResponse
    {
        $placed = $layouts->saveNodes($site, DiagramView::Logical, $request->positions(), $request->version(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Layout saved: :count element placed.|Layout saved: :count elements placed.', $placed)]);

        return to_route('diagrams.logical', ['site' => $site->id]);
    }

    public function destroyLogical(Request $request, Site $site, DiagramLayouts $layouts): RedirectResponse
    {
        return $this->reset($request, $site, $layouts, DiagramView::Logical, 'diagrams.logical');
    }

    private function reset(Request $request, Site $site, DiagramLayouts $layouts, DiagramView $view, string $route): RedirectResponse
    {
        $layouts->reset($site, $view, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The diagram is laid out automatically again.')]);

        return to_route($route, ['site' => $site->id]);
    }
}
