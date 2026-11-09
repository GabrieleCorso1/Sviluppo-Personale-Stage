<?php

use App\Models\AssignmentRole;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\Sbi;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->startOfDay());
    $this->writer = User::factory()->create();
    $this->receiver = User::factory()->create();
    $this->project = Project::factory()->create();
    $this->sbi = Sbi::create([
        'writer_id' => $this->writer->id,
        'receiver_id' => $this->receiver->id,
        'project_id' => $this->project->id,
    ]);
});

test('SBI author and recipient can read their feedback', function () {
    expect(Gate::forUser($this->writer)->allows('view', $this->sbi))->toBeTrue();
    expect(Gate::forUser($this->receiver)->allows('view', $this->sbi))->toBeTrue();
});

test('SBI administrator can read feedback between other users', function () {
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::create(['alias' => 'Admin']));
    expect(Gate::forUser($admin)->allows('view', $this->sbi))->toBeTrue();
});

test('SBI reading requires a current leadership assignment in the same project', function (
    string $roleAlias,
    string $start,
    ?string $end,
    bool $otherProject,
    bool $deleted,
    bool $expected
) {
    $actor = User::factory()->create();
    $actor->roles()->attach(Role::create(['alias' => $roleAlias]));
    $assignmentRole = AssignmentRole::create(['alias' => $roleAlias]);
    $project = $otherProject ? Project::factory()->create() : $this->project;
    $assignment = ProjectUser::create([
        'project_id' => $project->id,
        'user_id' => $actor->id,
        'assignmentrole_id' => $assignmentRole->id,
        'startwork_date' => $start,
        'endwork_date' => $end,
        'work_allocation' => 0,
    ]);
    if ($deleted) {
        $assignment->delete();
    }

    expect(Gate::forUser($actor)->allows('view', $this->sbi))->toBe($expected);
})->with([
    'current PM' => ['Project Manager', '2026-01-01', null, false, false, true],
    'current TL' => ['Team Leader', '2026-01-01', null, false, false, true],
    'unrelated PM' => ['Project Manager', '2026-01-01', null, true, false, false],
    'unrelated TL' => ['Team Leader', '2026-01-01', null, true, false, false],
    'future PM' => ['Project Manager', '2026-12-01', null, false, false, false],
    'ended TL' => ['Team Leader', '2026-01-01', '2026-11-04', false, false, false],
    'removed PM' => ['Project Manager', '2026-01-01', null, false, true, false],
    'starts today' => ['Team Leader', '2026-11-05', null, false, false, true],
    'ends today' => ['Project Manager', '2026-01-01', '2026-11-05', false, false, true],
]);

test('SBI ordinary members and managers without assignment cannot read other feedback', function () {
    foreach (['User', 'HR Manager', 'Project Manager', 'Team Leader'] as $alias) {
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::create(['alias' => $alias]));
        expect(Gate::forUser($actor)->allows('view', $this->sbi))->toBeFalse();
    }
    expect(Gate::allows('view', $this->sbi))->toBeFalse();
});

test('SBI deleted feedback cannot be read through the normal view permission', function () {
    $this->sbi->delete();
    expect(Gate::forUser($this->writer)->allows('view', $this->sbi))->toBeFalse();
});
