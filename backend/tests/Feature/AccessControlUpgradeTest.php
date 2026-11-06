<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('the dedicated access seeder enables user management for an existing HR account without requiring a new login', function () {
    $hr = User::factory()->create();
    $role = Role::where('alias', 'HR Manager')->firstOrFail();
    $hr->roles()->attach($role);
    $target = User::factory()->create();
    $target->roles()->attach(Role::firstOrCreate(['alias' => 'User']));
    $before = $hr->fresh()->getAttributes();
    $targetBefore = $target->fresh()->getAttributes();
    $token = $hr->createToken('existing-hr-session');
    $this->withToken($token->plainTextToken);
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Updated by HR'])->assertForbidden();

    $this->seed(AccessControlSeeder::class);

    expect($hr->fresh()->getAttributes())->toBe($before);
    expect($target->fresh()->getAttributes())->toBe($targetBefore);
    $this->assertModelExists($token->accessToken);
    expect($hr->roles()->sole()->id)->toBe($role->id);
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Updated by HR'])->assertOk();
    $this->assertDatabaseHas('users', ['id' => $target->id, 'first_name' => 'Updated by HR']);
    $profile = $this->getJson('/api/user')->assertOk()->json();
    expect($profile['permissions'])->toContain('users.manage');
    $this->postJson('/api/users', [])->assertUnprocessable()->assertJsonValidationErrors('first_name');
    $this->getJson('/api/allocations')->assertForbidden();
    $this->getJson('/api/projects?is_timesheet=0')->assertForbidden();
});

test('repeating the dedicated access seeder preserves accounts contracts sessions and role memberships', function () {
    $user = User::factory()->create();
    $role = Role::where('alias', 'HR Manager')->firstOrFail();
    $user->roles()->attach($role);
    $contract = $user->contracts()->create([
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 40, 'has_rol' => true,
    ]);
    $user->createToken('existing-session');
    $before = [
        'users' => DB::table('users')->orderBy('id')->get()->toJson(),
        'contracts' => DB::table('contracts')->orderBy('id')->get()->toJson(),
        'tokens' => DB::table('personal_access_tokens')->orderBy('id')->get()->toJson(),
        'memberships' => DB::table('role_user')->orderBy('user_id')->orderBy('role_id')->get()->toJson(),
    ];

    $this->seed(AccessControlSeeder::class);
    $roleCount = Role::count();
    $permissionCount = DB::table('permissions')->count();
    $pivotCount = DB::table('permission_role')->count();
    $this->seed(AccessControlSeeder::class);

    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before['users']);
    expect(DB::table('contracts')->orderBy('id')->get()->toJson())->toBe($before['contracts']);
    expect(DB::table('personal_access_tokens')->orderBy('id')->get()->toJson())->toBe($before['tokens']);
    expect(DB::table('role_user')->orderBy('user_id')->orderBy('role_id')->get()->toJson())->toBe($before['memberships']);
    $this->assertDatabaseCount('roles', $roleCount);
    $this->assertDatabaseCount('permissions', $permissionCount);
    $this->assertDatabaseCount('permission_role', $pivotCount);
    expect($contract->fresh()->has_rol)->toBeTrue();
});
