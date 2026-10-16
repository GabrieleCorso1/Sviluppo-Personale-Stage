<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar', function (Blueprint $table) {
            $table->id();
         $table->foreignId('user_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->foreignId('typology_id')
            ->constrained('calendar_typology')
            ->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('status', ['pending','approved','rejected'])->default('pending');
            $table->boolean('is_smart')->default(false);
            $table->string('notes')->nullable();
    
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar');
    }
};
