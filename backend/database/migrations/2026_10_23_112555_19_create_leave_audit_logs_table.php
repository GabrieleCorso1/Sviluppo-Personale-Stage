<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();

            $table->string('leave_type');
            $table->date('reference_month');

            $table->string('field_name'); // 'remaining_hours' o 'monthly_accrual'
            $table->decimal('old_value', 8, 2)->nullable(); // Valore precedente
            $table->decimal('new_value', 8, 2)->nullable(); // Nuovo valore salvato

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_audit_logs');
    }
};
