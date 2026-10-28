<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test', function () {
    return response()->json([
        'message'=> 'API funzionante',
        'Status'=> 'success'    
        ]);
});

// Rotta di test utente base
Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// Rotte di Autenticazione (Login / Logout)
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

//

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    
    // Gestione Utenti Completa (Figma Sync)
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{id}/disable', [UserController::class, 'disable']);
    Route::post('/users/{id}/restore', [UserController::class, 'restore']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

    // Gestione Ferie, Permessi e R.O.L. (Modale Figma)
    Route::get('/users/{id}/leaves', [UserController::class, 'getLeaves']);
    Route::put('/users/{id}/leaves', [UserController::class, 'updateLeaves']);
});

