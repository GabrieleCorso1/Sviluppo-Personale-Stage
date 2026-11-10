<?php

use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AllocationController;
use App\Http\Controllers\SbiController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\CalendarRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectManagerOptionController;
use App\Http\Controllers\ProjectParticipantAvatarController;
use App\Http\Controllers\SprintCsvController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserLeaveController;
use App\Http\Middleware\EnsureManagedProject;
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
        'hiring_date' => $user->hiring_date?->toDateString(),
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

    Route::get('/dashboard', DashboardController::class);
    Route::post('/calendar/requests', [CalendarRequestController::class, 'store']);
    Route::patch('/admin/notifications/{notification}/decision', [AdminNotificationController::class, 'decide'])->whereUuid('notification');
    Route::get('/notifications/{notification}', [AdminNotificationController::class, 'show'])->whereUuid('notification')->name('notifications.personal.show');
    Route::get('/notifications/{notification}/avatar', [AdminNotificationController::class, 'avatar'])->whereUuid('notification')->name('notifications.personal.avatar');
    Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('notifications.personal.index');
    Route::patch('/notifications', [AdminNotificationController::class, 'update'])->name('notifications.personal.update');
    Route::delete('/notifications', [AdminNotificationController::class, 'destroy'])->name('notifications.personal.destroy');

    Route::get('/admin/notifications', [AdminNotificationController::class, 'index']);
    Route::patch('/admin/notifications', [AdminNotificationController::class, 'update']);
    Route::delete('/admin/notifications', [AdminNotificationController::class, 'destroy']);


    // Gestione Utenti Completa (Figma Sync)
    Route::get('/users/options', [UserController::class, 'options']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/user/profile', [ProfileController::class, 'show']);
    Route::put('/user/password', [ProfileController::class, 'password'])->middleware('throttle:5,1');
    Route::get('/user/avatar', [ProfileController::class, 'avatar'])->name('user.avatar');
    Route::post('/user/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/user/avatar', [ProfileController::class, 'removeAvatar']);
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

Route::middleware('auth:sanctum')->prefix('allocations')->controller(AllocationController::class)->group(function () {
    Route::get('/options', 'options');
    Route::get('/summary', 'summary');
    Route::get('/', 'index');
    Route::get('/projects/{project}', 'project');
    Route::get('/resources/{user}', 'resource');
    Route::get('/resources/{user}/avatar', 'avatar')->name('allocations.resources.avatar');
    Route::get('/projects/{project}/logo', 'logo')->name('allocations.projects.logo');
    Route::put('/projects/{project}/necessities', 'saveNecessities');
    Route::post('/', 'store');
    Route::put('/{allocation}', 'update');
    Route::patch('/{allocation}/difficulty', 'difficulty');
    Route::post('/{allocation}/conclude', 'conclude');
    Route::delete('/{allocation}', 'destroy');
});
Route::post('/projects', [ProjectController::class, 'store'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);
Route::get('/projects', [ProjectController::class, 'index'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::get('/projects/{project}', [ProjectController::class, 'show'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::patch('/projects/{project}', [ProjectController::class, 'update'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::patch('/projects/{project}/note', [ProjectController::class, 'updateNote'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::get('/projects/{project}/users', [ProjectController::class, 'users'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::post('/projects/{project}/logo', [ProjectController::class, 'uploadLogo'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::get('/projects/{project}/logo', [ProjectController::class, 'logo'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class])
    ->name('projects.logo');

Route::post('/projects/{project}/delivery-plan', [ProjectController::class, 'uploadDeliveryPlan'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class]);

Route::get('/projects/{project}/delivery-plan', [ProjectController::class, 'deliveryPlan'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class])
    ->name('projects.delivery-plan');

Route::middleware(['auth:sanctum', EnsureManagedProject::class])->group(function () {
    Route::get('/projects/{project}/csv', [SprintCsvController::class, 'index']);
    Route::post('/projects/{project}/sprints/{sprint}/csv', [SprintCsvController::class, 'upload']);
    Route::get('/projects/{project}/sprints/{sprint}/csv', [SprintCsvController::class, 'download'])
        ->name('projects.sprints.csv');
    Route::patch('/projects/{project}/sprints/{sprint}/csv-type', [SprintCsvController::class, 'updateType']);
});

Route::get('/project-options/users', [ProjectManagerOptionController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/projects/{project}/users/{user}/avatar', [ProjectParticipantAvatarController::class, 'show'])
    ->middleware(['auth:sanctum', EnsureManagedProject::class])
    ->name('projects.users.avatar');

Route::middleware('auth:sanctum')->prefix('sbis')->controller(SbiController::class)->group(function () {
    Route::get('/users', 'users');
    Route::get('/options', 'options');
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{sbi}', 'show');
    Route::put('/{sbi}', 'update');
    Route::delete('/{sbi}', 'destroy');
    Route::put('/{sbi}/revisions/{revision}/reply', 'reply');
    Route::delete('/{sbi}/revisions/{revision}', 'destroyRevision');
    Route::get('/{sbi}/users/{user}/avatar', 'avatar')->name('sbis.avatar');
    Route::get('/{sbi}/logo', 'logo')->name('sbis.logo');
});
