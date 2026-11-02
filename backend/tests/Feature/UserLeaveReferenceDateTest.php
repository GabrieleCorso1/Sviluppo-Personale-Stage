<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);
beforeEach(function () {
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::firstOrCreate(['name' => 'users.leaves.manage'], ['description' => 'Manage counters']));
    $actor->roles()->attach($role);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $this->target = User::factory()->create();
    $this->target->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40, 'has_rol' => true]);
    $this->path = '/api/users/'.$this->target->id.'/leaves';
});

test('permission and ROL preserve precise reference dates and keep only one balance per month', function (string $type) {
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => $type, 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-15', 'remaining_hours' => 8,
    ]]])->assertOk();
    $this->getJson($this->path.'?year=2026')->assertOk()
        ->assertJsonPath('months.0.balances.'.$type.'.reference_date', '2026-01-15');
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => $type, 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-20', 'remaining_hours' => 6,
    ]]])->assertOk();
    expect($this->target->leaveBalances()->count())->toBe(1);
    $balance = $this->target->leaveBalances()->sole();
    expect($balance->reference_month->toDateString())->toBe('2026-01-01');
    expect($balance->reference_date->toDateString())->toBe('2026-01-20');
    $this->getJson($this->path.'/history?year=2026')->assertOk()
        ->assertJsonPath('data.data.0.reference_date', '2026-01-20')
        ->assertJsonPath('data.data.0.previous_reference_date', '2026-01-15');
})->with(['permesso', 'rol']);

test('legacy callers retain the day submitted in reference_month for permission and ROL', function (string $type) {
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => $type, 'reference_month' => '2026-01-15', 'remaining_hours' => 8,
    ]]])->assertOk();
    expect($this->target->leaveBalances()->sole()->reference_date->toDateString())->toBe('2026-01-15');
})->with(['permesso', 'rol']);

test('changing only the reference day with unchanged amounts creates one audit entry and repeating it creates none', function () {
    $entry = ['leave_type' => 'permesso', 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-15', 'remaining_hours' => 8];
    $this->putJson($this->path, ['leaves' => [$entry]])->assertOk();
    $this->assertDatabaseCount('leave_audit_logs', 3);
    $entry['reference_date'] = '2026-01-20';
    $this->putJson($this->path, ['leaves' => [$entry]])->assertOk();
    $this->assertDatabaseCount('leave_audit_logs', 4);
    $this->getJson($this->path.'/history')->assertOk()
        ->assertJsonPath('data.data.0.field_name', 'reference_date')
        ->assertJsonPath('data.data.0.reference_date', '2026-01-20')
        ->assertJsonPath('data.data.0.previous_reference_date', '2026-01-15');
    $this->putJson($this->path, ['leaves' => [$entry]])->assertOk();
    $this->assertDatabaseCount('leave_audit_logs', 4);
});

test('holidays remain monthly and do not acquire a daily reference date', function () {
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => 'ferie', 'reference_month' => '2026-01-15', 'remaining_hours' => 8,
    ]]])->assertOk();
    $balance = $this->target->leaveBalances()->sole();
    expect($balance->reference_month->toDateString())->toBe('2026-01-01');
    expect($balance->reference_date)->toBeNull();
});

test('invalid precise reference dates reject the whole batch without writing balances or audit', function (array $entry) {
    $this->putJson($this->path, ['leaves' => [
        ['leave_type' => 'ferie', 'reference_month' => '2026-01-01', 'remaining_hours' => 8],
        $entry + ['reference_month' => '2026-01-01', 'remaining_hours' => 4],
    ]])->assertUnprocessable()->assertJsonValidationErrors('leaves.1.reference_date');
    $this->assertDatabaseCount('user_leave_balances', 0);
    $this->assertDatabaseCount('leave_audit_logs', 0);
})->with([
    [['leave_type' => 'permesso', 'reference_date' => '2026-02-01']],
    [['leave_type' => 'permesso', 'reference_date' => '2999-01-01']],
    [['leave_type' => 'permesso', 'reference_date' => null]],
    [['leave_type' => 'ferie', 'reference_date' => '2026-01-15', 'reference_month' => '2026-02-01']],
]);

test('ROL entitlement uses the precise day even when the contract starts part way through the month', function () {
    $this->target->contracts()->update(['contract_start_date' => '2026-01-10']);
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => 'rol', 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-15', 'remaining_hours' => 8,
    ]]])->assertOk();
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => 'rol', 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-09', 'remaining_hours' => 9,
    ]]])->assertUnprocessable();
    expect($this->target->leaveBalances()->sole()->remaining_hours)->toBe('8.00');
    expect($this->target->leaveBalances()->sole()->reference_date->toDateString())->toBe('2026-01-15');
});

test('partial counter changes preserve the saved reference day and do not add spurious date history', function (string $type) {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => $type, 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-15',
        'remaining_hours' => 8, 'accrued_hours' => 12, 'monthly_accrual' => 2,
    ]]])->assertOk();

    $partial = ['leaves' => [[
        'leave_type' => $type, 'reference_month' => '2026-01-01', 'remaining_hours' => 6,
    ]]];
    $this->putJson($this->path, $partial)->assertOk();

    $balance = $this->target->leaveBalances()->sole();
    expect($balance->reference_date->toDateString())->toBe('2026-01-15');
    expect($balance->remaining_hours)->toBe('6.00');
    expect($balance->accrued_hours)->toBe('12.00');
    expect($balance->monthly_accrual)->toBe('2.00');
    $this->getJson($this->path.'/history')->assertOk()
        ->assertJsonPath('data.data.0.field_name', 'remaining_hours')
        ->assertJsonPath('data.data.0.reference_date', '2026-01-15')
        ->assertJsonPath('data.data.0.previous_reference_date', '2026-01-15');
    $this->assertDatabaseCount('leave_audit_logs', 4);
    $this->putJson($this->path, $partial)->assertOk();
    $this->assertDatabaseCount('leave_audit_logs', 4);
})->with(['permesso', 'rol']);

test('partial ROL changes use the saved day when the contract starts in the middle of the month', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $this->target->contracts()->update(['contract_start_date' => '2026-01-10']);
    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => 'rol', 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-15', 'remaining_hours' => 8,
    ]]])->assertOk();

    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => 'rol', 'reference_month' => '2026-01-01', 'remaining_hours' => 6,
    ]]])->assertOk();

    $balance = $this->target->leaveBalances()->sole();
    expect($balance->remaining_hours)->toBe('6.00');
    expect($balance->reference_date->toDateString())->toBe('2026-01-15');
});

test('legacy balances without a precise date use the submitted reference day', function (string $type) {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $balance = $this->target->leaveBalances()->create([
        'leave_type' => $type, 'reference_month' => '2026-01-01', 'reference_date' => null,
        'remaining_hours' => 8, 'accrued_hours' => 0, 'monthly_accrual' => 0,
    ]);

    $this->putJson($this->path, ['leaves' => [[
        'leave_type' => $type, 'reference_month' => '2026-01-20', 'remaining_hours' => 6,
    ]]])->assertOk();

    expect($balance->fresh()->reference_date->toDateString())->toBe('2026-01-20');
    expect($balance->fresh()->remaining_hours)->toBe('6.00');
    $this->assertDatabaseCount('user_leave_balances', 1);
})->with(['permesso', 'rol']);

test('an invalid explicit ROL date rolls back other partial counter changes in the batch', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $this->target->contracts()->update(['contract_start_date' => '2026-01-10']);
    foreach (['permesso', 'rol'] as $type) {
        $this->putJson($this->path, ['leaves' => [[
            'leave_type' => $type, 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-15', 'remaining_hours' => 8,
        ]]])->assertOk();
    }

    $this->putJson($this->path, ['leaves' => [
        ['leave_type' => 'permesso', 'reference_month' => '2026-01-01', 'remaining_hours' => 6],
        ['leave_type' => 'rol', 'reference_month' => '2026-01-01', 'reference_date' => '2026-01-09', 'remaining_hours' => 6],
    ]])->assertUnprocessable()->assertJsonValidationErrors('leaves.1.leave_type');

    foreach ($this->target->leaveBalances()->get() as $balance) {
        expect($balance->remaining_hours)->toBe('8.00');
        expect($balance->reference_date->toDateString())->toBe('2026-01-15');
    }
    $this->assertDatabaseCount('leave_audit_logs', 6);
});
