<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Schema rebuilds must run outside a test transaction so SQLite can preserve foreign keys.
uses(DatabaseMigrations::class);

test('develop schema repair preserves existing contracts and balances and supports optional emails', function () {
    Schema::table('users', function ($table) {
        $table->string('azure_email')->nullable(false)->change();
        $table->string('company_email')->nullable(false)->change();
    });
    Schema::table('contracts', fn ($table) => $table->dropColumn('has_rol'));
    Schema::table('user_leave_balances', fn ($table) => $table->dropColumn('reference_date'));
    Schema::table('leave_audit_logs', fn ($table) => $table->dropColumn(['reference_date', 'previous_reference_date']));
    $user = User::factory()->create();
    $contractId = DB::table('contracts')->insertGetId(['user_id' => $user->id, 'contract_start_date' => '2026-01-01', 'weekly_hours' => 40]);
    $balanceId = DB::table('user_leave_balances')->insertGetId([
        'user_id' => $user->id, 'leave_type' => 'ferie', 'reference_month' => '2026-10-01',
        'remaining_hours' => 8, 'accrued_hours' => 16, 'monthly_accrual' => 2,
    ]);
    $migration = require database_path('migrations/2026_11_02_142921_align_existing_user_and_leave_schema.php');

    $migration->up();

    $this->assertModelExists($user);
    $this->assertDatabaseHas('contracts', ['id' => $contractId, 'user_id' => $user->id, 'weekly_hours' => 40, 'has_rol' => false]);
    $this->assertDatabaseHas('user_leave_balances', ['id' => $balanceId, 'remaining_hours' => 8, 'reference_date' => null]);
    expect(Schema::hasColumns('leave_audit_logs', ['reference_date', 'previous_reference_date']))->toBeTrue();
    $user->update(['azure_email' => null, 'company_email' => null]);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'azure_email' => null, 'company_email' => null]);
    DB::table('contracts')->where('id', $contractId)->update(['has_rol' => true]);
    DB::table('user_leave_balances')->where('id', $balanceId)->update(['reference_date' => '2026-10-08']);
    $migration->up();
    $this->assertDatabaseHas('contracts', ['id' => $contractId, 'has_rol' => true]);
    $this->assertDatabaseHas('user_leave_balances', ['id' => $balanceId, 'remaining_hours' => 8, 'reference_date' => '2026-10-08']);
});
