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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('alias');
            $table->string('description')->nullable();
            $table->boolean('is_daily');
            $table->boolean('is_azure');
            $table->boolean('is_ux_ui');
            $table->enum('status', ['in corso', 'manutenzione', 'sospeso', 'concluso'])->nullable();
            $table->string('azure_project_name')->nullable();
            $table->foreignId('project_manager_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('team_leader_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('scrum_master_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('delivery_plan')->nullable();
            $table->string('azure_team_name')->nullable();
            $table->string('bug_count_url')->nullable();
            $table->string('note')->nullable();
            $table->enum('priority', ['bassa', 'media', 'alta'])->nullable();
            $table->string('pbi_without_feature_url')->nullable();
            $table->string('pbi_past_feature_url')->nullable();
            $table->boolean('is_timesheet');
            $table->boolean('is_course');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->foreignId('filestorage_id')
                ->nullable()
                ->constrained('filestorage')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
