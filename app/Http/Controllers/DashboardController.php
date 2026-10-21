<?php

// torsade/app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Home\HomeModules;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page, behind the "view-network" gate (see routes/web.php): the
 * modules the user may see, in their order, each with its own data. It
 * only reads: nothing is written to the activity log.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, HomeModules $modules): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'modules' => $modules->forUser($user),
        ]);
    }
}
