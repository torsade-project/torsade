<?php

// torsade/routes/admin.php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Admin\UserTwoFactorController;
use Illuminate\Support\Facades\Route;

// User administration: administrators only. There is no route to delete an
// account: accounts are deactivated, so that the activity log keeps its authors.
Route::middleware(['auth', 'can:manage-users'])->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');

    Route::post('users/{user}/deactivate', [UserStatusController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/activate', [UserStatusController::class, 'activate'])->name('users.activate');

    // The gate refuses the administrator's own account, whose password is
    // changed from the settings.
    Route::post('users/{user}/reset-password', [UserPasswordController::class, 'store'])
        ->middleware(['can:reset-user-password,user', 'throttle:10,1'])
        ->name('users.reset-password');

    // Two-factor authentication of another account. The gate refuses the
    // administrator's own account, which is managed from the settings.
    Route::middleware('can:manage-user-two-factor,user')->group(function () {
        Route::post('users/{user}/two-factor/enforce', [UserTwoFactorController::class, 'enforce'])
            ->name('users.two-factor.enforce');
        Route::post('users/{user}/two-factor/release', [UserTwoFactorController::class, 'release'])
            ->name('users.two-factor.release');
        Route::post('users/{user}/two-factor/reset', [UserTwoFactorController::class, 'reset'])
            ->name('users.two-factor.reset');
    });
});

// Activity log: administrators only, and read-only by construction, this is
// the only route.
Route::middleware(['auth', 'can:view-activity-log'])->group(function () {
    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
});
