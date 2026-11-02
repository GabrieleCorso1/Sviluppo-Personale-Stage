<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

test('user permissions follow the seeded role matrix', function (string $alias, bool $canView, bool $canManage, bool $canLeaves) {
    $this->seed(AccessControlSeeder::class);
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $actor->roles()->attach(Role::where('alias', $alias)->firstOrFail());
    $gate = Gate::forUser($actor);

    expect($gate->allows('viewAny', User::class))->toBe($canView);
    expect($gate->allows('create', User::class))->toBe($canManage);
    expect($gate->allows('update', $target))->toBe($canManage);
    expect($gate->allows('delete', $target))->toBe($canManage);
    expect($gate->allows('restore', $target))->toBe($canManage);
    expect($gate->allows('manageLeaves', $target))->toBe($canLeaves);
    expect($gate->allows('update', $actor))->toBe($canManage && $alias !== 'HR Manager');
    expect($gate->allows('delete', $actor))->toBeFalse();
})->with([
    'HR manager' => ['HR Manager', true, true, true],
    'administrator' => ['Admin', true, true, true],
    'project manager' => ['Project Manager', false, false, false],
    'team leader' => ['Team Leader', false, false, false],
    'standard user' => ['User', false, false, false],
]);

test('an admin role without permissions does not grant user administration', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $actor->roles()->attach(Role::create(['alias' => 'Admin']));
    $gate = Gate::forUser($actor);

    expect($gate->allows('viewAny', User::class))->toBeFalse();
    expect($gate->allows('create', User::class))->toBeFalse();
    expect($gate->allows('update', $target))->toBeFalse();
    expect($gate->allows('delete', $target))->toBeFalse();
    expect($gate->allows('restore', $target))->toBeFalse();
    expect($gate->allows('manageLeaves', $target))->toBeFalse();
});

test('user administration permission alone does not grant access to leave counters', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $permission = Permission::create(['name' => 'users.manage', 'description' => 'Manage users']);
    $role->permissions()->attach($permission);
    $actor->roles()->attach($role);
    $this->withToken($actor->createToken('test')->plainTextToken)->getJson('/api/users/'.$target->id.'/leaves')->assertForbidden();
});

test('HR can manage counters and ordinary accounts', function () {
    $this->seed(AccessControlSeeder::class);
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $actor->roles()->attach(Role::where('alias', 'HR Manager')->firstOrFail());
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->getJson('/api/users')->assertOk();
    $this->putJson('/api/users/'.$target->id.'/leaves', ['leaves' => [['leave_type' => 'ferie', 'reference_month' => '2026-01-01', 'remaining_hours' => 8]]])->assertOk();
    $this->assertDatabaseHas('leave_audit_logs', ['admin_id' => $actor->id, 'user_id' => $target->id, 'new_value' => 8]);
    $this->postJson('/api/users', [])->assertUnprocessable();
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Allowed'])->assertOk();
    $this->postJson('/api/users/'.$target->id.'/disable', ['resignation_date' => '2026-11-02'])->assertOk();
});

test('the permission upgrade backfills existing admins without duplicating HR roles', function () {
    $admin = Role::create(['alias' => 'Admin']);
    $migration = require database_path('migrations/2026_11_02_101136_add_hr_manager_role_and_leave_permission.php');
    $migration->up();
    $migration->up();
    expect(Role::where('alias', 'HR Manager')->count())->toBe(1);
    expect($admin->permissions()->where('name', 'users.leaves.manage')->count())->toBe(1);
});
