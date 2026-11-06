<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

function matrixUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('alias', $role)->firstOrFail());
    return $user;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $this->seed(AccessControlSeeder::class);
    Notification::fake();
    $this->hr = matrixUser('HR Manager');
    $this->withToken($this->hr->createToken('hr')->plainTextToken);
});
afterEach(function () { $this->travelBack(); });

test('HR can create ordinary users and update their personal data contract and ordinary role', function () {
    $role = Role::where('alias', 'User')->firstOrFail();
    $created = $this->postJson('/api/users', [
        'first_name' => 'New', 'last_name' => 'Person', 'email' => 'hr-created@example.com',
        'azure_email' => 'hr-created@azure.example.com', 'company_email' => 'hr-created@company.example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-01',
        'filestorage_id' => $this->hr->filestorage_id, 'role_id' => $role->id,
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ])->assertCreated();
    $id = $created->json('data.id');
    $pm = Role::where('alias', 'Project Manager')->firstOrFail();
    $this->putJson('/api/users/'.$id, [
        'first_name' => 'Changed', 'contract_start_date' => '2026-02-01', 'role_id' => $pm->id,
    ])->assertOk()->assertJsonPath('data.first_name', 'Changed');
    expect(User::findOrFail($id)->contracts()->firstOrFail()->contract_start_date->toDateString())->toBe('2026-02-01');
    $this->assertDatabaseHas('role_user', ['user_id' => $id, 'role_id' => $pm->id]);
});

test('HR cannot assign privileged roles on creation or update', function (string $privilegedRole) {
    $target = matrixUser('User');
    $role = Role::where('alias', $privilegedRole)->firstOrFail();
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Forbidden', 'role_id' => $role->id])
        ->assertUnprocessable()->assertJsonValidationErrors('role_id');
    expect($target->fresh()->first_name)->toBe($target->first_name);
    $this->assertDatabaseMissing('role_user', ['user_id' => $target->id, 'role_id' => $role->id]);
    $this->postJson('/api/users', [
        'first_name' => 'Forbidden', 'last_name' => 'Role', 'email' => 'forbidden@example.com',
        'azure_email' => 'forbidden@azure.example.com', 'company_email' => 'forbidden@company.example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-01',
        'filestorage_id' => $this->hr->filestorage_id, 'role_id' => $role->id,
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
    $this->assertDatabaseMissing('users', ['email' => 'forbidden@example.com']);
})->with(['Admin', 'HR Manager']);

test('HR can read but cannot modify or downgrade privileged accounts', function (string $role) {
    $target = matrixUser($role);
    $this->getJson('/api/users/'.$target->id)->assertOk();
    $this->putJson('/api/users/'.$target->id, [
        'first_name' => 'Forbidden', 'role_id' => Role::where('alias', 'User')->value('id'),
    ])->assertForbidden();
    expect($target->fresh()->first_name)->toBe($target->first_name);
    expect($target->roles()->where('alias', $role)->exists())->toBeTrue();
})->with(['Admin', 'HR Manager']);

test('HR cannot disable or restore privileged accounts through either disable route', function (string $role) {
    $target = matrixUser($role);
    $token = $target->createToken('preserved');
    $this->postJson('/api/users/'.$target->id.'/disable', ['resignation_date' => '2026-11-02'])->assertForbidden();
    $this->deleteJson('/api/users/'.$target->id, ['resignation_date' => '2026-11-02'])->assertForbidden();
    expect($target->fresh()->trashed())->toBeFalse();
    $this->assertModelExists($token->accessToken);
    $target->delete();
    $this->postJson('/api/users/'.$target->id.'/restore')->assertForbidden();
    $this->assertSoftDeleted($target);
})->with(['Admin', 'HR Manager']);

test('HR can disable and restore non privileged users through both routes', function (string $method, string $suffix) {
    $target = matrixUser('User');
    $target->createToken('old');
    $this->json($method, '/api/users/'.$target->id.$suffix, ['resignation_date' => '2026-11-02'])->assertOk();
    $this->assertSoftDeleted($target);
    expect($target->tokens()->count())->toBe(0);
    $this->postJson('/api/users/'.$target->id.'/restore')->assertOk();
    expect($target->fresh()->trashed())->toBeFalse();
    expect($target->fresh()->resignation_date)->toBeNull();
})->with([['POST', '/disable'], ['DELETE', '']]);

test('HR can manage counters of every role including their own', function (string $role) {
    $target = matrixUser($role);
    foreach ([$target, $this->hr] as $user) {
        $this->putJson('/api/users/'.$user->id.'/leaves', [
            'leaves' => [['leave_type' => 'ferie', 'reference_month' => '2026-11-01', 'remaining_hours' => 8]],
        ])->assertOk();
        $this->getJson('/api/users/'.$user->id.'/leaves')->assertOk();
    }
})->with(['Admin', 'HR Manager', 'User', 'Project Manager', 'Team Leader']);

test('non administrative roles cannot access management even if legacy permissions remain', function (string $alias) {
    $user = matrixUser($alias);
    $role = $user->roles()->firstOrFail();
    $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['users.view', 'users.manage', 'users.leaves.manage'])->pluck('id')->all());
    $this->withToken($user->createToken('legacy')->plainTextToken);
    $target = matrixUser('User');
    $this->getJson('/api/users')->assertForbidden();
    $this->getJson('/api/users/options')->assertForbidden();
    $this->getJson('/api/users/'.$target->id)->assertForbidden();
    $this->postJson('/api/users', [])->assertForbidden();
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Forbidden'])->assertForbidden();
    $this->getJson('/api/users/'.$target->id.'/leaves')->assertForbidden();
})->with(['Project Manager', 'Team Leader', 'User']);

test('Admin can assign privileged roles and modify privileged accounts', function (string $alias) {
    $admin = matrixUser('Admin');
    $this->withToken($admin->createToken('admin')->plainTextToken);
    $target = matrixUser('HR Manager');
    $role = Role::where('alias', $alias)->firstOrFail();
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Allowed', 'role_id' => $role->id])
        ->assertOk();
    expect($target->roles()->whereKey($role->id)->exists())->toBeTrue();
    $this->postJson('/api/users/'.$target->id.'/disable', ['resignation_date' => '2026-11-02'])->assertOk();
    $this->postJson('/api/users/'.$target->id.'/restore')->assertOk();
})->with(['Admin', 'HR Manager']);

test('the permission seeder upgrades existing roles repeatably without altering project permissions', function () {
    $view = Permission::where('name', 'users.view')->firstOrFail();
    $manage = Permission::where('name', 'users.manage')->firstOrFail();
    $hr = Role::where('alias', 'HR Manager')->firstOrFail();
    $hr->permissions()->detach($manage);
    foreach (['Project Manager', 'Team Leader'] as $alias) {
        Role::where('alias', $alias)->firstOrFail()->permissions()->syncWithoutDetaching([$view->id]);
    }
    $this->seed(AccessControlSeeder::class);
    $this->seed(AccessControlSeeder::class);
    expect($hr->permissions()->whereKey($manage->id)->count())->toBe(1);
    foreach (['Project Manager', 'Team Leader'] as $alias) {
        $role = Role::where('alias', $alias)->firstOrFail();
        expect($role->permissions()->whereKey($view->id)->exists())->toBeFalse();
        expect($role->permissions()->where('name', 'projects.view')->exists())->toBeTrue();
    }
    $this->seed(AccessControlSeeder::class);
    expect($hr->permissions()->whereKey($manage->id)->count())->toBe(1);
});

test('HR cannot bypass protection when the target has both ordinary and privileged roles', function () {
    $target = matrixUser('User');
    $target->roles()->attach(Role::where('alias', 'Admin')->firstOrFail());
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Forbidden'])->assertForbidden();
    $this->postJson('/api/users/'.$target->id.'/disable', ['resignation_date' => '2026-11-02'])->assertForbidden();
    expect($target->roles()->count())->toBe(2);
});

test('Admin can create accounts with either privileged role', function (string $alias) {
    $admin = matrixUser('Admin');
    $this->withToken($admin->createToken('admin')->plainTextToken);
    $role = Role::where('alias', $alias)->firstOrFail();
    $this->postJson('/api/users', [
        'first_name' => 'Privileged', 'last_name' => 'Account', 'email' => 'admin-created@example.com',
        'azure_email' => 'admin-created@azure.example.com', 'company_email' => 'admin-created@company.example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-01',
        'filestorage_id' => $admin->filestorage_id, 'role_id' => $role->id,
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ])->assertCreated()->assertJsonPath('data.roles.0.id', $role->id);
})->with(['Admin', 'HR Manager']);
