<?php

use App\Models\AssignmentRole;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->person = User::factory()->create();
    $this->pmRole = AssignmentRole::create(['alias' => 'Project Manager']);
    $this->devRole = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $this->project = Project::create([
        'alias' => 'Regole incarichi', 'is_timesheet' => false, 'is_course' => false,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $this->assign = function ($role, $start, $end = null, $project = null, $user = null) {
        return ProjectUser::create([
            'project_id' => ($project ?? $this->project)->id,
            'user_id' => ($user ?? $this->person)->id,
            'assignmentrole_id' => $role->id,
            'startwork_date' => $start, 'endwork_date' => $end, 'work_allocation' => 0,
        ]);
    };
});

test('PM and other overlapping assignments are rejected in both directions', function ($pmFirst) {
    ($this->assign)($pmFirst ? $this->pmRole : $this->devRole, '2026-10-01', '2026-10-31');
    expect(fn () => ($this->assign)($pmFirst ? $this->devRole : $this->pmRole, '2026-10-31'))
        ->toThrow(ValidationException::class);
    expect(ProjectUser::count())->toBe(1);
})->with([true, false]);

test('non overlapping history is allowed and extending it into a PM period is rejected', function () {
    $developer = ($this->assign)($this->devRole, '2026-10-01', '2026-10-30');
    ($this->assign)($this->pmRole, '2026-10-31');
    expect(fn () => $developer->update(['endwork_date' => '2026-10-31']))->toThrow(ValidationException::class);
    expect($developer->fresh()->endwork_date->toDateString())->toBe('2026-10-30');
});

test('multiple PMs and PM responsibilities on different projects are allowed', function () {
    ($this->assign)($this->pmRole, '2026-10-01');
    ($this->assign)($this->pmRole, '2026-10-01', null, null, User::factory()->create());
    $otherProject = $this->project->replicate();
    $otherProject->alias = 'Altro progetto';
    $otherProject->save();
    ($this->assign)($this->pmRole, '2026-10-01', null, $otherProject);
    expect(ProjectUser::count())->toBe(3);
});

test('no unconfirmed cross project restriction is imposed', function () {
    ($this->assign)($this->pmRole, '2026-10-01');
    $otherProject = $this->project->replicate();
    $otherProject->alias = 'Altro progetto';
    $otherProject->save();
    ($this->assign)($this->devRole, '2026-10-01', null, $otherProject);
    expect(ProjectUser::count())->toBe(2);
});

test('role changes respect conflicts and soft deleted assignments do not block new ones', function () {
    $developer = ($this->assign)($this->devRole, '2026-10-01');
    $leaderRole = AssignmentRole::create(['alias' => 'Team Leader']);
    $leader = ($this->assign)($leaderRole, '2026-10-01');
    expect(fn () => $leader->update(['assignmentrole_id' => $this->pmRole->id]))->toThrow(ValidationException::class);
    expect($leader->fresh()->assignmentrole_id)->toBe($leaderRole->id);
    $developer->delete();
    $leader->update(['assignmentrole_id' => $this->pmRole->id]);
    expect($leader->fresh()->assignmentrole_id)->toBe($this->pmRole->id);
    expect(fn () => $developer->restore())->toThrow(ValidationException::class);
    expect($developer->fresh()->trashed())->toBeTrue();
});

test('leadership has one assignment per project and overlapping period', function ($roleName) {
    $role = AssignmentRole::create(['alias' => $roleName]);
    $first = ($this->assign)($role, '2026-10-01', '2026-10-30');
    $otherPerson = User::factory()->create();
    expect(fn () => ($this->assign)($role, '2026-10-30', null, null, $otherPerson))
        ->toThrow(ValidationException::class);
    $successor = ($this->assign)($role, '2026-10-31', null, null, $otherPerson);
    expect(fn () => $first->update(['endwork_date' => '2026-10-31']))->toThrow(ValidationException::class);
    $successor->delete();
    $first->update(['endwork_date' => null]);
    expect(fn () => $successor->restore())->toThrow(ValidationException::class);
    expect($successor->fresh()->trashed())->toBeTrue();
})->with(['Team Leader', 'Scrum Master']);

test('invalid assignment periods are rejected on creation and either date update', function () {
    expect(fn () => ($this->assign)($this->devRole, '2026-10-30', '2026-10-29'))
        ->toThrow(ValidationException::class);
    expect(ProjectUser::count())->toBe(0);
    $assignment = ($this->assign)($this->devRole, '2026-10-30', '2026-10-30');
    expect($assignment->endwork_date->toDateString())->toBe('2026-10-30');
    expect(fn () => $assignment->update(['endwork_date' => '2026-10-29']))->toThrow(ValidationException::class);
    $assignment->refresh();
    expect(fn () => $assignment->update(['startwork_date' => '2026-10-31']))->toThrow(ValidationException::class);
    $assignment->refresh();
    expect($assignment->startwork_date->toDateString())->toBe('2026-10-30')
        ->and($assignment->endwork_date->toDateString())->toBe('2026-10-30');
    $assignment->update(['endwork_date' => null]);
    expect($assignment->fresh()->endwork_date)->toBeNull();
});
