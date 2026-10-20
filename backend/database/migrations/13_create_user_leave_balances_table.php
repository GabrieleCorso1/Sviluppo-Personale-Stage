
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_leave_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('leave_type');//Forse enum

            $table->date('reference_month');

            $table->decimal('remaining_hours', 8, 2);
            $table->decimal('accrued_hours', 8, 2);
            $table->decimal('monthly_accrual', 8, 2);

            $table->timestamps();

            $table->unique([
                'user_id',
                'leave_type',
                'reference_month',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_leave_balances');
    }
};
