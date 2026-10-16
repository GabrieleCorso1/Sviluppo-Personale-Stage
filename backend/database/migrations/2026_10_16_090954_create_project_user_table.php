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
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
        $table->foreignId('project_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->foreignId('user_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->foreignId('assignmentrole_id')
            ->constrained('assignmentroles')
            ->cascadeOnDelete();
            
            $table->unique(['user_id', 'project_id']);
            $table->date('startwork_date');
            $table->date('endwork_date')->nullable();
            $table->integer('work_allocation');

            

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
