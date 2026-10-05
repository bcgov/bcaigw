<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/logout', [UserController::class, 'logout'])->name('logout');
Route::post('/logout', [UserController::class, 'logout']);

Route::get('/', fn () => redirect('/login'));

Route::get('/login', [UserController::class, 'login'])->name('login');
Route::match(['get', 'post'], '/applogin', [UserController::class, 'appLogin'])->name('applogin');

Route::middleware('auth')->group(function () {
    Route::get('/home', [UserController::class, 'home'])->name('home');
});
