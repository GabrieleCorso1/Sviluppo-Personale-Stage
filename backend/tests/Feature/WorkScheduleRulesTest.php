<?php

use App\Models\Contract;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\WorkSchedule;
use Database\Seeders\ContractWorkingDaySeeder;
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
});

test('the break is subtracted only when a work interval exceeds six hours', function (string $start, string $end, int $netMinutes) {
    expect(WorkSchedule::workedMinutes($start, $end))->toBe($netMinutes);
})->with([
    ['09:00', '13:00', 240],
    ['10:00', '16:00', 360],
    ['10:00', '16:01', 301],
    ['10:00', '18:00', 420],
    ['09:00', '18:00', 480],
]);

test('weekends cannot become working days and rejected updates preserve the previous schedule', function (string $day) {
    $user = User::factory()->create();
    $contract = $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    foreach (WorkSchedule::fullTime() as $entry) $contract->workingDays()->create($entry);
    $before = $contract->workingDays()->get()->toArray();
    $this->putJson('/api/users/'.$user->id, [
        'first_name' => 'Rollback', 'weekly_hours' => 4,
        'working_days' => [['day_of_week' => $day, 'is_working_day' => true, 'start_time' => '09:00', 'end_time' => '13:00']],
    ])->assertUnprocessable()->assertJsonValidationErrors('working_days.0.is_working_day');
    expect($user->fresh()->first_name)->toBe($user->first_name);
    expect($contract->fresh()->weekly_hours)->toBe(40);
    expect($contract->workingDays()->get()->toArray())->toBe($before);
})->with(['saturday', 'sunday']);

test('weekly hours outside the agreed integer range fail in both creation and update', function ($hours) {
    $user = User::factory()->create();
    $this->putJson('/api/users/'.$user->id, ['contract_start_date' => '2026-01-01', 'weekly_hours' => $hours])
        ->assertUnprocessable()->assertJsonValidationErrors('weekly_hours');
    $this->postJson('/api/users', [
        'first_name' => 'Invalid', 'last_name' => 'Hours', 'email' => 'invalidhours@example.com',
        'azure_email' => 'invalidhours@azure.example.com', 'company_email' => 'invalidhours@company.example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-01',
        'filestorage_id' => $this->actor->filestorage_id, 'role_id' => $this->actor->roles()->firstOrFail()->id,
        'contract_start_date' => '2026-01-01', 'weekly_hours' => $hours,
    ])->assertUnprocessable()->assertJsonValidationErrors('weekly_hours');
    $this->assertDatabaseMissing('users', ['email' => 'invalidhours@example.com']);
    expect($user->contracts()->count())->toBe(0);
})->with([0, 41, 45, 20.5]);

test('long intervals must match net hours after the break rather than elapsed hours', function () {
    $user = User::factory()->create();
    $days = [['day_of_week' => 'monday', 'is_working_day' => true, 'start_time' => '10:00', 'end_time' => '18:00']];
    $this->putJson('/api/users/'.$user->id, [
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 8, 'working_days' => $days,
    ])->assertUnprocessable()->assertJsonValidationErrors('working_days');
    expect($user->contracts()->count())->toBe(0);
    $this->putJson('/api/users/'.$user->id, [
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 7, 'working_days' => $days,
    ])->assertOk();
});

test('the 24 hour seeder replaces the old weekend pattern repeatably with three full weekdays', function () {
    $user = User::factory()->create();
    $contract = $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 24]);
    $contract->workingDays()->create(['day_of_week' => 'saturday', 'is_working_day' => true, 'start_time' => '09:00', 'end_time' => '13:00']);
    $this->seed(ContractWorkingDaySeeder::class);
    $this->seed(ContractWorkingDaySeeder::class);
    $days = $contract->workingDays()->get();
    expect($days)->toHaveCount(7);
    expect($days->where('is_working_day', true)->pluck('day_of_week')->all())->toBe(['monday', 'wednesday', 'friday']);
    expect($days->firstWhere('day_of_week', 'saturday')->is_working_day)->toBeFalse();
    expect($days->firstWhere('day_of_week', 'saturday')->start_time)->toBeNull();
    expect(WorkSchedule::validate($days->toArray(), 24))->toHaveCount(7);
});
