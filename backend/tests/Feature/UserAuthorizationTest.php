<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);

function userAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    foreach (['users.view', 'users.manage', 'users.leaves.manage'] as $name) {
        $role->permissions()->attach(Permission::create(['name' => $name, 'description' => $name]));
    }
    $actor->roles()->attach($role);

    return $actor;
}

dataset('user administration endpoints', [
    'list' => ['GET', '/api/users', false],
    'create' => ['POST', '/api/users', false],
    'update' => ['PUT', '/api/users/%d', false],
    'disable' => ['POST', '/api/users/%d/disable', false],
    'delete' => ['DELETE', '/api/users/%d', false],
    'restore' => ['POST', '/api/users/%d/restore', true],
    'read counters' => ['GET', '/api/users/%d/leaves', false],
    'write counters' => ['PUT', '/api/users/%d/leaves', false],
]);

test('user administration returns 401 without authentication', function (string $method, string $path, bool $disabled) {
    $target = User::factory()->create();
    if ($disabled) {
        $target->delete();
    }

    $this->json($method, sprintf($path, $target->id))->assertUnauthorized();
})->with('user administration endpoints');

test('user administration returns 403 without permission and preserves data', function (string $method, string $path, bool $disabled) {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    if ($disabled) {
        $target->delete();
    }
    $before = $target->fresh()->getAttributes();

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->json($method, sprintf($path, $target->id), ['first_name' => 'Unauthorized'])
        ->assertForbidden();

    expect($target->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('contracts', 0);
    $this->assertDatabaseCount('user_leave_balances', 0);
})->with('user administration endpoints');

test('a viewer can list users but cannot update them', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $role = Role::create(['alias' => 'Project Manager']);
    $role->permissions()->attach(Permission::create(['name' => 'users.view', 'description' => 'View users']));
    $actor->roles()->attach($role);
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->getJson('/api/users')->assertOk()->assertJsonPath('data.total', 2);
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Unauthorized'])->assertForbidden();
    expect($target->fresh()->first_name)->toBe($target->first_name);
});

test('an administrator can create a user with a contract and update their profile', function () {
    $actor = userAdministrator();
    $this->withToken($actor->createToken('test')->plainTextToken);

    $response = $this->postJson('/api/users', [
        'first_name' => 'Nuovo', 'last_name' => 'Utente', 'email' => 'nuovo@example.com',
        'azure_email' => 'nuovo@azure.example.com', 'company_email' => 'nuovo@company.example.com',
        'birth_date' => '1995-01-01', 'hiring_date' => '2026-11-01',
        'role_id' => $actor->roles()->firstOrFail()->id, 'filestorage_id' => $actor->filestorage_id, 'contract_start_date' => '2026-11-01', 'weekly_hours' => 40,
    ])->assertCreated()->assertJsonPath('data.first_name', 'Nuovo');
    $id = $response->json('data.id');
    $this->assertDatabaseHas('users', ['id' => $id, 'email' => 'nuovo@example.com']);
    $this->assertDatabaseHas('contracts', ['user_id' => $id, 'weekly_hours' => 40]);

    $this->putJson('/api/users/'.$id, ['first_name' => 'Modificato'])
        ->assertOk()->assertJsonPath('data.first_name', 'Modificato');
    $this->assertDatabaseHas('users', ['id' => $id, 'first_name' => 'Modificato']);
});

test('an administrator can disable and restore a user without reviving old tokens', function () {
    $actor = userAdministrator();
    $target = User::factory()->create();
    $oldToken = $target->createToken('old-access');
    $target->createToken('second-device');
    $actorToken = $actor->createToken('test')->plainTextToken;
    $this->withToken($actorToken);

    $this->postJson('/api/users/'.$target->id.'/disable', ['resignation_date' => '2026-11-02'])->assertOk();
    $this->assertSoftDeleted($target);
    expect($target->fresh()->resignation_date->toDateString())->toBe('2026-11-02');
    expect($target->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $actor->tokens()->firstOrFail()->id]);

    $this->postJson('/api/users/'.$target->id.'/restore')->assertOk();
    expect($target->fresh()->trashed())->toBeFalse();
    expect($target->fresh()->resignation_date)->toBeNull();
    Auth::forgetGuards();
    $this->withToken($oldToken->plainTextToken)->getJson('/api/user')->assertUnauthorized();
});

test('an administrator cannot disable or delete their own account', function (string $method, string $suffix) {
    $actor = userAdministrator();
    $token = $actor->createToken('test');

    $this->withToken($token->plainTextToken)->json($method, '/api/users/'.$actor->id.$suffix, [
        'resignation_date' => '2026-11-02',
    ])->assertForbidden();

    expect($actor->fresh()->trashed())->toBeFalse();
    expect($actor->fresh()->resignation_date)->toBeNull();
    $this->assertModelExists($token->accessToken);
})->with([['POST', '/disable'], ['DELETE', '']]);

test('disabling requires a resignation date and leaves the account active on validation errors', function () {
    $actor = userAdministrator();
    $target = User::factory()->create();
    $token = $target->createToken('existing');

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users/'.$target->id.'/disable', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['resignation_date']);

    expect($target->fresh()->trashed())->toBeFalse();
    $this->assertModelExists($token->accessToken);
});

test('the legacy delete endpoint also revokes tokens while preserving the user record', function () {
    $actor = userAdministrator();
    $target = User::factory()->create();
    $target->createToken('existing');

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->deleteJson('/api/users/'.$target->id)->assertOk();

    $this->assertSoftDeleted($target);
    expect($target->tokens()->count())->toBe(0);
});

test('an administrator can save and read leave counters', function () {
    $actor = userAdministrator();
    $target = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->putJson('/api/users/'.$target->id.'/leaves', ['leaves' => [[
        'leave_type' => 'ferie', 'reference_month' => '2026-11-01',
        'remaining_hours' => 8, 'accrued_hours' => 16, 'monthly_accrual' => 4,
    ]]])->assertOk();

    $this->assertDatabaseHas('user_leave_balances', [
        'user_id' => $target->id, 'leave_type' => 'ferie', 'remaining_hours' => 8,
    ]);
    $this->getJson('/api/users/'.$target->id.'/leaves')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.remaining_hours', '8.00');
});
