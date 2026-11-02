<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserLeaveController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::middleware('auth:sanctum')->group(function () {

    // Gestione Utenti Completa (Figma Sync)
    Route::get('/users/options', [UserController::class, 'options']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{id}/disable', [UserController::class, 'disable']);
    Route::post('/users/{id}/restore', [UserController::class, 'restore']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

    // Gestione Ferie, Permessi e R.O.L. (Modale Figma)
    Route::get('/users/{user}/leaves', [UserLeaveController::class, 'index']);
    Route::put('/users/{user}/leaves', [UserLeaveController::class, 'update']);
    Route::get('/users/{user}/leaves/history', [UserLeaveController::class, 'history']);
    Route::get('/users/{user}/leaves/conversion', [UserLeaveController::class, 'conversion']);
});

Route::post('/login', [AuthenticatedSessionController::class, 'store']);

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('throttle:5,1');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('throttle:5,1');
