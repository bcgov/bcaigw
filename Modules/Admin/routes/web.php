<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\ApplicationReviewController;
use Modules\Admin\Http\Controllers\ModelControlController;
use Modules\Admin\Http\Controllers\UserRoleController;

// Admin area, restricted to Super Admin / Ministry Admin via the superadmin middleware.
Route::middleware(['auth', 'superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');

    Route::get('/users', [UserRoleController::class, 'index'])->name('users.index');
    Route::put('/users/{user}/role', [UserRoleController::class, 'update'])->name('users.role.update');

    Route::get('/applications', [ApplicationReviewController::class, 'index'])->name('applications.index');
    Route::get('/applications/{application}', [ApplicationReviewController::class, 'show'])->name('applications.show');
    Route::put('/applications/{application}/details', [ApplicationReviewController::class, 'updateDetails'])
        ->name('applications.details.update');
    Route::put('/applications/{application}/environments/{environment}', [ApplicationReviewController::class, 'updateEnvironment'])
        ->whereIn('environment', ['development', 'test', 'production'])
        ->name('applications.environments.update');
    Route::post('/applications/{application}/transition', [ApplicationReviewController::class, 'transition'])
        ->name('applications.transition');
    Route::post('/applications/{application}/grants', [ApplicationReviewController::class, 'grantModel'])
        ->name('applications.grants.store');
    Route::put('/applications/{application}/grants/{grant}/toggle', [ApplicationReviewController::class, 'toggleGrant'])
        ->name('applications.grants.toggle');

    Route::post('/applications/{application}/promotions/{promotion}/approve', [ApplicationReviewController::class, 'approvePromotion'])
        ->name('applications.promotions.approve');
    Route::post('/applications/{application}/promotions/{promotion}/reject', [ApplicationReviewController::class, 'rejectPromotion'])
        ->name('applications.promotions.reject');

    Route::get('/model-control', [ModelControlController::class, 'index'])->name('model-control.index');
    Route::post('/model-control/test', [ModelControlController::class, 'testTarget'])->name('model-control.test');
    Route::get('/model-control/bifrost/providers', [ModelControlController::class, 'bifrostProviders'])->name('model-control.bifrost.providers');
    Route::get('/model-control/bifrost/keys', [ModelControlController::class, 'bifrostProviderKeys'])->name('model-control.bifrost.keys');
    Route::get('/model-control/discover', [ModelControlController::class, 'discoverModels'])->name('model-control.discover');
    Route::post('/model-control/models', [ModelControlController::class, 'storeDiscoveredModel'])->name('model-control.models.store');

    Route::put('/model-control/providers/{provider}', [ModelControlController::class, 'updateProvider'])->name('model-control.providers.update');
    Route::put('/model-control/providers/{provider}/status', [ModelControlController::class, 'setProviderStatus'])->name('model-control.providers.status');

    Route::put('/model-control/targets/{target}', [ModelControlController::class, 'updateTarget'])->name('model-control.targets.update');
    Route::put('/model-control/targets/{target}/status', [ModelControlController::class, 'setTargetStatus'])->name('model-control.targets.status');

    Route::put('/model-control/aliases/{alias}', [ModelControlController::class, 'updateAlias'])->name('model-control.aliases.update');
    Route::put('/model-control/aliases/{alias}/status', [ModelControlController::class, 'setAliasStatus'])->name('model-control.aliases.status');
    Route::post('/model-control/aliases/{alias}/pricing', [ModelControlController::class, 'storeAliasPricing'])->name('model-control.aliases.pricing');

    Route::put('/model-control/grants/{grant:public_id}/toggle', [ModelControlController::class, 'toggleGrant'])
        ->name('model-control.grants.toggle');
});
