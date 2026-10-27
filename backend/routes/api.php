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

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    
    // --- NUOVE ROTTE PER LA GESTIONE UTENTI ---
    Route::get('/users', [UserController::class, 'index']);          // Lista di tutti gli utenti
    Route::get('/user/profile', [UserController::class, 'profile']); // Profilo utente loggato
    Route::delete('/users/{id}', [UserController::class, 'destroy']); // Cancella un utente per ID
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/users', [UserController::class, 'store']);
});
