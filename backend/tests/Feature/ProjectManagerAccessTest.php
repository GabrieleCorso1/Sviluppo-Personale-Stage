<?php

use App\Models\AssignmentRole;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create();
    $this->manager->roles()->attach(Role::create(['alias' => 'Project Manager', 'description' => 'Project Manager']));
    $this->assignmentRole = AssignmentRole::create(['alias' => 'Project Manager']);
    $this->owned = Project::factory()->create();
    $this->other = Project::factory()->create();
    $this->assignment = ProjectUser::create([
        'project_id' => $this->owned->id, 'user_id' => $this->manager->id,
        'assignmentrole_id' => $this->assignmentRole->id,
        'startwork_date' => now()->subMonth()->toDateString(), 'work_allocation' => 20,
    ]);
    Sanctum::actingAs($this->manager);
});

test('project managers see only their currently assigned projects and scoped counters', function () {
    Project::factory()->create(['is_timesheet' => true]);
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->owned->id)
        ->assertJsonPath('counts.projects', 1)->assertJsonPath('counts.timesheets', 0);
    $this->getJson('/api/projects?is_timesheet=1')->assertForbidden();
    $this->getJson('/api/projects/'.$this->owned->id)->assertOk();
    $this->getJson('/api/projects/'.$this->other->id)->assertForbidden();
});

test('project managers can update their project and its note but not another project', function () {
    $this->patchJson('/api/projects/'.$this->owned->id, ['description' => 'Aggiornato'])->assertOk();
    $this->patchJson('/api/projects/'.$this->owned->id.'/note', ['note' => 'Nota PM'])->assertOk();
    $this->getJson('/api/projects/'.$this->owned->id.'/users')->assertOk();
    $this->patchJson('/api/projects/'.$this->other->id, ['description' => 'Vietato'])->assertForbidden();
    $this->patchJson('/api/projects/'.$this->other->id.'/note', ['note' => 'Vietato'])->assertForbidden();
    $this->getJson('/api/projects/'.$this->other->id.'/users')->assertForbidden();
    $this->postJson('/api/projects/'.$this->other->id.'/logo')->assertForbidden();
    $this->postJson('/api/projects/'.$this->other->id.'/delivery-plan')->assertForbidden();
    $this->deleteJson('/api/projects/'.$this->owned->id)->assertForbidden();
    $this->getJson('/api/users')->assertForbidden();
    $this->getJson('/api/allocations')->assertForbidden();
});

test('expired and future manager assignments do not grant access', function (string $period) {
    $this->assignment->update($period === 'expired'
        ? ['endwork_date' => now()->subDay()->toDateString()]
        : ['startwork_date' => now()->addDay()->toDateString()]);
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()->assertJsonCount(0, 'data')
        ->assertJsonPath('counts.projects', 0);
    $this->getJson('/api/projects/'.$this->owned->id)->assertForbidden();
})->with(['expired', 'future']);

test('manager options do not disclose users outside assigned projects', function () {
    $this->getJson('/api/project-options/users')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->manager->id);
});
