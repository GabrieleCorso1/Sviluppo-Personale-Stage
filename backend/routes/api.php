<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::get('/user', function (Request $request) {
    $user = $request->user();

    $user->load('roles.permissions');

    return response()->json([
        'id' => $user->id,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        'roles' => $user->roles
            ->pluck('alias')
            ->unique()
            ->values(),
        'permissions' => $user->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->unique()
            ->values(),
    ]);
})->middleware('auth:sanctum');

Route::get('/test', function () {
    return response()->json([
        'message' => 'API funzionante',
        'Status' => 'success',
    ]);
});

// Rotta di test utente base
Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});



Route::middleware('auth:sanctum')->group(function () {
    
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

Route::post('/login', [AuthenticatedSessionController::class, 'store']);

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('throttle:5,1');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('throttle:5,1');
