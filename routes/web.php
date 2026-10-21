<?php

// torsade/routes/web.php

use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SearchController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// No public landing page: guests go to the login form, users to the dashboard.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->middleware('can:view-network')->name('dashboard');

    // The search of the documentation: its page of results, whose address
    // can be shared, and what the window of every page asks for. They only
    // read; each user has a number of searches per minute, for both.
    Route::get('search', [SearchController::class, 'show'])
        ->middleware(['can:view-network', 'throttle:search'])
        ->name('search');
    Route::get('search/results', [SearchController::class, 'results'])
        ->middleware(['can:view-network', 'throttle:search'])
        ->name('search.results');
    // Their former addresses, so that the links already shared still work:
    // they lead to the new ones for good, the query with them, behind the
    // gate of the pages they lead to.
    Route::get('recherche', [SearchController::class, 'formerPage'])
        ->middleware('can:view-network')
        ->name('search.former');
    Route::get('recherche/resultats', [SearchController::class, 'formerResults'])
        ->middleware('can:view-network')
        ->name('search.results.former');

    // Where a user holding a temporary password is sent until they change it.
    Route::get('change-password', [PasswordChangeController::class, 'edit'])->name('password.change');

    // Where a user is sent while two-factor authentication is required on
    // their account and not configured yet.
    Route::get('two-factor/setup', [TwoFactorSetupController::class, 'show'])
        ->middleware(RequirePassword::class)
        ->name('two-factor.setup');
});

require __DIR__.'/settings.php';
require __DIR__.'/network.php';
require __DIR__.'/admin.php';
