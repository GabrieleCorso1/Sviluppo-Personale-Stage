<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

function initialPasswordAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $actor->roles()->attach($role);

    return $actor;
}

function initialPasswordPayload(User $actor): array
{
    return [
        'first_name' => 'Nuovo', 'last_name' => 'Utente', 'email' => 'invited@example.com',
        'azure_email' => 'invited@azure.example.com', 'company_email' => 'invited@company.example.com',
        'birth_date' => '1995-01-01', 'hiring_date' => '2026-01-01', 'filestorage_id' => $actor->filestorage_id,
        'role_id' => $actor->roles()->firstOrFail()->id, 'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ];
}

test('a new demo account can log in with its initial password and change it from the profile', function () {
    $actor = initialPasswordAdministrator();
    Notification::fake();
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', initialPasswordPayload($actor))->assertCreated()
        ->assertJsonPath('initial_password', 'Password123!')->assertJsonMissingPath('invitation')
        ->assertJsonMissingPath('data.password');
    $user = User::findOrFail($response->json('data.id'));
    expect(Hash::check('Password123!', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('Password123!');
    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
    Auth::forgetGuards();
    $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertOk()->json('access_token');
    $this->withToken($token)->putJson('/api/user/password', [
        'current_password' => 'Password123!', 'password' => 'FirstPassword123!',
        'password_confirmation' => 'FirstPassword123!',
    ])->assertOk();
    expect($user->tokens()->count())->toBe(0);
    Auth::forgetGuards();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password123!'])->assertUnprocessable();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'FirstPassword123!'])->assertOk()->assertJsonStructure(['access_token']);
});

test('creation rejects an administrator supplied password', function () {
    $actor = initialPasswordAdministrator();
    Notification::fake();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', initialPasswordPayload($actor) + ['password' => 'AdminChosen123!'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    Notification::assertNothingSent();
    $this->assertDatabaseCount('users', 1);
});

test('invalid contracts do not create accounts or send emails', function () {
    $actor = initialPasswordAdministrator();
    Notification::fake();
    $payload = initialPasswordPayload($actor);
    $payload['weekly_hours'] = 20;
    $this->withToken($actor->createToken('test')->plainTextToken)->postJson('/api/users', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['working_days']);
    Notification::assertNothingSent();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('contracts', 0);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

test('new demo accounts retain the normal forgot password recovery flow', function () {
    $actor = initialPasswordAdministrator();
    Notification::fake();
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', initialPasswordPayload($actor))->assertCreated();
    $user = User::findOrFail($response->json('data.id'));
    Notification::assertNothingSent();
    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
    $token = Notification::sent($user, ResetPassword::class)->first()->token;
    expect(Password::tokenExists($user, $token))->toBeTrue();
    $payload = ['email' => $user->email, 'token' => $token,
        'password' => 'RecoveredPassword123!', 'password_confirmation' => 'RecoveredPassword123!'];
    $this->postJson('/api/reset-password', $payload)->assertOk();
    $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
    expect(Hash::check('RecoveredPassword123!', $user->fresh()->password))->toBeTrue();
});
