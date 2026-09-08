<?php

use App\Http\Controllers\Admin\ApplicationReviewController;
use App\Http\Controllers\Admin\ModelControlPlaneController;
use App\Http\Controllers\Admin\TelemetryDeletionController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Applications\ApplicationController;
use App\Http\Controllers\Applications\ApplicationLifecycleController;
use App\Http\Controllers\Applications\ApplicationMemberController;
use App\Http\Controllers\Applications\MachineCredentialController;
use App\Http\Controllers\Auth\PortalAuthController;
use App\Http\Controllers\CallHistoryController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
})->name('home');

Route::get('/metrics', MetricsController::class)->name('metrics');

/*
| IDIR sign-in.
|
| The /applogin path mirrors FSG's IDIR entry point. Existing BCAIGW login and
| callback paths remain available, with one implementation and one set of
| security checks regardless of which registered callback URL is used.
|
| Whichever callback path is registered in CSS must be the value of
| KEYCLOAK_REDIRECT_URI - the provider compares it byte for byte.
*/
Route::get('/auth/idir', [PortalAuthController::class, 'redirect'])->name('auth.idir.redirect');
Route::get('/auth/idir/callback', [PortalAuthController::class, 'callback'])->name('auth.idir.callback');
Route::get('/applogin', [PortalAuthController::class, 'redirect'])->name('app-login');
Route::get('/idir-login', [PortalAuthController::class, 'redirect'])->name('idir-login');
Route::get('/auth/keycloak/callback', [PortalAuthController::class, 'callback'])
    ->name('auth.keycloak.callback');

Route::post('/portal/logout', [PortalAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('portal.logout');

Route::middleware(['auth', 'audit.authorization:access-portal'])
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
        Route::post('/applications', [ApplicationController::class, 'store'])->name('applications.store');
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])
            ->middleware('audit.authorization:view')
            ->name('applications.show');
        Route::get('/applications/{application}/edit', [ApplicationController::class, 'edit'])
            ->middleware('audit.authorization:update')
            ->name('applications.edit');
        Route::put('/applications/{application}', [ApplicationController::class, 'update'])
            ->middleware('audit.authorization:update')
            ->name('applications.update');
        Route::post('/applications/{application}/submit', [ApplicationLifecycleController::class, 'submit'])
            ->middleware('audit.authorization:submit')
            ->name('applications.submit');
        Route::post('/applications/{application}/members', [ApplicationMemberController::class, 'store'])
            ->middleware('audit.authorization:manageMembers')
            ->name('applications.members.store');
        Route::delete('/applications/{application}/members/{user}', [ApplicationMemberController::class, 'destroy'])
            ->middleware('audit.authorization:manageMembers')
            ->name('applications.members.destroy');
        Route::post('/applications/{application}/credentials', [MachineCredentialController::class, 'store'])
            ->middleware('audit.authorization:manageCredentials')
            ->name('applications.credentials.store');
        Route::post(
            '/applications/{application}/credentials/{machineCredential}/rotate',
            [MachineCredentialController::class, 'rotate'],
        )
            ->middleware('audit.authorization:manageCredentials')
            ->name('applications.credentials.rotate');
        Route::delete(
            '/applications/{application}/credentials/{machineCredential}',
            [MachineCredentialController::class, 'destroy'],
        )
            ->middleware('audit.authorization:manageCredentials')
            ->name('applications.credentials.destroy');

        Route::get('/calls', [CallHistoryController::class, 'index'])->name('calls.index');
        Route::get('/calls/export', [CallHistoryController::class, 'export'])->name('calls.export');
        Route::get('/calls/{callAttempt}', [CallHistoryController::class, 'show'])->name('calls.show');
        Route::post('/calls/{callAttempt}/reveal', [CallHistoryController::class, 'reveal'])->name('calls.reveal');

        Route::middleware('audit.authorization:access-admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', [UserRoleController::class, 'index'])->name('index');
            Route::patch('/users/{user}/role', [UserRoleController::class, 'update'])->name('users.role');
            Route::get('/applications', [ApplicationReviewController::class, 'index'])
                ->name('applications.index');
            Route::get('/applications/{application}', [ApplicationReviewController::class, 'show'])
                ->name('applications.show');
            Route::post('/applications/{application}/transitions', [ApplicationReviewController::class, 'transition'])
                ->name('applications.transitions');
            Route::patch('/applications/{application}/configuration', [ApplicationReviewController::class, 'configure'])
                ->name('applications.configuration');
            Route::post('/applications/{application}/quota-adjustments', [ApplicationReviewController::class, 'adjustQuota'])
                ->name('applications.quota-adjustments');
            Route::get('/model-control', [ModelControlPlaneController::class, 'index'])
                ->name('model-control.index');
            Route::post('/model-control/providers', [ModelControlPlaneController::class, 'storeProvider'])
                ->name('model-control.providers.store');
            Route::patch('/model-control/providers/{providerAccount}', [ModelControlPlaneController::class, 'updateProvider'])
                ->name('model-control.providers.update');
            Route::post('/model-control/targets', [ModelControlPlaneController::class, 'storeTarget'])
                ->name('model-control.targets.store');
            Route::patch('/model-control/targets/{upstreamTarget}', [ModelControlPlaneController::class, 'updateTarget'])
                ->name('model-control.targets.update');
            Route::post('/model-control/targets/{upstreamTarget}/health', [ModelControlPlaneController::class, 'checkTarget'])
                ->name('model-control.targets.health');
            Route::post('/model-control/aliases', [ModelControlPlaneController::class, 'storeAlias'])
                ->name('model-control.aliases.store');
            Route::patch('/model-control/aliases/{publicModelAlias}', [ModelControlPlaneController::class, 'updateAlias'])
                ->name('model-control.aliases.update');
            Route::post('/model-control/aliases/{publicModelAlias}/pricing', [ModelControlPlaneController::class, 'storePricing'])
                ->name('model-control.pricing.store');
            Route::post('/model-control/grants', [ModelControlPlaneController::class, 'upsertGrant'])
                ->name('model-control.grants.upsert');
            Route::get('/telemetry/deletions', [TelemetryDeletionController::class, 'index'])
                ->name('telemetry.deletions.index');
            Route::post('/telemetry/deletions', [TelemetryDeletionController::class, 'store'])
                ->name('telemetry.deletions.store');
        });
    });
