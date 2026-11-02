<?php

use App\Models\AssignmentRole;
use App\Models\Necessity;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

function integratedAllocationAdmin(): User
{
    $actor = User::factory()->create(['isAllocation_visible' => false]);
    $actor->roles()->attach(Role::firstOrCreate(['alias' => 'Admin']));
    AssignmentRole::firstOrCreate(['alias' => 'Project Manager']);

    return $actor;
}

function integratedNormalProjectPayload(User $manager): array
{
    return [
        'alias' => 'Progetto integrato', 'is_timesheet' => false, 'project_manager_id' => $manager->id,
        'start_date' => '2026-01-01', 'status' => 'in corso', 'priority' => 'media',
    ];
}

test('project and timesheet creation APIs feed distinct allocation tabs even with the same name', function () {
    $actor = integratedAllocationAdmin();
    $this->withToken($actor->createToken('test')->plainTextToken);
    $normal = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');
    $timesheet = $this->postJson('/api/projects', ['alias' => 'Progetto integrato', 'is_timesheet' => true])
        ->assertCreated()->json('data.id');
    $course = Project::factory()->create(['is_course' => true, 'project_manager_id' => $actor->id]);

    $this->getJson('/api/allocations?type=project')->assertOk()->assertJsonCount(1, 'data.projects')->assertJsonPath('data.projects.0.id', $normal);
    $this->getJson('/api/allocations?type=timesheet')->assertOk()->assertJsonCount(1, 'data.projects')->assertJsonPath('data.projects.0.id', $timesheet);
    $this->getJson('/api/allocations?type=course')->assertOk()->assertJsonCount(1, 'data.projects')->assertJsonPath('data.projects.0.id', $course->id);
});

test('a timesheet without status counts as assigned locally while header totals always describe normal projects', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $actor = integratedAllocationAdmin();
    $user = User::factory()->create();
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $normal = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');
    $timesheet = $this->postJson('/api/projects', ['alias' => 'Timesheet senza stato', 'is_timesheet' => true])
        ->assertCreated()->assertJsonPath('data.status', null)->json('data.id');
    $this->postJson('/api/allocations', ['type' => 'timesheet', 'project_id' => $timesheet, 'user_id' => $user->id, 'start_date' => '2026-11-01'])->assertCreated();

    $this->getJson('/api/allocations/resources/'.$user->id.'?type=timesheet')->assertOk()
        ->assertJsonPath('data.is_unallocated', false)->assertJsonPath('data.is_overloaded', false);
    $this->getJson('/api/allocations/summary?type=timesheet')->assertOk()
        ->assertJsonPath('data.type', 'project')->assertJsonPath('data.allocated_resources', 0);
    $this->postJson('/api/allocations', ['type' => 'project', 'project_id' => $normal, 'user_id' => $user->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-11-01'])->assertCreated();
    foreach (['project', 'timesheet', 'course'] as $type) {
        $this->getJson('/api/allocations/summary?type='.$type)->assertOk()
            ->assertJsonPath('data.type', 'project')->assertJsonPath('data.allocated_resources', 1);
    }
});

test('both modules use the same business day at midnight in Rome in winter and summer', function (string $instant, string $day) {
    $this->travelTo(Carbon::parse($instant, 'UTC'));
    $actor = integratedAllocationAdmin();
    $user = User::factory()->create();
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $id = $this->postJson('/api/projects', array_merge(integratedNormalProjectPayload($actor), ['start_date' => $day]))
        ->assertCreated()->json('data.id');
    $this->postJson('/api/allocations', ['type' => 'project', 'project_id' => $id, 'user_id' => $user->id,
        'assignment_role_id' => $role->id, 'start_date' => $day])->assertCreated();

    $this->getJson('/api/projects/'.$id.'/users')->assertOk()->assertJsonPath('total', 2);
    $this->getJson('/api/allocations')->assertOk()->assertJsonPath('data.as_of', $day)
        ->assertJsonPath('data.resources.data.0.projects_count', 1);
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()->assertJsonPath('data.allocations.0.is_current', true);
})->with([
    'winter' => ['2026-11-02 23:30:00', '2026-11-03'],
    'summer' => ['2026-06-01 22:30:00', '2026-06-02'],
]);

test('existing PM and scrum master assignments can be viewed and managed through allocation by an admin', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $actor = integratedAllocationAdmin();
    $actor->update(['isAllocation_visible' => true]);
    $scrumUser = User::factory()->create();
    $scrum = AssignmentRole::create(['alias' => 'Scrum Master']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $id = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');
    $pmRow = ProjectUser::firstOrFail();
    $scrumRow = ProjectUser::create(['project_id' => $id, 'user_id' => $scrumUser->id, 'assignmentrole_id' => $scrum->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 0]);

    $this->getJson('/api/projects/'.$id.'/users')->assertOk()->assertJsonPath('total', 2);
    $this->getJson('/api/allocations/projects/'.$id)->assertOk()->assertJsonPath('data.resources_count', 2);
    foreach ([$pmRow, $scrumRow] as $row) {
        $this->getJson('/api/allocations/resources/'.$row->user_id)->assertOk()->assertJsonCount(1, 'data.allocations');
        $this->putJson('/api/allocations/'.$row->id, ['start_date' => '2026-02-01'])->assertOk();
        $this->patchJson('/api/allocations/'.$row->id.'/difficulty', ['is_difficult' => true])->assertOk();
        $this->postJson('/api/allocations/'.$row->id.'/conclude', ['end_date' => '2026-10-31'])->assertOk();
        expect($row->fresh()->endwork_date->toDateString())->toBe('2026-10-31');
        expect($row->fresh()->is_difficult)->toBeTrue();
        $this->deleteJson('/api/allocations/'.$row->id)->assertOk();
        $this->assertSoftDeleted($row);
    }
});

test('team leader updates through allocation are reflected by project management and respect its unique leadership rule', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $actor = integratedAllocationAdmin();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $role = AssignmentRole::create(['alias' => 'Team Leader']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $projectId = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');
    $payload = ['type' => 'project', 'project_id' => $projectId, 'user_id' => $first->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-01-01'];
    $id = $this->postJson('/api/allocations', $payload)->assertCreated()->json('data.id');

    $this->getJson('/api/projects/'.$projectId)->assertOk()->assertJsonPath('data.team_leader_id', $first->id);
    $this->postJson('/api/allocations', array_merge($payload, ['user_id' => $second->id]))->assertUnprocessable();
    $this->postJson('/api/allocations/'.$id.'/conclude', ['end_date' => '2026-11-01'])->assertOk();
    $this->getJson('/api/projects/'.$projectId)->assertOk()->assertJsonPath('data.team_leader_id', null);
    $this->assertDatabaseHas('project_user', ['id' => $id, 'deleted_at' => null]);
});

test('allocation cannot give a PM an overlapping technical role on the same project', function () {
    $actor = integratedAllocationAdmin();
    $actor->update(['isAllocation_visible' => true]);
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $id = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');

    $this->postJson('/api/allocations', ['type' => 'project', 'project_id' => $id, 'user_id' => $actor->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-02-01'])->assertUnprocessable();

    $this->assertDatabaseCount('project_user', 1);
});

test('allocation rejects role and status filters on tabs whose project forms have no such fields', function () {
    $actor = integratedAllocationAdmin();
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/allocations?type=timesheet&status=in%20corso&assignment_role_id='.$role->id)
        ->assertUnprocessable()->assertJsonValidationErrors(['status', 'assignment_role_id']);
});

test('logos uploaded by project management are readable through authenticated allocation URLs', function () {
    Storage::fake('local');
    $actor = integratedAllocationAdmin();
    $this->withToken($actor->createToken('test')->plainTextToken);
    $id = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');
    $contents = file_get_contents(base_path('postman/fixtures/logo.png'));
    $this->postJson('/api/projects/'.$id.'/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $contents)])->assertOk();

    $data = $this->getJson('/api/allocations/projects/'.$id)->assertOk()
        ->assertJsonPath('data.logo_requires_auth', true)->json('data');
    $response = $this->get($data['logo_url'])->assertOk();
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($contents);
});

test('unallocated visible resources have authenticated avatar URLs using the shared private storage convention', function () {
    Storage::fake('local');
    $actor = integratedAllocationAdmin();
    $user = User::factory()->create();
    $user->filestorage->update(['path' => 'users/avatars', 'name' => 'integration-avatar', 'extension' => 'png', 'type' => 'image']);
    Storage::disk('local')->put('users/avatars/integration-avatar.png', 'private-avatar-content');
    $this->withToken($actor->createToken('test')->plainTextToken);

    $data = $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()
        ->assertJsonPath('data.avatar_requires_auth', true)->json('data');
    $response = $this->get($data['avatar_url'])->assertOk();
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('private-avatar-content');
    $user->update(['isAllocation_visible' => false]);
    $this->get($data['avatar_url'])->assertNotFound();
});

test('shared model derives allocation type when project management creates an assignment without the new field', function () {
    $actor = integratedAllocationAdmin();
    $user = User::factory()->create();
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Front-end']);
    $project = Project::factory()->create(['is_timesheet' => true, 'project_manager_id' => $actor->id]);

    $row = $project->projectUsers()->create(['user_id' => $user->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 0]);

    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'allocation_type' => 'timesheet', 'assignmentrole_id' => $role->id]);
});

test('compatibility migration transfers legacy responsibility fields without replacing assignment history', function () {
    $actor = integratedAllocationAdmin();
    $leader = User::factory()->create();
    $scrum = User::factory()->create();
    $leaderRole = AssignmentRole::create(['alias' => 'Team Leader']);
    $scrumRole = AssignmentRole::create(['alias' => 'Scrum Master']);
    $project = Project::factory()->create(['project_manager_id' => $actor->id, 'team_leader_id' => $leader->id, 'scrum_master_id' => $scrum->id]);
    $migration = require database_path('migrations/2026_11_02_135900_backfill_legacy_project_responsibilities.php');

    $migration->up();
    $migration->up();

    $this->assertDatabaseCount('project_user', 3);
    $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $leader->id, 'assignmentrole_id' => $leaderRole->id]);
    $leaderRow = ProjectUser::where('assignmentrole_id', $leaderRole->id)->firstOrFail();
    $leaderRow->delete();
    $migration->up();
    $this->assertDatabaseCount('project_user', 3);
    $this->assertSoftDeleted($leaderRow);
});

test('legacy responsibility conflicts stop the transfer without overwriting or partially inserting assignments', function () {
    $actor = integratedAllocationAdmin();
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $project = Project::factory()->create(['project_manager_id' => $actor->id]);
    $row = $project->projectUsers()->create(['user_id' => $actor->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 80]);
    $migration = require database_path('migrations/2026_11_02_135900_backfill_legacy_project_responsibilities.php');

    expect(fn () => $migration->up())->toThrow(ValidationException::class);

    $this->assertDatabaseCount('project_user', 1);
    $this->assertDatabaseHas('project_user', ['id' => $row->id, 'work_allocation' => 80]);
});

test('PM needs are included and can be removed through allocation', function () {
    $actor = integratedAllocationAdmin();
    $project = Project::factory()->create(['project_manager_id' => $actor->id]);
    $pm = AssignmentRole::where('alias', 'Project Manager')->firstOrFail();
    Necessity::create(['project_id' => $project->id, 'assignmentrole_id' => $pm->id]);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/allocations/projects/'.$project->id.'/necessities', ['assignment_role_ids' => []])->assertOk()->assertJsonCount(0, 'data');

    $this->assertDatabaseMissing('necessities', ['project_id' => $project->id, 'assignmentrole_id' => $pm->id]);
});

test('allocation adds multiple PMs and replaces a scrum master while project management preserves their history', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $actor = integratedAllocationAdmin();
    $otherPm = User::factory()->create();
    $ordinaryRole = Role::firstOrCreate(['alias' => 'User']);
    $otherPm->roles()->attach($ordinaryRole);
    $firstScrum = User::factory()->create();
    $nextScrum = User::factory()->create();
    $pm = AssignmentRole::where('alias', 'Project Manager')->firstOrFail();
    $scrum = AssignmentRole::create(['alias' => 'Scrum Master']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $id = $this->postJson('/api/projects', integratedNormalProjectPayload($actor))->assertCreated()->json('data.id');

    $pmId = $this->postJson('/api/allocations', ['type' => 'project', 'project_id' => $id, 'user_id' => $otherPm->id,
        'assignment_role_id' => $pm->id, 'start_date' => '2026-01-01'])->assertCreated()->assertJsonPath('data.role.code', 'PM')->json('data.id');
    $this->getJson('/api/projects/'.$id)->assertOk()->assertJsonCount(2, 'data.project_managers');
    expect($otherPm->roles()->sole()->id)->toBe($ordinaryRole->id);
    $scrumPayload = ['type' => 'project', 'project_id' => $id, 'user_id' => $firstScrum->id,
        'assignment_role_id' => $scrum->id, 'start_date' => '2026-01-01'];
    $firstId = $this->postJson('/api/allocations', $scrumPayload)->assertCreated()->assertJsonPath('data.role.code', 'SM')->json('data.id');
    $this->getJson('/api/projects/'.$id)->assertOk()->assertJsonPath('data.scrum_master_id', $firstScrum->id);
    $this->postJson('/api/allocations', array_merge($scrumPayload, ['user_id' => $nextScrum->id]))->assertUnprocessable();
    $this->postJson('/api/allocations/'.$firstId.'/conclude', ['end_date' => '2026-10-31'])->assertOk();
    $this->postJson('/api/allocations', array_merge($scrumPayload, ['user_id' => $nextScrum->id, 'start_date' => '2026-11-01']))->assertCreated();
    $this->getJson('/api/projects/'.$id)->assertOk()->assertJsonPath('data.scrum_master_id', $nextScrum->id);
    $this->postJson('/api/allocations/'.$pmId.'/conclude', ['end_date' => '2026-10-31'])->assertOk();
    $this->getJson('/api/projects/'.$id)->assertOk()->assertJsonCount(1, 'data.project_managers')
        ->assertJsonPath('data.project_managers.0.id', $actor->id);
    $this->getJson('/api/allocations/resources/'.$otherPm->id)->assertOk()
        ->assertJsonPath('data.projects_count', 0)->assertJsonPath('data.allocations.0.role.code', 'PM');
    $this->assertDatabaseHas('project_user', ['id' => $firstId, 'deleted_at' => null]);
    $this->assertDatabaseHas('project_user', ['id' => $pmId, 'deleted_at' => null]);
});

test('all seven missing roles including PM and scrum master can be saved together', function () {
    $actor = integratedAllocationAdmin();
    $roles = collect(['Sviluppatore Front-end', 'Sviluppatore Back-end', 'Sviluppatore Full-stack', 'UX-UI Designer',
        'Team Leader', 'Project Manager', 'Scrum Master'])
        ->map(fn ($alias) => AssignmentRole::firstOrCreate(['alias' => $alias]));
    $project = Project::factory()->create(['project_manager_id' => $actor->id]);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/allocations/projects/'.$project->id.'/necessities', ['assignment_role_ids' => $roles->pluck('id')->all()])
        ->assertOk()->assertJsonCount(7, 'data');

    $this->assertDatabaseCount('necessities', 7);
});

test('full seed creates timesheet assignments visible in allocation without putting them in the project tab', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'mario.rossi@example.com')->firstOrFail();
    $user = User::where('email', 'giulia.neri@example.com')->firstOrFail();
    $timesheet = Project::where('is_timesheet', true)->sole();
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->getJson('/api/allocations?type=project')->assertOk()->assertJsonCount(2, 'data.projects');
    $this->getJson('/api/allocations/resources/'.$user->id.'?type=timesheet')->assertOk()
        ->assertJsonPath('data.projects_count', 1)->assertJsonPath('data.is_unallocated', false)
        ->assertJsonPath('data.allocations.0.role', null);
    $this->assertDatabaseHas('project_user', ['project_id' => $timesheet->id, 'user_id' => $user->id, 'allocation_type' => 'timesheet']);
});
