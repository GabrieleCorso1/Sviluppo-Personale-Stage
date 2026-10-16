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
        Schema::create('assignmentrole_user', function (Blueprint $table) {
            $table->id();
        $table->foreignId('user_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->foreignId('assignmentrole_id')
            ->constrained()
            ->cascadeOnDelete();

            $table->enum('expertise',['junior', 'senior']);
            $table->unique(['user_id', 'assignmentrole_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignmentrole_user');
    }
};
