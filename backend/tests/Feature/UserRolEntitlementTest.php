<?php

use App\Models\Contract;
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
    foreach (['users.manage', 'users.view', 'users.leaves.manage'] as $permission) {
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
    }
    $this->actor->roles()->attach($role);
    $this->withToken($this->actor->createToken('test')->plainTextToken);
    $this->target = User::factory()->create();
});

test('ROL entitlement defaults to false and can be enabled or disabled independently', function () {
    $contract = $this->target->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    expect($contract->fresh()->has_rol)->toBeFalse();
    $path = '/api/users/'.$this->target->id;
    $this->putJson($path, ['contract_id' => $contract->id, 'has_rol' => true])->assertOk()
        ->assertJsonPath('data.contracts.0.has_rol', true);
    $this->putJson($path, ['first_name' => 'Changed'])->assertOk();
    expect($contract->fresh()->has_rol)->toBeTrue();
    $this->putJson($path, ['has_rol' => false])->assertOk();
    expect($contract->fresh()->has_rol)->toBeFalse();
});

test('ROL updates require one eligible contract and preserve existing history when rejected', function (string $scenario) {
    if ($scenario !== 'none') {
        $contract = $this->target->contracts()->create([
            'contract_start_date' => $scenario === 'future' ? '2026-02-01' : '2026-01-01',
            'contract_end_date' => $scenario === 'expired' ? '2026-01-14' : null,
            'weekly_hours' => 40, 'has_rol' => $scenario !== 'disabled',
        ]);
        if ($scenario === 'deleted') $contract->delete();
        if ($scenario === 'ambiguous') {
            $this->target->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40, 'has_rol' => true]);
        }
    }
    $balance = $this->target->leaveBalances()->create([
        'leave_type' => 'rol', 'reference_month' => '2026-01-01',
        'remaining_hours' => 3, 'accrued_hours' => 5, 'monthly_accrual' => 1,
    ]);
    $this->putJson('/api/users/'.$this->target->id.'/leaves', ['leaves' => [
        ['leave_type' => 'ferie', 'reference_month' => '2026-01-15', 'remaining_hours' => 10],
        ['leave_type' => 'rol', 'reference_month' => '2026-01-15', 'remaining_hours' => 20],
    ]])->assertUnprocessable()->assertJsonValidationErrors('leaves.1.leave_type');
    expect($balance->fresh()->remaining_hours)->toBe('3.00');
    $this->assertDatabaseCount('user_leave_balances', 1);
    $this->assertDatabaseCount('leave_audit_logs', 0);
    $this->getJson('/api/users/'.$this->target->id.'/leaves?year=2026')
        ->assertOk()->assertJsonPath('months.0.balances.rol.remaining_hours', '3.00');
})->with(['none', 'disabled', 'expired', 'future', 'deleted', 'ambiguous']);

test('ROL eligibility follows the reference date and contract boundaries rather than the current contract', function () {
    $old = $this->target->contracts()->create([
        'contract_start_date' => '2025-01-01', 'contract_end_date' => '2025-12-31',
        'weekly_hours' => 40, 'has_rol' => true,
    ]);
    $this->target->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40, 'has_rol' => false]);
    $this->putJson('/api/users/'.$this->target->id.'/leaves', ['leaves' => [
        ['leave_type' => 'rol', 'reference_month' => '2025-01-01', 'remaining_hours' => 4],
        ['leave_type' => 'rol', 'reference_month' => '2025-12-31', 'remaining_hours' => 8],
    ]])->assertOk();
    $this->assertDatabaseCount('leave_audit_logs', 6);
    $this->getJson('/api/users/'.$this->target->id.'/leaves?year=2025')
        ->assertOk()->assertJsonPath('rol_entitlements.0.contract_id', $old->id)
        ->assertJsonPath('rol_entitlements.0.has_rol', true);
});

test('disabling entitlement preserves historical ROL balances and audit records', function () {
    $contract = $this->target->contracts()->create(['contract_start_date' => '2026-01-01', 'weekly_hours' => 40, 'has_rol' => true]);
    $path = '/api/users/'.$this->target->id;
    $entry = ['leave_type' => 'rol', 'reference_month' => '2026-01-01', 'remaining_hours' => 5];
    $this->putJson($path.'/leaves', ['leaves' => [$entry]])->assertOk();
    $this->putJson($path, ['contract_id' => $contract->id, 'has_rol' => false])->assertOk();
    $this->getJson($path.'/leaves?year=2026')->assertOk()
        ->assertJsonPath('months.0.balances.rol.remaining_hours', '5.00');
    $this->assertDatabaseCount('leave_audit_logs', 3);
    $this->putJson($path.'/leaves', ['leaves' => [$entry]])->assertUnprocessable();
    $this->assertDatabaseCount('leave_audit_logs', 3);
});

test('holidays and other leave do not require ROL entitlement or a contract', function () {
    $this->putJson('/api/users/'.$this->target->id.'/leaves', ['leaves' => [
        ['leave_type' => 'ferie', 'reference_month' => '2026-01-01', 'remaining_hours' => 5],
        ['leave_type' => 'permesso', 'reference_month' => '2026-01-01', 'remaining_hours' => 3],
    ]])->assertOk();
});

test('ROL entitlement can be specified at creation but must be boolean', function () {
    $payload = [
        'first_name' => 'ROL', 'last_name' => 'Test', 'email' => 'rol@example.com',
        'azure_email' => 'rol@azure.example.com', 'company_email' => 'rol@company.example.com',
        'birth_date' => '1990-01-01', 'hiring_date' => '2026-01-01',
        'role_id' => $this->actor->roles()->firstOrFail()->id, 'filestorage_id' => $this->actor->filestorage_id,
        'contract_start_date' => '2026-01-01', 'weekly_hours' => 40,
    ];
    $this->postJson('/api/users', $payload + ['has_rol' => 'yes'])
        ->assertUnprocessable()->assertJsonValidationErrors('has_rol');
    $this->postJson('/api/users', $payload + ['has_rol' => true])
        ->assertCreated()->assertJsonPath('data.contracts.0.has_rol', true);
});
