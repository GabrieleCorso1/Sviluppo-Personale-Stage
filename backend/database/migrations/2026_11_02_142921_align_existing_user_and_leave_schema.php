<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $columns = collect(Schema::getColumns('users'))->keyBy('name');
        Schema::table('users', function (Blueprint $table) use ($columns) {
            foreach (['azure_email', 'company_email'] as $name) {
                if (! $columns[$name]['nullable']) {
                    $table->string($name)->nullable()->change();
                }
            }
        });

        if (! Schema::hasColumn('contracts', 'has_rol')) {
            Schema::table('contracts', fn (Blueprint $table) => $table->boolean('has_rol')->default(false));
        }
        if (! Schema::hasColumn('user_leave_balances', 'reference_date')) {
            Schema::table('user_leave_balances', fn (Blueprint $table) => $table->date('reference_date')->nullable());
        }
        foreach (['reference_date', 'previous_reference_date'] as $name) {
            if (! Schema::hasColumn('leave_audit_logs', $name)) {
                Schema::table('leave_audit_logs', fn (Blueprint $table) => $table->date($name)->nullable());
            }
        }
    }

    public function down(): void
    {
        // The original migrations now define these columns. Keep existing entitlements, reference dates and optional emails on rollback.
    }
};
