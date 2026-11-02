<?php

use App\Models\AssignmentRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SQLite table rebuilds must run outside a test transaction so foreign-key constraints can be temporarily disabled by the schema builder.
uses(DatabaseMigrations::class);

afterEach(function () {
    DB::table('project_user')->delete();
});

test('compatibility migration upgrades old required columns without losing projects or assignment history', function () {
    Schema::table('projects', function ($table) {
        $table->enum('status', ['in corso', 'manutenzione', 'sospeso', 'concluso'])->nullable(false)->change();
        $table->enum('priority', ['bassa', 'media', 'alta'])->nullable(false)->change();
        foreach (['project_manager_id', 'team_leader_id', 'scrum_master_id', 'filestorage_id'] as $name) {
            $table->unsignedBigInteger($name)->nullable(false)->change();
        }
        foreach (['azure_team_name', 'bug_count_url', 'note'] as $name) {
            $table->string($name)->nullable(false)->change();
        }
        $table->date('start_date')->nullable(false)->change();
    });
    $actor = User::factory()->create(['isAllocation_visible' => false]);
    $actor->roles()->attach(Role::create(['alias' => 'Admin']));
    $user = User::factory()->create();
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $project = Project::factory()->create(['is_timesheet' => true, 'project_manager_id' => $actor->id]);
    $rowId = DB::table('project_user')->insertGetId(['project_id' => $project->id, 'user_id' => $user->id,
        'assignmentrole_id' => $role->id, 'allocation_type' => 'project', 'startwork_date' => '2026-01-01', 'work_allocation' => 70]);
    $migration = require database_path('migrations/2026_11_02_135800_align_existing_projects_schema.php');

    $migration->up();

    $this->assertModelExists($project);
    $this->assertDatabaseHas('project_user', ['id' => $rowId, 'assignmentrole_id' => $role->id, 'work_allocation' => 70, 'allocation_type' => 'timesheet']);
    $this->withToken($actor->createToken('test')->plainTextToken)->postJson('/api/projects', ['alias' => 'Schema aggiornato', 'is_timesheet' => true])->assertCreated();
    $this->postJson('/api/allocations', ['type' => 'timesheet', 'project_id' => $project->id, 'user_id' => $user->id,
        'start_date' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
});
