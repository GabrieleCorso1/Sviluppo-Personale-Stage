<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    Notification::fake();
    $this->actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $this->actor->roles()->attach($role);
    $this->withToken($this->actor->createToken('test')->plainTextToken);
    $this->payload = [
        'first_name' => 'Date', 'last_name' => 'Test', 'email' => 'dates@example.com',
        'azure_email' => 'dates@azure.example.com', 'company_email' => 'dates@company.example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-10',
        'filestorage_id' => $this->actor->filestorage_id, 'role_id' => $role->id,
        'contract_start_date' => '2026-01-10', 'weekly_hours' => 40,
    ];
});
afterEach(function () { $this->travelBack(); });

test('creation rejects invalid dates without creating user contract or invitation', function (array $changes, string $field) {
    $this->postJson('/api/users', array_replace($this->payload, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseMissing('users', ['email' => 'dates@example.com']);
    $this->assertDatabaseCount('contracts', 0);
    Notification::assertNothingSent();
})->with([
    'future birth' => [['birth_date' => '2026-11-03'], 'birth_date'],
    'invalid birth date' => [['birth_date' => '2026-02-30'], 'birth_date'],
    'contract before hiring' => [['contract_start_date' => '2026-01-09'], 'contract_start_date'],
    'end before start' => [['contract_end_date' => '2026-01-09'], 'contract_end_date'],
    'invalid hiring date' => [['hiring_date' => 'not-a-date'], 'hiring_date'],
]);

test('future hiring and contract dates are allowed and equal boundaries are valid', function () {
    $this->postJson('/api/users', array_replace($this->payload, [
        'hiring_date' => '2027-01-10', 'contract_start_date' => '2027-01-10', 'contract_end_date' => '2027-01-10',
    ]))->assertCreated();
});

test('future birth updates preserve all existing data', function () {
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();
    $this->putJson('/api/users/'.$user->id, ['first_name' => 'Rollback', 'birth_date' => '2026-11-03'])
        ->assertUnprocessable()->assertJsonValidationErrors('birth_date');
    expect($user->fresh()->getAttributes())->toBe($before);
});

test('contract updates compare a new start against the saved hiring date and preserve all data on failure', function () {
    $user = User::factory()->create(['hiring_date' => '2026-01-10']);
    $contract = $user->contracts()->create(['contract_start_date' => '2026-02-01', 'weekly_hours' => 40]);
    $beforeUser = $user->fresh()->getAttributes();
    $beforeContract = $contract->fresh()->getAttributes();
    $this->putJson('/api/users/'.$user->id, ['first_name' => 'Rollback', 'contract_start_date' => '2026-01-09'])
        ->assertUnprocessable()->assertJsonValidationErrors('contract_start_date');
    expect($user->fresh()->getAttributes())->toBe($beforeUser);
    expect($contract->fresh()->getAttributes())->toBe($beforeContract);
});

test('changing hiring alone checks saved contracts without requiring a selection', function () {
    $user = User::factory()->create(['hiring_date' => '2026-01-10']);
    $first = $user->contracts()->create(['contract_start_date' => '2026-02-01', 'weekly_hours' => 40]);
    $user->contracts()->create(['contract_start_date' => '2026-04-01', 'weekly_hours' => 40]);
    $before = $user->fresh()->getAttributes();
    $this->putJson('/api/users/'.$user->id, ['hiring_date' => '2026-03-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('hiring_date');
    expect($user->fresh()->getAttributes())->toBe($before);
    expect($first->fresh()->contract_start_date->toDateString())->toBe('2026-02-01');
    $this->putJson('/api/users/'.$user->id, ['hiring_date' => '2026-01-01'])->assertOk();
});

test('changing hiring and selected contract together uses the effective new values', function () {
    $user = User::factory()->create(['hiring_date' => '2026-01-10']);
    $contract = $user->contracts()->create(['contract_start_date' => '2026-02-01', 'weekly_hours' => 40]);
    $this->putJson('/api/users/'.$user->id, [
        'hiring_date' => '2027-01-01', 'contract_id' => $contract->id,
        'contract_start_date' => '2027-01-01', 'contract_end_date' => '2027-01-01',
    ])->assertOk();
    expect($user->fresh()->hiring_date->toDateString())->toBe('2027-01-01');
    expect($contract->fresh()->contract_start_date->toDateString())->toBe('2027-01-01');
});

test('changing the selected contract cannot invalidate another contract through a hiring change', function () {
    $user = User::factory()->create(['hiring_date' => '2026-01-10']);
    $first = $user->contracts()->create(['contract_start_date' => '2026-02-01', 'weekly_hours' => 40]);
    $second = $user->contracts()->create(['contract_start_date' => '2026-04-01', 'weekly_hours' => 40]);
    $this->putJson('/api/users/'.$user->id, [
        'hiring_date' => '2026-03-01', 'contract_id' => $second->id, 'contract_start_date' => '2026-05-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('hiring_date');
    expect($user->fresh()->hiring_date->toDateString())->toBe('2026-01-10');
    expect($second->fresh()->contract_start_date->toDateString())->toBe('2026-04-01');
    expect($first->fresh()->contract_start_date->toDateString())->toBe('2026-02-01');
});

test('birth date today follows Rome even when UTC is on the previous day', function () {
    $this->travelTo(Carbon::parse('2026-11-01 23:30:00', 'UTC'));
    $this->postJson('/api/users', array_replace($this->payload, ['birth_date' => '2026-11-02']))->assertCreated();
});
