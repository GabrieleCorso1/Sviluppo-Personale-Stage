<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $this->actor->roles()->attach($role);
    $this->actorToken = $this->actor->createToken('administrator');
    $this->withToken($this->actorToken->plainTextToken);
});

test('an administrative password change revokes all target sessions and allows only the new password', function () {
    $target = User::factory()->create();
    $first = $target->createToken('first-device');
    $target->createToken('second-device');

    $this->putJson('/api/users/'.$target->id, ['password' => 'ChangedPassword123!'])
        ->assertOk()->assertJsonMissingPath('data.password');
    expect(Hash::check('ChangedPassword123!', $target->fresh()->password))->toBeTrue();
    expect($target->tokens()->count())->toBe(0);
    $this->assertModelExists($this->actorToken->accessToken);

    Auth::forgetGuards();
    $this->withToken($first->plainTextToken)->getJson('/api/user')->assertUnauthorized();
    $this->postJson('/api/login', ['email' => $target->email, 'password' => 'password'])
        ->assertUnprocessable();
    $this->postJson('/api/login', ['email' => $target->email, 'password' => 'ChangedPassword123!'])
        ->assertOk()->assertJsonStructure(['access_token']);
});

test('profile updates without a nonempty password preserve password and sessions', function (array $extra) {
    $target = User::factory()->create();
    $token = $target->createToken('preserved');
    $password = $target->password;
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Changed'] + $extra)->assertOk();
    expect($target->fresh()->password)->toBe($password);
    $this->assertModelExists($token->accessToken);
})->with([
    'omitted' => [[]],
    'null' => [['password' => null]],
    'empty' => [['password' => '']],
]);

test('rejected password requests preserve both the user and all tokens', function () {
    $target = User::factory()->create();
    $token = $target->createToken('preserved');
    $before = $target->fresh()->getAttributes();
    $this->putJson('/api/users/'.$target->id, ['password' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    expect($target->fresh()->getAttributes())->toBe($before);
    $this->assertModelExists($token->accessToken);
});

test('a contract error after changing the password rolls back the password and token revocation', function () {
    $target = User::factory()->create();
    $contract = $target->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $first = $target->createToken('first-device');
    $second = $target->createToken('second-device');
    $before = $target->fresh()->getAttributes();
    $this->putJson('/api/users/'.$target->id, [
        'password' => 'ChangedPassword123!', 'contract_end_date' => '2025-12-31',
    ])->assertUnprocessable()->assertJsonValidationErrors('contract_end_date');
    expect($target->fresh()->getAttributes())->toBe($before);
    expect($contract->fresh()->contract_end_date)->toBeNull();
    $this->assertModelExists($first->accessToken);
    $this->assertModelExists($second->accessToken);
});

test('an unauthorized user cannot change passwords or revoke sessions', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $token = $target->createToken('preserved');
    $password = $target->password;
    $this->withToken($actor->createToken('unprivileged')->plainTextToken)
        ->putJson('/api/users/'.$target->id, ['password' => 'ChangedPassword123!'])->assertForbidden();
    expect($target->fresh()->password)->toBe($password);
    $this->assertModelExists($token->accessToken);
});

test('an administrator changing their own password must authenticate again afterward', function () {
    $this->putJson('/api/users/'.$this->actor->id, ['password' => 'ChangedPassword123!'])->assertOk();
    expect($this->actor->tokens()->count())->toBe(0);
    Auth::forgetGuards();
    $this->withToken($this->actorToken->plainTextToken)->getJson('/api/user')->assertUnauthorized();
});
