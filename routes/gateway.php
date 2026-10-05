<?php

use App\Http\Controllers\Gateway\GatewayController;
use Illuminate\Support\Facades\Route;

/*
 | Gateway invocation API. Registered in bootstrap/app.php under the /api/gateway
 | prefix with the gateway.jwt (API Directory token) and gateway.app (application
 | GUID) middleware. These routes are stateless: no session or CSRF.
 */
Route::get('/v1/models', [GatewayController::class, 'models'])->name('gateway.models');
Route::post('/v1/chat/completions', [GatewayController::class, 'chatCompletions'])->name('gateway.chat');
Route::post('/v1/images/generations', [GatewayController::class, 'imageGenerations'])->name('gateway.images');
Route::post('/v1/embeddings', [GatewayController::class, 'embeddings'])->name('gateway.embeddings');
Route::post('/v1/rerank', [GatewayController::class, 'rerank'])->name('gateway.rerank');
