<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);
beforeEach(function () {
    Notification::fake();
    $this->actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $this->actor->roles()->attach($role);
    $this->withToken($this->actor->createToken('test')->plainTextToken);
    $this->payload = [
        'first_name' => 'Optional', 'last_name' => 'Email', 'email' => 'optional@example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-01',
        'filestorage_id' => $this->actor->filestorage_id, 'role_id' => $role->id,
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ];
});

test('users can be created with omitted empty or null optional email addresses', function (array $extra) {
    $response = $this->postJson('/api/users', $this->payload + $extra)->assertCreated()
        ->assertJsonPath('data.azure_email', null)->assertJsonPath('data.company_email', null);
    $this->assertDatabaseHas('users', ['id' => $response->json('data.id'), 'azure_email' => null, 'company_email' => null]);
})->with([
    'omitted' => [[]],
    'null' => [['azure_email' => null, 'company_email' => null]],
    'empty' => [['azure_email' => '', 'company_email' => '']],
]);

test('optional addresses can be cleared and remain unchanged when omitted from an edit', function () {
    $target = User::factory()->create();
    $this->putJson('/api/users/'.$target->id, ['first_name' => 'Changed'])->assertOk();
    expect($target->fresh()->azure_email)->toBe($target->azure_email);
    expect($target->fresh()->company_email)->toBe($target->company_email);
    $this->putJson('/api/users/'.$target->id, ['azure_email' => '', 'company_email' => null])->assertOk();
    expect($target->fresh()->azure_email)->toBeNull();
    expect($target->fresh()->company_email)->toBeNull();
});

test('optional email addresses must be valid if provided in creation and editing', function (string $field) {
    $this->postJson('/api/users', $this->payload + [$field => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $target = User::factory()->create();
    $before = $target->fresh()->getAttributes();
    $this->putJson('/api/users/'.$target->id, [$field => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($target->fresh()->getAttributes())->toBe($before);
})->with(['azure_email', 'company_email']);

test('birth date and login email remain required at creation', function (string $field) {
    $payload = $this->payload;
    unset($payload[$field]);
    $this->postJson('/api/users', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseMissing('users', ['email' => 'optional@example.com']);
})->with(['birth_date', 'email']);
