<?php

// torsade/app/Http/Controllers/SearchController.php

namespace App\Http\Controllers;

use App\Http\Requests\SearchPageRequest;
use App\Http\Requests\SearchRequest;
use App\Models\User;
use App\Services\Network\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The search of the documentation, behind the "view-network" gate and a
 * limit of searches per user (see routes/web.php). It only reads: nothing
 * is written to the activity log.
 */
class SearchController extends Controller
{
    /**
     * The former address of the page of results, for the links shared
     * before it moved: it leads there for good, with what it was asked.
     */
    public function formerPage(Request $request): RedirectResponse
    {
        return redirect()->route('search', $request->query(), 301);
    }

    /**
     * The former address of what the window asks for.
     */
    public function formerResults(Request $request): RedirectResponse
    {
        return redirect()->route('search.results', $request->query(), 301);
    }

    /**
     * The page of results: every group, a page of results each. Without a
     * search, the page only offers to type one.
     */
    public function show(SearchPageRequest $request, GlobalSearch $search): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('search/Index', [
            'results' => $request->hasSearch()
                ? $search->search($request->searchInput(), $user, GlobalSearch::PAGE_LIMIT)
                : null,
            'perPage' => GlobalSearch::PAGE_LIMIT,
        ]);
    }

    /**
     * What the search window shows as the text is typed, a few results per
     * group; or the next page of one group of the page of results.
     */
    public function results(SearchRequest $request, GlobalSearch $search): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $group = $request->group();

        if ($group === null) {
            return response()->json($search->search($request->searchInput(), $user, GlobalSearch::WINDOW_LIMIT));
        }

        $results = $search->search(
            $request->searchInput(),
            $user,
            GlobalSearch::PAGE_LIMIT,
            $group,
            ($request->page() - 1) * GlobalSearch::PAGE_LIMIT,
        );

        return response()->json(['group' => $results['groups'][0] ?? null]);
    }
}
