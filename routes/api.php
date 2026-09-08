<?php

use App\Http\Controllers\GatewayProbeController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/status', [HealthController::class, 'status'])->name('api.status');
Route::get('/ready', [HealthController::class, 'readiness'])->name('api.readiness');

Route::middleware(['machine.auth', 'machine.scope:gateway.invoke'])
    ->get('/gateway/test', GatewayProbeController::class)
    ->name('api.gateway.test');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
