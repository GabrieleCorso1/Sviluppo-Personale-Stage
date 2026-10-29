<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ProjectController;
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

Route::post('/login', [AuthenticatedSessionController::class, 'store']);

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('throttle:5,1');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('throttle:5,1');

Route::post('/projects', [ProjectController::class, 'store'])
    ->middleware('auth:sanctum');
Route::get('/projects', [ProjectController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/projects/{project}', [ProjectController::class, 'show'])
    ->middleware('auth:sanctum');

Route::patch('/projects/{project}', [ProjectController::class, 'update'])
    ->middleware('auth:sanctum');

Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
    ->middleware('auth:sanctum');

Route::patch('/projects/{project}/note', [ProjectController::class, 'updateNote'])
    ->middleware('auth:sanctum');

Route::get('/projects/{project}/users', [ProjectController::class, 'users'])
    ->middleware('auth:sanctum');

Route::post('/projects/{project}/logo', [ProjectController::class, 'uploadLogo'])
    ->middleware('auth:sanctum');

Route::get('/projects/{project}/logo', [ProjectController::class, 'logo'])
    ->middleware('auth:sanctum')
    ->name('projects.logo');
