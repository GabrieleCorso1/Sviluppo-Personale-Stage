<?php

use App\Models\AssignmentRole;
use App\Models\Necessity;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function allocationAdmin(): User
{
    $user = User::factory()->create(['isAllocation_visible' => false]);
    $user->roles()->attach(Role::firstOrCreate(['alias' => 'Admin']));

    return $user;
}

function allocationProject(User $owner, array $attributes = []): Project
{
    return Project::factory()->create(array_merge([
        'project_manager_id' => $owner->id, 'team_leader_id' => $owner->id,
        'scrum_master_id' => $owner->id, 'filestorage_id' => $owner->filestorage_id,
    ], $attributes));
}

function allocationRole(string $alias = 'Sviluppatore Back-end'): AssignmentRole
{
    return AssignmentRole::firstOrCreate(['alias' => $alias]);
}

function allocationRow(Project $project, User $user, array $attributes = []): ProjectUser
{
    return ProjectUser::create(array_merge([
        'project_id' => $project->id, 'user_id' => $user->id,
        'assignmentrole_id' => allocationRole()->id, 'allocation_type' => 'project',
        'startwork_date' => '2026-01-01', 'work_allocation' => 50,
    ], $attributes));
}

dataset('allocation routes', [
    ['GET', '/api/allocations'], ['GET', '/api/allocations/options'], ['GET', '/api/allocations/summary'],
    ['GET', '/api/allocations/projects/%p'], ['GET', '/api/allocations/resources/%u'],
    ['GET', '/api/allocations/projects/%p/logo'], ['GET', '/api/allocations/resources/%u/avatar'],
    ['POST', '/api/allocations'], ['PUT', '/api/allocations/%a'],
    ['PATCH', '/api/allocations/%a/difficulty'], ['POST', '/api/allocations/%a/conclude'],
    ['DELETE', '/api/allocations/%a'], ['PUT', '/api/allocations/projects/%p/necessities'],
]);

test('allocation endpoints return 401 without a token', function (string $method, string $path) {
    $this->json($method, strtr($path, ['%p' => '1', '%u' => '1', '%a' => '1']))->assertUnauthorized();
})->with('allocation routes');

test('allocation endpoints return 403 for HR and preserve assignments', function (string $method, string $path) {
    $actor = User::factory()->create();
    $actor->roles()->attach(Role::create(['alias' => 'HR Manager']));
    $project = allocationProject($actor);
    $row = allocationRow($project, $actor);
    $before = $row->fresh()->getAttributes();

    $this->withToken($actor->createToken('test')->plainTextToken)->json($method, strtr($path, [
        '%p' => (string) $project->id, '%u' => (string) $actor->id, '%a' => (string) $row->id,
    ]))->assertForbidden();

    expect($row->fresh()->getAttributes())->toBe($before);
})->with('allocation routes');

test('options expose all seven allocation roles including PM and scrum master and separate project types', function () {
    $admin = allocationAdmin();
    foreach (['Sviluppatore Front-end', 'Sviluppatore Back-end', 'Sviluppatore Full-stack', 'UX-UI Designer', 'Team Leader', 'Scrum Master', 'Project Manager'] as $alias) {
        allocationRole($alias);
    }

    $this->withToken($admin->createToken('test')->plainTextToken)->getJson('/api/allocations/options')->assertOk()
        ->assertJsonCount(7, 'data.roles')->assertJsonPath('data.types', ['project', 'timesheet', 'course']);
});

test('one resource has independent project timesheet and course assignments', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $timesheet = allocationProject($admin, ['is_timesheet' => true, 'alias' => $project->alias, 'status' => null]);
    $course = allocationProject($admin, ['is_course' => true]);
    $role = allocationRole();
    $this->withToken($admin->createToken('test')->plainTextToken);

    $projectId = $this->postJson('/api/allocations', [
        'type' => 'project', 'project_id' => $project->id, 'user_id' => $user->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-10-01',
    ])->assertCreated()->assertJsonPath('data.role.code', 'B')->assertJsonPath('data.is_difficult', false)->json('data.id');
    $timesheetId = $this->postJson('/api/allocations', [
        'type' => 'timesheet', 'project_id' => $timesheet->id, 'user_id' => $user->id, 'start_date' => '2026-10-01',
    ])->assertCreated()->assertJsonPath('data.role', null)->json('data.id');
    $this->postJson('/api/allocations', [
        'type' => 'course', 'project_id' => $course->id, 'user_id' => $user->id, 'start_date' => '2026-10-01',
    ])->assertCreated();
    $this->deleteJson('/api/allocations/'.$timesheetId)->assertOk();

    $this->assertSoftDeleted('project_user', ['id' => $timesheetId]);
    $this->assertDatabaseHas('project_user', ['id' => $projectId, 'deleted_at' => null]);
    $this->getJson('/api/allocations?type=timesheet')->assertOk()->assertJsonPath('data.resources.data.0.projects_count', 0);
    $this->getJson('/api/allocations?type=project')->assertOk()->assertJsonPath('data.resources.data.0.projects_count', 1);
    $this->getJson('/api/allocations?type=course')->assertOk()->assertJsonPath('data.resources.data.0.projects_count', 1);
});

test('summary counts distinct working projects and excludes hidden disabled future and ended resources', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $loaded = User::factory()->create();
    $suspended = User::factory()->create();
    $ended = User::factory()->create();
    $future = User::factory()->create();
    $hidden = User::factory()->create(['isAllocation_visible' => false]);
    $disabled = User::factory()->create();
    $projects = collect(['in corso', 'manutenzione', 'in corso', 'sospeso', 'concluso'])
        ->map(fn ($status) => allocationProject($admin, ['status' => $status]));
    foreach ($projects->take(3) as $project) {
        allocationRow($project, $loaded);
    }
    allocationRow($projects[0], $loaded, ['assignmentrole_id' => allocationRole('Team Leader')->id, 'is_difficult' => true]);
    allocationRow($projects[3], $suspended);
    allocationRow($projects[4], $suspended);
    allocationRow($projects[0], $ended, ['endwork_date' => '2026-11-01']);
    allocationRow($projects[0], $future, ['startwork_date' => '2026-11-03']);
    allocationRow($projects[0], $hidden, ['is_difficult' => true]);
    allocationRow($projects[0], $disabled, ['is_difficult' => true]);
    $disabled->delete();
    Necessity::create(['project_id' => $projects[0]->id, 'assignmentrole_id' => allocationRole()->id]);

    $this->withToken($admin->createToken('test')->plainTextToken)->getJson('/api/allocations/summary')->assertOk()
        ->assertJsonPath('data.total_resources', 4)->assertJsonPath('data.allocated_resources', 1)
        ->assertJsonPath('data.unallocated_resources', 3)->assertJsonPath('data.overloaded_resources', 1)
        ->assertJsonPath('data.resources_with_difficulty', 1)->assertJsonPath('data.open_necessities', 1);
    $this->getJson('/api/allocations/projects/'.$projects[0]->id)->assertOk()->assertJsonPath('data.resources_count', 1);
});

test('selected project puts members first and sorts both groups alphabetically before pagination', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $project = allocationProject($admin);
    $nonmember = User::factory()->create(['first_name' => 'Anna', 'last_name' => 'Verdi']);
    $member = User::factory()->create(['first_name' => 'Zeno', 'last_name' => 'Rossi']);
    $other = User::factory()->create(['first_name' => 'Bruno', 'last_name' => 'Bianchi']);
    allocationRow($project, $member);
    allocationRow($project, $other);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->getJson('/api/allocations?selected_project_id='.$project->id.'&per_page=1')->assertOk()
        ->assertJsonPath('data.resources.data.0.id', $other->id)->assertJsonPath('data.resources.total', 3);
    $this->getJson('/api/allocations?selected_project_id='.$project->id.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.resources.data.0.id', $member->id);
    $this->getJson('/api/allocations?selected_project_id='.$project->id.'&per_page=1&page=3')->assertOk()
        ->assertJsonPath('data.resources.data.0.id', $nonmember->id);
});

test('name role and status filters combine on the same assignment without changing global summary', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $user = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
    $project = allocationProject($admin);
    $paused = allocationProject($admin, ['status' => 'sospeso']);
    $backend = allocationRole();
    $frontend = allocationRole('Sviluppatore Front-end');
    allocationRow($project, $user, ['assignmentrole_id' => $frontend->id]);
    allocationRow($paused, $user, ['assignmentrole_id' => $backend->id]);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->getJson('/api/allocations?'.http_build_query([
        'search' => ' Rossi  Mario ', 'assignment_role_id' => $frontend->id, 'status' => 'in corso',
    ]))->assertOk()->assertJsonPath('data.resources.total', 1)->assertJsonCount(1, 'data.resources.data.0.allocations');
    $this->getJson('/api/allocations?'.http_build_query([
        'assignment_role_id' => $backend->id, 'status' => 'in corso',
    ]))->assertOk()->assertJsonPath('data.resources.total', 0);
    $this->getJson('/api/allocations/summary?search=missing')->assertOk()->assertJsonPath('data.allocated_resources', 1);
});

test('difficulty is explicit idempotent and attached to a single role', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $backend = allocationRow($project, $user);
    $leader = allocationRow($project, $user, ['assignmentrole_id' => allocationRole('Team Leader')->id]);
    $this->withToken($admin->createToken('test')->plainTextToken);

    foreach ([true, true, false] as $flag) {
        $this->patchJson('/api/allocations/'.$backend->id.'/difficulty', ['is_difficult' => $flag])->assertOk()
            ->assertJsonPath('data.is_difficult', $flag);
        $this->assertDatabaseHas('project_user', ['id' => $backend->id, 'is_difficult' => $flag]);
    }
    $this->assertDatabaseHas('project_user', ['id' => $leader->id, 'is_difficult' => false]);
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()->assertJsonPath('data.projects_count', 1)
        ->assertJsonCount(2, 'data.allocations');
});

test('concluding keeps history and permits a later assignment but rejects overlapping dates with 422', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $row = allocationRow($project, $user);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->postJson('/api/allocations/'.$row->id.'/conclude', ['end_date' => '2026-10-31'])->assertOk()
        ->assertJsonPath('data.end_date', '2026-10-31')->assertJsonPath('data.is_current', false);
    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'endwork_date' => '2026-10-31 00:00:00', 'deleted_at' => null]);
    $payload = ['type' => 'project', 'project_id' => $project->id, 'user_id' => $user->id,
        'assignment_role_id' => $row->assignmentrole_id, 'start_date' => '2026-10-31'];
    $this->postJson('/api/allocations', $payload)->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $id = $this->postJson('/api/allocations', array_merge($payload, ['start_date' => '2026-11-01']))
        ->assertCreated()->json('data.id');
    $this->putJson('/api/allocations/'.$row->id, ['start_date' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->postJson('/api/allocations/'.$row->id.'/conclude', ['end_date' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()->assertJsonCount(2, 'data.allocations');
    $this->deleteJson('/api/allocations/'.$id)->assertOk();
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()->assertJsonCount(1, 'data.allocations');
});

test('updates preserve percentage and difficulty and cannot change resource project or type', function () {
    $admin = allocationAdmin();
    $project = allocationProject($admin);
    $user = User::factory()->create();
    $row = allocationRow($project, $user, ['is_difficult' => true]);
    $role = allocationRole('Team Leader');
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->putJson('/api/allocations/'.$row->id, [
        'assignment_role_id' => $role->id, 'start_date' => '2026-02-01', 'work_allocation' => 100, 'is_difficult' => false,
    ])->assertOk()->assertJsonPath('data.role.code', 'TL')->assertJsonPath('data.start_date', '2026-02-01');
    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'work_allocation' => 50, 'is_difficult' => true]);
    $this->putJson('/api/allocations/'.$row->id, ['user_id' => $admin->id, 'type' => 'timesheet', 'project_id' => $project->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'type', 'project_id']);
});

test('necessities support an atomic multi-select replacement including clearing the list', function () {
    $admin = allocationAdmin();
    $project = allocationProject($admin);
    $first = allocationRole();
    $second = allocationRole('Sviluppatore Front-end');
    $this->withToken($admin->createToken('test')->plainTextToken);
    $url = '/api/allocations/projects/'.$project->id.'/necessities';

    $this->putJson($url, ['assignment_role_ids' => [$first->id, $second->id]])->assertOk()->assertJsonCount(2, 'data');
    $this->putJson($url, ['assignment_role_ids' => [$second->id]])->assertOk()->assertJsonCount(1, 'data');
    $this->assertDatabaseMissing('necessities', ['project_id' => $project->id, 'assignmentrole_id' => $first->id]);
    $this->putJson($url, ['assignment_role_ids' => [$second->id, $second->id]])->assertUnprocessable();
    $this->assertDatabaseCount('necessities', 1);
    $this->putJson($url, ['assignment_role_ids' => []])->assertOk()->assertJsonCount(0, 'data');
    $this->assertDatabaseCount('necessities', 0);
});

test('invalid dates wrong project types hidden users and unavailable roles return 422 without creating assignments', function () {
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $role = allocationRole();
    $payload = ['type' => 'project', 'project_id' => $project->id, 'user_id' => $user->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-01-01'];
    $this->withToken($admin->createToken('test')->plainTextToken);

    foreach ([
        ['type' => 'timesheet', 'assignment_role_id' => null],
        ['type' => 'course', 'assignment_role_id' => null],
        ['start_date' => '2026-02-30'], ['assignment_role_id' => null],
        ['assignment_role_id' => 999999], ['user_id' => $admin->id],
    ] as $invalid) {
        $this->postJson('/api/allocations', array_merge($payload, $invalid))->assertUnprocessable();
    }
    $this->assertDatabaseCount('project_user', 0);
});

test('timesheet roles and difficulty are rejected with 422', function () {
    $admin = allocationAdmin();
    $project = allocationProject($admin, ['is_timesheet' => true]);
    $user = User::factory()->create();
    $row = allocationRow($project, $user, ['allocation_type' => 'timesheet', 'assignmentrole_id' => null]);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->putJson('/api/allocations/'.$row->id, ['assignment_role_id' => allocationRole()->id])->assertUnprocessable();
    $this->patchJson('/api/allocations/'.$row->id.'/difficulty', ['is_difficult' => true])->assertUnprocessable();
    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'assignmentrole_id' => null, 'is_difficult' => false]);
});

test('disabled and hidden resources and deleted projects cannot be edited or exposed by details', function () {
    $admin = allocationAdmin();
    $project = allocationProject($admin);
    $hidden = User::factory()->create(['isAllocation_visible' => false]);
    $disabled = User::factory()->create();
    $row = allocationRow($project, $hidden);
    $disabledRow = allocationRow($project, $disabled);
    $disabled->delete();
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->getJson('/api/allocations/resources/'.$hidden->id)->assertNotFound();
    $this->getJson('/api/allocations/resources/'.$disabled->id)->assertNotFound();
    $this->putJson('/api/allocations/'.$row->id, ['start_date' => '2026-02-01'])->assertNotFound();
    $this->deleteJson('/api/allocations/'.$disabledRow->id)->assertNotFound();
    $project->delete();
    $this->getJson('/api/allocations/projects/'.$project->id)->assertNotFound();
    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'deleted_at' => null]);
});

test('empty matrices and invalid filters have predictable responses', function () {
    $admin = allocationAdmin();
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->getJson('/api/allocations')->assertOk()->assertJsonCount(0, 'data.projects')->assertJsonPath('data.resources.total', 0);
    $this->getJson('/api/allocations?type=invalid&per_page=1000&page=0&status=invalid')->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'per_page', 'page', 'status']);
    $this->getJson('/api/allocations/summary')->assertOk()->assertJsonPath('data.total_resources', 0);
});

test('creation requires project resource type and a start date with 422 on an empty payload', function () {
    $admin = allocationAdmin();

    $this->withToken($admin->createToken('test')->plainTextToken)->postJson('/api/allocations', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'project_id', 'user_id', 'start_date']);

    $this->assertDatabaseCount('project_user', 0);
});

test('duplicate roles return 422 but a second distinct role on the same project is allowed', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $role = allocationRole();
    $payload = ['type' => 'project', 'project_id' => $project->id, 'user_id' => $user->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-01-01'];
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->postJson('/api/allocations', $payload)->assertCreated();
    $this->postJson('/api/allocations', $payload)->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $leaderId = $this->postJson('/api/allocations', array_merge($payload, ['assignment_role_id' => allocationRole('Team Leader')->id]))
        ->assertCreated()->json('data.id');
    $this->putJson('/api/allocations/'.$leaderId, ['assignment_role_id' => $role->id])->assertUnprocessable();

    $this->assertDatabaseCount('project_user', 2);
    $this->assertDatabaseHas('project_user', ['id' => $leaderId, 'assignmentrole_id' => allocationRole('Team Leader')->id]);
    $this->getJson('/api/allocations/summary')->assertOk()->assertJsonPath('data.allocated_resources', 1)->assertJsonPath('data.overloaded_resources', 0);
});

test('start and end dates are inclusive for daily counts', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $row = allocationRow($project, $user, ['startwork_date' => '2026-11-02', 'endwork_date' => '2026-11-02']);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->getJson('/api/allocations')->assertOk()->assertJsonPath('data.resources.data.0.allocations.0.id', $row->id);
    $this->travel(1)->days();
    $this->getJson('/api/allocations')->assertOk()->assertJsonCount(0, 'data.resources.data.0.allocations');
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()->assertJsonPath('data.allocations.0.is_current', false);
});

test('concluding rejects missing or earlier dates with 422 and preserves the assignment', function () {
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $row = allocationRow(allocationProject($admin), $user);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->postJson('/api/allocations/'.$row->id.'/conclude', [])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $this->postJson('/api/allocations/'.$row->id.'/conclude', ['end_date' => '2025-12-31'])->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');

    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'endwork_date' => null]);
});

test('deleted roles cannot be assigned or added as necessities', function () {
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $role = allocationRole();
    $role->delete();
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->postJson('/api/allocations', ['type' => 'project', 'project_id' => $project->id, 'user_id' => $user->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('assignment_role_id');
    $this->putJson('/api/allocations/projects/'.$project->id.'/necessities', ['assignment_role_ids' => [$role->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('assignment_role_ids.0');

    $this->assertDatabaseCount('project_user', 0);
    $this->assertDatabaseCount('necessities', 0);
});

test('migration preserves legacy assignments and initializes them as project allocations', function () {
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin);
    $role = allocationRole();
    $migration = require database_path('migrations/2026_11_02_132236_extend_project_user_for_allocations.php');
    $migration->down();
    $id = DB::table('project_user')->insertGetId([
        'project_id' => $project->id, 'user_id' => $user->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 70,
    ]);

    $migration->up();

    $this->assertDatabaseHas('project_user', ['id' => $id, 'work_allocation' => 70,
        'assignmentrole_id' => $role->id, 'allocation_type' => 'project', 'is_difficult' => false]);
});

test('rollback refuses to discard independent timesheet or course allocations', function () {
    $admin = allocationAdmin();
    $user = User::factory()->create();
    $project = allocationProject($admin, ['is_timesheet' => true]);
    $row = allocationRow($project, $user, ['allocation_type' => 'timesheet', 'assignmentrole_id' => null]);
    $migration = require database_path('migrations/2026_11_02_132236_extend_project_user_for_allocations.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    $this->assertModelExists($row);
    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'allocation_type' => 'timesheet']);
});

test('necessities reject courses and timesheets without changing existing needs', function (array $flags, array $roles) {
    $admin = allocationAdmin();
    $project = allocationProject($admin, $flags);
    $role = allocationRole();
    $need = Necessity::create(['project_id' => $project->id, 'assignmentrole_id' => $role->id]);
    $this->withToken($admin->createToken('test')->plainTextToken)
        ->putJson('/api/allocations/projects/'.$project->id.'/necessities', [
            'assignment_role_ids' => $roles === [] ? [] : [$role->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignment_role_ids');
    $this->assertModelExists($need);
    $this->assertDatabaseCount('necessities', 1);
})->with([
    'course add' => [['is_course' => true], [1]],
    'course clear' => [['is_course' => true], []],
    'timesheet add' => [['is_timesheet' => true], [1]],
    'timesheet clear' => [['is_timesheet' => true], []],
]);
