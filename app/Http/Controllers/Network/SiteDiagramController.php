<?php

// torsade/app/Http/Controllers/Network/SiteDiagramController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\SiteDiagramRequest;
use App\Models\DiagramPosition;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A diagram of a site, chosen in a list: the site is in the address of the
 * page, so that a diagram can be shared by its link. Without a site asked
 * for, the page goes to the first one by name.
 */
abstract class SiteDiagramController extends Controller
{
    /**
     * @param  string  $route  The name of the route of the page.
     * @param  string  $component  The page that draws the diagram.
     * @param  callable(Site): array{diagram: array<string, mixed>, layout: array<string, mixed>}  $drawn  The diagram of the site and where its nodes were placed by hand.
     * @param  array<string, mixed>  $props  What the page needs besides.
     */
    protected function page(SiteDiagramRequest $request, string $route, string $component, callable $drawn, array $props = []): Response|RedirectResponse
    {
        $sites = Site::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        $siteId = $request->site();

        // Without a site asked for, the first one by name, in the address.
        if ($siteId === null && $sites->isNotEmpty()) {
            return to_route($route, ['site' => $sites->firstOrFail()->id]);
        }

        $site = $siteId === null ? null : $sites->firstWhere('id', $siteId);

        abort_if($siteId !== null && $site === null, 404);

        return Inertia::render($component, [
            'sites' => array_values($sites->map(fn (Site $one): array => ['id' => $one->id, 'name' => $one->name])->all()),
            'site' => $site === null ? null : ['id' => $site->id, 'name' => $site->name, 'code' => $site->code],
            ...($site === null ? ['diagram' => null, 'layout' => null] : $drawn($site)),
            ...$props,
            'canEditLayout' => (bool) $request->user()?->can('update', DiagramPosition::class),
        ]);
    }
}
