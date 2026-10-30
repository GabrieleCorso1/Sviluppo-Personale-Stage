<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('the profile exposes only selected fields with distinct role permissions', function () {
    $user = User::factory()->create();
    $admin = Role::create(['alias' => 'Admin', 'description' => 'Admin']);
    $standard = Role::create(['alias' => 'User', 'description' => 'User']);
    $permission = Permission::create(['name' => 'users.view', 'description' => 'View users']);
    $admin->permissions()->attach($permission);
    $standard->permissions()->attach($permission);
    $user->roles()->attach([$admin->id, $standard->id]);
    $token = $user->createToken('test');

    $this->withToken($token->plainTextToken)->getJson('/api/user')->assertOk()->assertExactJson([
        'id' => $user->id,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        'roles' => ['Admin', 'User'],
        'permissions' => ['users.view'],
    ]);
});
