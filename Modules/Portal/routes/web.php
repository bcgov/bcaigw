<?php

use Illuminate\Support\Facades\Route;
use Modules\Portal\Http\Controllers\ApplicationController;
use Modules\Portal\Http\Controllers\ApplicationMemberController;
use Modules\Portal\Http\Controllers\CallHistoryController;
use Modules\Portal\Http\Controllers\PortalController;

// Portal for ministry users (Ministry User / Ministry Guest and administrators).
Route::middleware(['auth'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('dashboard');

    Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
    Route::post('/applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::get('/applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::get('/applications/{application}/edit', [ApplicationController::class, 'edit'])->name('applications.edit');
    Route::put('/applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
    Route::post('/applications/{application}/submit', [ApplicationController::class, 'submit'])->name('applications.submit');
    Route::post('/applications/{application}/promote', [ApplicationController::class, 'promote'])->name('applications.promote');
    Route::post('/applications/{application}/models', [ApplicationController::class, 'changeModels'])->name('applications.models.change');

    Route::post('/applications/{application}/members', [ApplicationMemberController::class, 'store'])
        ->name('applications.members.store');
    Route::delete('/applications/{application}/members/{user}', [ApplicationMemberController::class, 'destroy'])
        ->name('applications.members.destroy');

    Route::get('/calls', [CallHistoryController::class, 'index'])->name('calls.index');
    Route::get('/calls/{callAttempt}', [CallHistoryController::class, 'show'])->name('calls.show');
});
