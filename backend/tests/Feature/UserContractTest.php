<?php

use App\Models\Contract;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function contractAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $actor->roles()->attach($role);

    return $actor;
}

function contractSchedule(int $hours = 20): array
{
    $days = $hours === 24 ? ['monday', 'tuesday', 'wednesday'] : ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    return array_map(fn ($day) => [
        'day_of_week' => $day, 'start_time' => '09:00',
        'end_time' => $hours === 24 ? '18:00' : '13:00', 'is_working_day' => true,
    ], $days);
}
function exampleUserContract(User $user, array $attributes = []): Contract
{
    return $user->contracts()->create($attributes + [
        'contract_start_date' => '2026-01-01', 'contract_end_date' => '2026-12-31',
        'weekly_hours' => 40, 'contract_type' => 'determinato',
    ]);
}

test('individual contract fields can be updated without resending other fields', function (array $changes, string $field, mixed $expected) {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $contract = exampleUserContract($user);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, $changes)->assertOk();

    $fresh = $contract->fresh();
    expect($fresh->getAttribute($field))->toBe($expected);
    expect($user->contracts()->count())->toBe(1);
    $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'user_id' => $user->id]);
})->with([
    'hours' => [['weekly_hours' => 20, 'working_days' => contractSchedule()], 'weekly_hours', 20],
    'type' => [['contract_type' => 'indeterminato'], 'contract_type', 'indeterminato'],
    'clear type' => [['contract_type' => null], 'contract_type', null],
    'clear end date' => [['contract_end_date' => null], 'contract_end_date', null],
]);

test('updating hours preserves contract type and dates', function () {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $contract = exampleUserContract($user);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['weekly_hours' => 24, 'working_days' => contractSchedule(24)])->assertOk();

    expect($contract->fresh()->contract_type)->toBe('determinato');
    expect($contract->fresh()->contract_start_date->toDateString())->toBe('2026-01-01');
    expect($contract->fresh()->contract_end_date->toDateString())->toBe('2026-12-31');
});

test('updating working days alone replaces the schedule and preserves the contract', function () {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $contract = exampleUserContract($user, ['weekly_hours' => 4]);
    $contract->workingDays()->create(['day_of_week' => 'monday', 'is_working_day' => true, 'start_time' => '09:00', 'end_time' => '18:00']);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['working_days' => [[
            'day_of_week' => 'tuesday', 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true,
        ]]])->assertOk()->assertJsonPath('data.contracts.0.working_days.1.day_of_week', 'tuesday');

    $this->assertDatabaseHas('contract_working_days', ['contract_id' => $contract->id, 'day_of_week' => 'monday', 'is_working_day' => false, 'start_time' => null, 'end_time' => null]);
    $this->assertDatabaseHas('contract_working_days', ['contract_id' => $contract->id, 'day_of_week' => 'tuesday']);
    expect($contract->fresh()->weekly_hours)->toBe(4);
});

test('multiple contracts require an explicit selection and roll back anagraphic changes', function () {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $first = exampleUserContract($user);
    $second = exampleUserContract($user, ['weekly_hours' => 24]);
    $before = $user->fresh()->getAttributes();
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->putJson('/api/users/'.$user->id, ['first_name' => 'Rollback', 'weekly_hours' => 20])
        ->assertUnprocessable()->assertJsonValidationErrors(['contract_id']);
    expect($user->fresh()->getAttributes())->toBe($before);
    expect($first->fresh()->weekly_hours)->toBe(40);
    expect($second->fresh()->weekly_hours)->toBe(24);

    $this->putJson('/api/users/'.$user->id, ['contract_id' => $second->id, 'weekly_hours' => 20, 'working_days' => contractSchedule()])->assertOk();
    expect($first->fresh()->weekly_hours)->toBe(40);
    expect($second->fresh()->weekly_hours)->toBe(20);
});

test('foreign and deleted contracts cannot be modified', function (bool $deleted) {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $owner = $deleted ? $user : User::factory()->create();
    $contract = exampleUserContract($owner);
    if ($deleted) {
        $contract->delete();
    }

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['contract_id' => $contract->id, 'weekly_hours' => 20])
        ->assertUnprocessable()->assertJsonValidationErrors(['contract_id']);
    expect($contract->fresh()->weekly_hours)->toBe(40);
})->with(['foreign' => false, 'deleted' => true]);

test('a first contract requires both start date and weekly hours', function () {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->putJson('/api/users/'.$user->id, ['weekly_hours' => 20])
        ->assertUnprocessable()->assertJsonValidationErrors(['contract_start_date']);
    $this->assertDatabaseCount('contracts', 0);
    $this->putJson('/api/users/'.$user->id, ['contract_start_date' => '2026-11-01', 'weekly_hours' => 20, 'working_days' => contractSchedule()])
        ->assertOk()->assertJsonPath('data.contracts.0.weekly_hours', 20);
    $this->assertDatabaseHas('contracts', ['user_id' => $user->id, 'weekly_hours' => 20]);
});

test('anagraphic updates leave multiple contracts untouched', function () {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $first = exampleUserContract($user);
    $second = exampleUserContract($user, ['weekly_hours' => 24]);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['first_name' => 'Aggiornato'])->assertOk();

    expect($first->fresh()->weekly_hours)->toBe(40);
    expect($second->fresh()->weekly_hours)->toBe(24);
});

test('effective contract dates cannot end before their start', function (array $changes) {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $contract = exampleUserContract($user);
    $before = $contract->fresh()->getAttributes();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors(['contract_end_date']);
    expect($contract->fresh()->getAttributes())->toBe($before);
})->with([
    'end before existing start' => [['contract_end_date' => '2025-12-31']],
    'start after existing end' => [['contract_start_date' => '2027-01-01']],
]);

test('duplicate and invalid weekdays return 422 without replacing existing schedules', function (?array $days, string $error) {
    $actor = contractAdministrator();
    $user = User::factory()->create();
    $contract = exampleUserContract($user);
    $day = $contract->workingDays()->create(['day_of_week' => 'friday', 'is_working_day' => false]);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['working_days' => $days])
        ->assertUnprocessable()->assertJsonValidationErrors([$error]);
    $this->assertModelExists($day);
    expect($contract->workingDays()->count())->toBe(1);
})->with([
    'duplicate' => [[['day_of_week' => 'monday'], ['day_of_week' => 'monday']], 'working_days.0.day_of_week'],
    'unknown weekday' => [[['day_of_week' => 'notaday']], 'working_days.0.day_of_week'],
    'null schedule' => [null, 'working_days'],
]);
test('creation saves the contract end date and rejects inconsistent dates before creating the user', function () {
    $actor = contractAdministrator();
    $payload = [
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'contract@example.com',
        'azure_email' => 'contract@azure.example.com', 'company_email' => 'contract@company.example.com',
        'birth_date' => '1995-01-01', 'hiring_date' => '2026-11-01',
        'filestorage_id' => $actor->filestorage_id, 'role_id' => $actor->roles()->firstOrFail()->id,
        'working_days' => contractSchedule(), 'contract_start_date' => '2026-11-01', 'weekly_hours' => 20, 'contract_end_date' => '2026-10-01',
    ];
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->postJson('/api/users', $payload)->assertUnprocessable()->assertJsonValidationErrors(['contract_end_date']);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('contracts', 0);
    $payload['contract_end_date'] = '2027-01-01';
    $response = $this->postJson('/api/users', $payload)->assertCreated();
    $contract = Contract::where('user_id', $response->json('data.id'))->firstOrFail();
    expect($contract->contract_end_date->toDateString())->toBe('2027-01-01');
});
