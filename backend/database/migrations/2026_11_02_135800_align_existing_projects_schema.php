<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $columns = collect(Schema::getColumns('projects'))->keyBy('name');

        Schema::table('projects', function (Blueprint $table) use ($columns) {
            if (! $columns['status']['nullable']) {
                $table->enum('status', ['in corso', 'manutenzione', 'sospeso', 'concluso'])->nullable()->change();
            }
            if (! $columns['priority']['nullable']) {
                $table->enum('priority', ['bassa', 'media', 'alta'])->nullable()->change();
            }
            foreach (['project_manager_id', 'team_leader_id', 'scrum_master_id', 'filestorage_id'] as $name) {
                if (! $columns[$name]['nullable']) {
                    $table->unsignedBigInteger($name)->nullable()->change();
                }
            }
            foreach (['azure_team_name', 'bug_count_url', 'note'] as $name) {
                if (! $columns[$name]['nullable']) {
                    $table->string($name)->nullable()->change();
                }
            }
            if (! $columns['start_date']['nullable']) {
                $table->date('start_date')->nullable()->change();
            }
        });

        foreach (['project', 'timesheet', 'course'] as $type) {
            $projects = DB::table('projects');
            if ($type === 'course') {
                $projects->where('is_course', true);
            } else {
                $projects->where('is_course', false)->where('is_timesheet', $type === 'timesheet');
            }
            DB::table('project_user')->whereIn('project_id', $projects->select('id'))
                ->where('allocation_type', '!=', $type)->update(['allocation_type' => $type]);
        }
    }

    public function down(): void
    {
        // The original projects migration already defines nullable fields. Keep data and its canonical project type on rollback.
    }
};
