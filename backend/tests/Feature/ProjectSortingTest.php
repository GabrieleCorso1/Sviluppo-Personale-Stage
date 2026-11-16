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
    $this->travelTo(now()->setDate(2026, 11, 12)->startOfDay());
    $this->actor = User::factory()->create();
    $this->actor->roles()->attach(Role::firstOrCreate(['alias' => 'Admin']));
    Sanctum::actingAs($this->actor);
});

test('project people ordering uses current assignments across pages in both directions', function (string $field, string $alias) {
    $role = AssignmentRole::firstOrCreate(['alias' => $alias]);
    $alpha = Project::factory()->create(['alias' => 'Alpha', 'project_manager_id' => $this->actor->id]);
    $zulu = Project::factory()->create(['alias' => 'Zulu', 'project_manager_id' => $this->actor->id]);
    $zelda = User::factory()->create(['first_name' => 'Zelda', 'last_name' => 'Rossi']);
    $anna = User::factory()->create(['first_name' => 'Anna', 'last_name' => 'Verdi']);
    $old = User::factory()->create(['first_name' => 'Aaa', 'last_name' => 'Storico']);
    foreach ([[$alpha, $old, '2025-01-01', '2025-12-31'], [$alpha, $zelda, '2026-01-01', '2026-12-31'], [$alpha, $old, '2027-01-01', null], [$zulu, $anna, '2026-01-01', null]] as [$project, $user, $start, $end]) {
        ProjectUser::create(['project_id' => $project->id, 'user_id' => $user->id,
            'assignmentrole_id' => $role->id, 'startwork_date' => $start, 'endwork_date' => $end,
            'allocation_type' => 'project', 'work_allocation' => 0]);
    }
    $url = '/api/projects?is_timesheet=0&per_page=1&sort_by='.$field;
    $this->getJson($url.'&sort_direction=asc')->assertOk()->assertJsonPath('data.0.id', $zulu->id);
    $this->getJson($url.'&sort_direction=asc&page=2')->assertOk()->assertJsonPath('data.0.id', $alpha->id);
    $this->getJson($url.'&sort_direction=desc')->assertOk()->assertJsonPath('data.0.id', $alpha->id);
})->with([
    ['project_manager', 'Project Manager'],
    ['team_leader', 'Team Leader'],
    ['scrum_master', 'Scrum Master'],
]);

test('project status and priority ordering follows their business sequence', function (string $field, array $values) {
    $expected = [];
    foreach ($values as $index => $value) {
        $expected[] = Project::factory()->create(['alias' => 'Progetto '.(10 - $index),
            'project_manager_id' => $this->actor->id, $field => $value])->id;
    }
    $url = '/api/projects?is_timesheet=0&sort_by='.$field;
    expect($this->getJson($url.'&sort_direction=asc')->assertOk()->json('data.*.id'))->toBe($expected);
    expect($this->getJson($url.'&sort_direction=desc')->assertOk()->json('data.*.id'))->toBe(array_reverse($expected));
})->with([
    ['priority', ['alta', 'media', 'bassa']],
    ['status', ['in corso', 'manutenzione', 'concluso', 'sospeso']],
]);

test('project ordering rejects unknown columns and non-name timesheet ordering', function () {
    $this->getJson('/api/projects?is_timesheet=0&sort_by=unknown')->assertUnprocessable()->assertJsonValidationErrors('sort_by');
    $this->getJson('/api/projects?is_timesheet=1&sort_by=status')->assertUnprocessable()->assertJsonValidationErrors('sort_by');
    $this->getJson('/api/projects?is_timesheet=1&sort_by=name&sort_direction=desc')->assertOk();
});
