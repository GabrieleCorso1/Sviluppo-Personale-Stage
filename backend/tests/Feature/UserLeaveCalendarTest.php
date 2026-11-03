<?php

use App\Models\LeaveAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

function calendarLeaveAdministrator(): User
{
    $admin = User::factory()->create();
    $role = Role::firstOrCreate(['alias' => 'Admin']);
    $permission = Permission::firstOrCreate(['name' => 'users.leaves.manage'], ['description' => 'Manage counters']);
    $role->permissions()->attach($permission);
    $admin->roles()->attach($role);

    return $admin;
}

test('annual balances and history follow the Italian year on both sides of new year', function (string $instant, int $year) {
    $this->travelTo(Carbon::parse($instant, 'UTC'));
    $admin = calendarLeaveAdministrator();
    $user = User::factory()->create();
    foreach ([$year - 1, $year] as $entryYear) {
        $user->leaveBalances()->create([
            'leave_type' => 'ferie', 'reference_month' => $entryYear.'-01-01',
            'remaining_hours' => 8, 'accrued_hours' => 0, 'monthly_accrual' => 0,
        ]);
        LeaveAuditLog::create([
            'user_id' => $user->id, 'admin_id' => $admin->id, 'leave_type' => 'ferie',
            'reference_month' => $entryYear.'-01-01', 'field_name' => 'remaining_hours',
            'old_value' => 0, 'new_value' => 8,
        ]);
    }
    $this->withToken($admin->createToken('test')->plainTextToken);
    $path = '/api/users/'.$user->id.'/leaves';

    $this->getJson($path)->assertOk()->assertJsonPath('year', $year)->assertJsonCount(1, 'data')
        ->assertJsonPath('months.0.reference_month', $year.'-01-01');
    $this->getJson($path.'?year='.$year)->assertOk()->assertJsonPath('year', $year)->assertJsonCount(1, 'data');
    $this->getJson($path.'/history?year='.$year)->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson($path.'/history?year='.($year - 1))->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson($path.'/history')->assertOk()->assertJsonPath('data.total', 2);
    $this->getJson($path.'?year='.($year + 1))->assertUnprocessable()->assertJsonValidationErrors('year');
    $this->getJson($path.'/history?year='.($year + 1))->assertUnprocessable()->assertJsonValidationErrors('year');
})->with([
    'before midnight in Rome' => ['2026-12-31 22:59:59', 2026],
    'midnight in Rome while UTC is still December' => ['2026-12-31 23:00:00', 2027],
    'both calendars in the new year' => ['2027-01-01 00:00:00', 2027],
]);

test('counters saved just after Italian new year are immediately visible in the annual view and history', function () {
    $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
    $admin = calendarLeaveAdministrator();
    $user = User::factory()->create();
    $path = '/api/users/'.$user->id.'/leaves';
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->putJson($path, ['leaves' => [[
        'leave_type' => 'ferie', 'reference_month' => '2027-01-01', 'remaining_hours' => 8,
    ]]])->assertOk();

    $this->getJson($path)->assertOk()->assertJsonPath('year', 2027)
        ->assertJsonPath('months.0.balances.ferie.remaining_hours', '8.00');
    $this->getJson($path.'/history?year=2027')->assertOk()->assertJsonPath('data.total', 3);
    $this->assertDatabaseHas('user_leave_balances', ['user_id' => $user->id, 'remaining_hours' => 8]);
});

test('full-time conversion rejects weekends for both hours and days', function (string $date, string $amount) {
    $admin = calendarLeaveAdministrator();
    $user = User::factory()->create();
    $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $this->withToken($admin->createToken('test')->plainTextToken)
        ->getJson('/api/users/'.$user->id.'/leaves/conversion?date='.$date.'&'.$amount.'=1')
        ->assertUnprocessable()->assertJsonValidationErrors('date');
})->with([
    ['2026-11-07', 'days'], ['2026-11-07', 'hours'],
    ['2026-11-08', 'days'], ['2026-11-08', 'hours'],
]);

test('full-time conversion still converts working days in both directions', function () {
    $admin = calendarLeaveAdministrator();
    $user = User::factory()->create();
    $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $this->withToken($admin->createToken('test')->plainTextToken);
    $url = '/api/users/'.$user->id.'/leaves/conversion?date=2026-11-09';
    $this->getJson($url.'&days=1')->assertOk()->assertJsonPath('data.hours', 8);
    $this->getJson($url.'&hours=4')->assertOk()->assertJsonPath('data.days', 0.5);
});
