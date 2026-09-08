<?php

use App\Http\Controllers\OpenAiGatewayController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'gateway.request_id',
    'machine.auth',
    'machine.scope:gateway.invoke',
    'gateway.concurrency',
])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/models', [OpenAiGatewayController::class, 'models'])->name('openai.models');
        Route::post('/chat/completions', [OpenAiGatewayController::class, 'chat'])->name('openai.chat.completions');
        Route::post('/responses', [OpenAiGatewayController::class, 'responses'])->name('openai.responses');
        Route::post('/embeddings', [OpenAiGatewayController::class, 'embeddings'])->name('openai.embeddings');
    });
