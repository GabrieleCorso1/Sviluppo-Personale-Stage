<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_working_days', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contract_id')
                ->constrained('contracts')
                ->cascadeOnDelete();

            $table->string('day_of_week', 10);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_working_day')->default(false);

            $table->timestamps();

            $table->unique(['contract_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_working_days');
    }
};