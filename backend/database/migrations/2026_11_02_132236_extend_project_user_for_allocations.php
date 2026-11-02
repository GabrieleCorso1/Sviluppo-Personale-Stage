<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->string('allocation_type', 16)->default('project');
            $table->boolean('is_difficult')->default(false);
            $table->unsignedBigInteger('assignmentrole_id')->nullable()->change();
            $table->index(['project_id', 'allocation_type', 'user_id'], 'project_user_allocation_lookup');
        });
    }

    public function down(): void
    {
        if (DB::table('project_user')->where('allocation_type', '!=', 'project')->orWhereNull('assignmentrole_id')->exists()) {
            throw new RuntimeException('Rollback non possibile: sono presenti allocazioni Timesheet/Corsi. Esportarle e rimuoverle esplicitamente prima del rollback.');
        }

        Schema::table('project_user', function (Blueprint $table) {
            $table->dropIndex('project_user_allocation_lookup');
            $table->dropColumn(['allocation_type', 'is_difficult']);
            $table->unsignedBigInteger('assignmentrole_id')->nullable(false)->change();
        });
    }
};
