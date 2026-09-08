<?php

use App\Http\Controllers\OAuthTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/oauth/token', OAuthTokenController::class)
    ->middleware('throttle:oauth-token')
    ->name('oauth.token');

Route::get('/.well-known/oauth-authorization-server', function () {
    return response()->json([
        'issuer' => config('machine-auth.issuer'),
        'token_endpoint' => url('/oauth/token'),
        'grant_types_supported' => ['client_credentials'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        'scopes_supported' => array_keys(config('machine-auth.abilities')),
    ]);
})->name('oauth.metadata');
