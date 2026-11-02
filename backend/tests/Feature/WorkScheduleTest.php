<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\WorkSchedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function scheduleAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $actor->roles()->attach($role);

    return $actor;
}

test('part time subtracts a one hour break only for intervals longer than six hours', function (array $days, int $hours) {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, [
            'contract_start_date' => '2026-11-01', 'weekly_hours' => $hours, 'working_days' => $days,
        ])->assertOk();

    $this->assertDatabaseHas('contracts', ['user_id' => $user->id, 'weekly_hours' => $hours]);
    $response->assertJsonCount(7, 'data.contracts.0.working_days');
})->with([
    'one full day in a part time week' => [[['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '18:00', 'is_working_day' => true]], 8],
    'morning only' => [[['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true]], 4],
    'afternoon only' => [[['day_of_week' => 'monday', 'start_time' => '14:00', 'end_time' => '18:00', 'is_working_day' => true]], 4],
    'other long interval includes a break' => [[['day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '18:00', 'is_working_day' => true]], 7],
    'fractional hours add precisely' => [[
        ['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '13:30', 'is_working_day' => true],
        ['day_of_week' => 'tuesday', 'start_time' => '09:00', 'end_time' => '12:30', 'is_working_day' => true],
    ], 8],
]);

test('40 hour contracts get the fixed schedule automatically', function () {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['contract_start_date' => '2026-11-01', 'weekly_hours' => 40])
        ->assertOk()->assertJsonCount(7, 'data.contracts.0.working_days');
    $days = $response->json('data.contracts.0.working_days');
    expect(collect($days)->where('is_working_day', true)->count())->toBe(5);
    expect($days[0]['day_of_week'])->toBe('monday');
    expect(substr($days[0]['start_time'], 0, 5))->toBe('09:00');
    expect(substr($days[0]['end_time'], 0, 5))->toBe('18:00');
    expect($days[5]['is_working_day'])->toBeFalse();
    expect($days[5]['start_time'])->toBeNull();
});

test('a matching full time schedule may be resubmitted but cannot be customized', function () {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $contract = $user->contracts()->create(['contract_start_date' => '2026-11-01', 'weekly_hours' => 40]);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->putJson('/api/users/'.$user->id, ['working_days' => WorkSchedule::fullTime()])->assertOk();
    $days = WorkSchedule::fullTime();
    $days[0]['start_time'] = '10:00';
    $this->putJson('/api/users/'.$user->id, ['working_days' => $days])->assertUnprocessable()
        ->assertJsonValidationErrors(['working_days']);
    expect(substr($contract->workingDays()->firstOrFail()->start_time, 0, 5))->toBe('09:00');
});

test('nonworking days contribute zero and are saved without times', function () {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, [
            'contract_start_date' => '2026-11-01', 'weekly_hours' => 4,
            'working_days' => [
                ['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true],
                ['day_of_week' => 'tuesday', 'start_time' => '09:00', 'end_time' => '18:00', 'is_working_day' => false],
            ],
        ])->assertOk()->assertJsonPath('data.contracts.0.working_days.1.start_time', null)
        ->assertJsonPath('data.contracts.0.working_days.1.end_time', null);
});

test('invalid schedules return 422 and roll back the user and contract changes', function (array $day, int $hours, string $error) {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, [
            'first_name' => 'Rollback', 'contract_start_date' => '2026-11-01',
            'weekly_hours' => $hours, 'working_days' => [$day],
        ])->assertUnprocessable()->assertJsonValidationErrors([$error]);
    expect($user->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('contracts', 0);
})->with([
    'wrong total' => [['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true], 20, 'working_days'],
    '09 to 18 is not nine hours' => [['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '18:00', 'is_working_day' => true], 9, 'working_days'],
    'end before start' => [['day_of_week' => 'monday', 'start_time' => '18:00', 'end_time' => '09:00', 'is_working_day' => true], 8, 'working_days.0.end_time'],
    'equal times' => [['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '09:00', 'is_working_day' => true], 8, 'working_days.0.end_time'],
    'missing end' => [['day_of_week' => 'monday', 'start_time' => '09:00', 'is_working_day' => true], 8, 'working_days.0.start_time'],
    'invalid time' => [['day_of_week' => 'monday', 'start_time' => '25:00', 'end_time' => '18:00', 'is_working_day' => true], 8, 'working_days.0.start_time'],
]);

test('changing weekly hours validates the stored schedule when no replacement is provided', function () {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $contract = $user->contracts()->create(['contract_start_date' => '2026-11-01', 'weekly_hours' => 4]);
    $contract->workingDays()->create(['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true]);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->putJson('/api/users/'.$user->id, ['weekly_hours' => 8])->assertUnprocessable()->assertJsonValidationErrors(['working_days']);
    expect($contract->fresh()->weekly_hours)->toBe(4);
    $this->putJson('/api/users/'.$user->id, ['weekly_hours' => 4])->assertOk();
    $this->putJson('/api/users/'.$user->id, ['weekly_hours' => 40])->assertOk();
    expect($contract->workingDays()->where('is_working_day', true)->count())->toBe(5);
});

test('new part time contracts require a matching schedule', function () {
    $actor = scheduleAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['contract_start_date' => '2026-11-01', 'weekly_hours' => 20])
        ->assertUnprocessable()->assertJsonValidationErrors(['working_days']);
    $this->assertDatabaseCount('contracts', 0);
});
