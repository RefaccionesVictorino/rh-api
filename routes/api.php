<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    // Ejemplos de protección por rol / permiso (Spatie):
    // Route::middleware('role:admin')->group(function () { ... });
    // Route::middleware('permission:usuarios.ver')->get('/usuarios', ...);
});
