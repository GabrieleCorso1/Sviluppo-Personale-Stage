<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function leaveAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $role->permissions()->attach(Permission::where('name', 'users.leaves.manage')->firstOrFail());
    $actor->roles()->attach($role);

    return $actor;
}

function leaveEntry(array $values = []): array
{
    return $values + ['leave_type' => 'ferie', 'reference_month' => '2026-01-01', 'remaining_hours' => 16, 'accrued_hours' => 8, 'monthly_accrual' => 4];
}

test('annual leave view contains twelve months and distinguishes missing data from zero', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $user->leaveBalances()->create(leaveEntry(['remaining_hours' => 0]));
    $user->leaveBalances()->create(leaveEntry(['reference_month' => '2025-01-01']));

    $this->withToken($actor->createToken('test')->plainTextToken)->getJson('/api/users/'.$user->id.'/leaves?year=2026')
        ->assertOk()->assertJsonPath('unit', 'hours')->assertJsonCount(12, 'months')->assertJsonCount(1, 'data')
        ->assertJsonPath('months.0.balances.ferie.remaining_hours', '0.00')
        ->assertJsonPath('months.0.balances.permesso', null)->assertJsonPath('months.1.balances.ferie', null);
});

test('updates normalize months preserve omitted amounts and record only actual changes', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken);
    $path = '/api/users/'.$user->id.'/leaves';
    $this->putJson($path, ['leaves' => [leaveEntry(['reference_month' => '2026-01-15'])]])->assertOk();
    $this->assertDatabaseCount('user_leave_balances', 1);
    $this->assertDatabaseCount('leave_audit_logs', 3);
    $this->assertDatabaseHas('leave_audit_logs', ['user_id' => $user->id, 'admin_id' => $actor->id, 'field_name' => 'remaining_hours', 'old_value' => null, 'new_value' => 16]);

    $this->putJson($path, ['leaves' => [['leave_type' => 'ferie', 'reference_month' => '2026-01-20', 'remaining_hours' => 12]]])->assertOk();
    $balance = $user->leaveBalances()->firstOrFail();
    expect($balance->reference_month->toDateString())->toBe('2026-01-01');
    expect($balance->accrued_hours)->toBe('8.00');
    expect($balance->monthly_accrual)->toBe('4.00');
    $this->assertDatabaseCount('user_leave_balances', 1);
    $this->assertDatabaseCount('leave_audit_logs', 4);
    $this->assertDatabaseHas('leave_audit_logs', ['field_name' => 'remaining_hours', 'old_value' => 16, 'new_value' => 12]);
    $this->putJson($path, ['leaves' => [['leave_type' => 'ferie', 'reference_month' => '2026-01-01', 'remaining_hours' => '12.00']]])->assertOk();
    $this->assertDatabaseCount('leave_audit_logs', 4);
});

test('new partial counters initialize omitted values and keep leave types separate', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)->putJson('/api/users/'.$user->id.'/leaves', ['leaves' => [
        ['leave_type' => 'permesso', 'reference_month' => '2026-01-01', 'remaining_hours' => 4],
        ['leave_type' => 'rol', 'reference_month' => '2026-01-01', 'remaining_hours' => 5, 'accrued_hours' => 10],
    ]])->assertOk();
    $this->assertDatabaseHas('user_leave_balances', ['user_id' => $user->id, 'leave_type' => 'permesso', 'remaining_hours' => 4, 'accrued_hours' => 0, 'monthly_accrual' => 0]);
    $this->assertDatabaseHas('user_leave_balances', ['user_id' => $user->id, 'leave_type' => 'rol', 'remaining_hours' => 5, 'accrued_hours' => 10]);
});

test('invalid counter batches return 422 without saving balances or history', function (array $entries, string $field) {
    $this->travelTo(now()->setDate(2026, 11, 2));
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id.'/leaves', ['leaves' => $entries])
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    $this->assertDatabaseCount('user_leave_balances', 0);
    $this->assertDatabaseCount('leave_audit_logs', 0);
})->with([
    'same month twice' => [[leaveEntry(), leaveEntry(['reference_month' => '2026-01-15'])], 'leaves.1.reference_month'],
    'negative' => [[leaveEntry(['remaining_hours' => -1])], 'leaves.0.remaining_hours'],
    'too much precision' => [[leaveEntry(['remaining_hours' => 1.234])], 'leaves.0.remaining_hours'],
    'overflow' => [[leaveEntry(['remaining_hours' => 1000000])], 'leaves.0.remaining_hours'],
    'future month' => [[leaveEntry(['reference_month' => '2026-12-01'])], 'leaves.0.reference_month'],
    'unknown type' => [[leaveEntry(['leave_type' => 'other'])], 'leaves.0.leave_type'],
    'no values' => [[['leave_type' => 'ferie', 'reference_month' => '2026-01-01']], 'leaves.0'],
    'unexpected days input' => [[leaveEntry(['remaining_days' => 2])], 'leaves.0'],
]);

test('history is scoped filtered paginated and exposes the editor without credentials', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->putJson('/api/users/'.$user->id.'/leaves', ['leaves' => [leaveEntry()]])->assertOk();
    $this->putJson('/api/users/'.$other->id.'/leaves', ['leaves' => [leaveEntry()]])->assertOk();
    $this->getJson('/api/users/'.$user->id.'/leaves/history?year=2026&leave_type=ferie&per_page=1')
        ->assertOk()->assertJsonPath('data.total', 3)->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.admin.id', $actor->id)->assertJsonMissingPath('data.data.0.admin.email');
});

test('full time conversion uses eight hours per day without changing balances', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->getJson('/api/users/'.$user->id.'/leaves/conversion?date=2026-01-05&days=2')
        ->assertOk()->assertJsonPath('data.hours', 16)->assertJsonPath('data.hours_per_day', 8);
    $this->getJson('/api/users/'.$user->id.'/leaves/conversion?date=2026-01-05&hours=12')
        ->assertOk()->assertJsonPath('data.days', 1.5);
    $this->assertDatabaseCount('user_leave_balances', 0);
    $this->assertDatabaseCount('leave_audit_logs', 0);
});

test('part time conversion depends on weekday and includes the agreed break', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $contract = $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 12]);
    $contract->workingDays()->create(['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true]);
    $contract->workingDays()->create(['day_of_week' => 'tuesday', 'start_time' => '09:00', 'end_time' => '18:00', 'is_working_day' => true]);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->getJson('/api/users/'.$user->id.'/leaves/conversion?date=2026-01-05&days=1')->assertOk()->assertJsonPath('data.hours', 4);
    $this->getJson('/api/users/'.$user->id.'/leaves/conversion?date=2026-01-06&days=1')->assertOk()->assertJsonPath('data.hours', 8);
    $this->getJson('/api/users/'.$user->id.'/leaves/conversion?date=2026-01-07&hours=4')
        ->assertUnprocessable()->assertJsonValidationErrors(['date']);
});

test('conversion selects contracts by date and rejects ambiguous foreign or expired choices', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $old = $user->contracts()->create(['contract_start_date' => '2025-01-01', 'contract_end_date' => '2025-12-31', 'weekly_hours' => 40]);
    $current = $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $path = '/api/users/'.$user->id.'/leaves/conversion?date=2026-01-05&hours=8';
    $this->getJson($path)->assertOk()->assertJsonPath('data.contract_id', $current->id);
    $this->getJson($path.'&contract_id='.$old->id)->assertUnprocessable()->assertJsonValidationErrors(['contract_id']);
    $user->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $this->getJson($path)->assertUnprocessable()->assertJsonValidationErrors(['contract_id']);
    $this->getJson($path.'&contract_id='.$current->id)->assertOk();
    $foreign = $actor->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $this->getJson($path.'&contract_id='.$foreign->id)->assertUnprocessable()->assertJsonValidationErrors(['contract_id']);
});

test('conversion requires a date and exactly one amount', function (string $query, string $error) {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)->getJson('/api/users/'.$user->id.'/leaves/conversion?'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors([$error]);
})->with([
    ['hours=8', 'date'], ['date=2026-01-05', 'hours'],
    ['date=2026-01-05&hours=8&days=1', 'hours'], ['date=2026-01-05&hours=-1', 'hours'],
]);

test('leave views and conversion require management permission', function (string $suffix) {
    $user = User::factory()->create();
    $this->getJson('/api/users/'.$user->id.'/leaves'.$suffix)->assertUnauthorized();
    $actor = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users/'.$user->id.'/leaves'.$suffix)->assertForbidden();
})->with(['', '/history', '/conversion?date=2026-01-05&hours=8']);
test('annual full time equivalents are shown only for an unambiguous full time month', function () {
    $actor = leaveAdministrator();
    $user = User::factory()->create();
    $user->contracts()->create(['contract_start_date' => '2026-01-01', 'contract_end_date' => '2026-01-31', 'weekly_hours' => 40]);
    $user->contracts()->create(['contract_start_date' => '2026-02-01', 'weekly_hours' => 20]);
    $user->leaveBalances()->create(leaveEntry());
    $user->leaveBalances()->create(leaveEntry(['reference_month' => '2026-02-01']));
    $this->withToken($actor->createToken('test')->plainTextToken)->getJson('/api/users/'.$user->id.'/leaves?year=2026')
        ->assertOk()->assertJsonPath('months.0.full_time_vacation_days.remaining_days', 2)
        ->assertJsonPath('months.1.full_time_vacation_days', null);
});
