<?php

use App\Models\AssignmentRole;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

function allocationDisablingAdmin(): User
{
    $admin = User::factory()->create(['isAllocation_visible' => false]);
    $role = Role::firstOrCreate(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::firstOrCreate(['name' => 'users.manage'], ['description' => 'Manage users']));
    $admin->roles()->attach($role);

    return $admin;
}

function disablingAssignment(User $user, User $owner, array $attributes = [], array $projectAttributes = []): ProjectUser
{
    $project = Project::factory()->create(array_merge(['project_manager_id' => $owner->id], $projectAttributes));
    $role = AssignmentRole::firstOrCreate(['alias' => 'Sviluppatore Back-end']);

    return $project->projectUsers()->create(array_merge([
        'user_id' => $user->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 50,
    ], $attributes));
}

dataset('allocation disabling endpoints', [
    'disable' => ['POST', '/disable'],
    'legacy delete' => ['DELETE', ''],
]);

test('disabling ends all existing project timesheet and course periods without changing past history', function (string $method, string $suffix) {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $admin = allocationDisablingAdmin();
    $user = User::factory()->create();
    $leaderRole = AssignmentRole::firstOrCreate(['alias' => 'Team Leader']);
    $scrumRole = AssignmentRole::firstOrCreate(['alias' => 'Scrum Master']);
    $pmRole = AssignmentRole::firstOrCreate(['alias' => 'Project Manager']);
    $rows = collect([
        disablingAssignment($user, $admin, ['assignmentrole_id' => $leaderRole->id]),
        disablingAssignment($user, $admin, ['assignmentrole_id' => $scrumRole->id]),
        disablingAssignment($user, $admin, ['assignmentrole_id' => $pmRole->id]),
        disablingAssignment($user, $admin, ['endwork_date' => '2026-12-31', 'is_difficult' => true]),
        disablingAssignment($user, $admin, ['assignmentrole_id' => null], ['is_timesheet' => true]),
        disablingAssignment($user, $admin, ['assignmentrole_id' => null], ['is_course' => true]),
        disablingAssignment($user, $admin, ['startwork_date' => '2026-11-01']),
    ]);
    $archivedProjectRow = disablingAssignment($user, $admin);
    $archivedProjectRow->project->delete();
    $rows->push($archivedProjectRow);
    $past = disablingAssignment($user, $admin, ['endwork_date' => '2026-10-15']);
    $pastBefore = $past->fresh()->getAttributes();
    $removed = disablingAssignment($user, $admin);
    $removed->delete();
    $removedBefore = $removed->fresh()->getAttributes();
    $other = User::factory()->create();
    $otherRow = disablingAssignment($other, $admin);
    $otherBefore = $otherRow->fresh()->getAttributes();
    $user->createToken('old-access');

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->json($method, '/api/users/'.$user->id.$suffix, ['resignation_date' => '2026-11-01'])->assertOk();

    $this->assertSoftDeleted($user);
    expect($user->tokens()->count())->toBe(0);
    foreach ($rows as $row) {
        expect($row->fresh()->endwork_date->toDateString())->toBe('2026-11-01');
        expect($row->fresh()->trashed())->toBeFalse();
        expect($row->fresh()->work_allocation)->toBe(50);
    }
    expect($past->fresh()->getAttributes())->toBe($pastBefore);
    expect($removed->fresh()->getAttributes())->toBe($removedBefore);
    expect($otherRow->fresh()->getAttributes())->toBe($otherBefore);
    expect($rows[3]->fresh()->is_difficult)->toBeTrue();
})->with('allocation disabling endpoints');

test('future assignments are cancelled without creating invalid periods and are not revived by user restoration', function (string $method, string $suffix) {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $admin = allocationDisablingAdmin();
    $user = User::factory()->create();
    $future = disablingAssignment($user, $admin, ['startwork_date' => '2026-11-03', 'endwork_date' => '2026-12-01']);
    $afterResignation = disablingAssignment($user, $admin, ['startwork_date' => '2026-10-25']);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->json($method, '/api/users/'.$user->id.$suffix, ['resignation_date' => '2026-10-20'])->assertOk();
    $this->postJson('/api/users/'.$user->id.'/restore')->assertOk();

    $this->assertSoftDeleted($future);
    $this->assertSoftDeleted($afterResignation);
    expect($future->fresh()->startwork_date->toDateString())->toBe('2026-11-03');
    expect($future->fresh()->endwork_date->toDateString())->toBe('2026-12-01');
    expect($afterResignation->fresh()->endwork_date)->toBeNull();
    expect($user->fresh()->trashed())->toBeFalse();
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()->assertJsonCount(0, 'data.allocations');
})->with('allocation disabling endpoints');

test('a replacement leader can start after resignation and restoring the old user preserves the new leader', function (string $method, string $suffix) {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $admin = allocationDisablingAdmin();
    $user = User::factory()->create();
    $successor = User::factory()->create();
    $role = AssignmentRole::firstOrCreate(['alias' => 'Team Leader']);
    $row = disablingAssignment($user, $admin, ['assignmentrole_id' => $role->id]);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->json($method, '/api/users/'.$user->id.$suffix, ['resignation_date' => '2026-11-01'])->assertOk();
    $this->postJson('/api/allocations', ['type' => 'project', 'project_id' => $row->project_id,
        'user_id' => $successor->id, 'assignment_role_id' => $role->id, 'start_date' => '2026-11-02'])->assertCreated();
    $this->postJson('/api/users/'.$user->id.'/restore')->assertOk();

    $this->getJson('/api/projects/'.$row->project_id)->assertOk()->assertJsonPath('data.team_leader_id', $successor->id);
    $this->getJson('/api/allocations/resources/'.$user->id)->assertOk()
        ->assertJsonPath('data.projects_count', 0)->assertJsonPath('data.allocations.0.end_date', '2026-11-01');
    expect($row->fresh()->trashed())->toBeFalse();
})->with('allocation disabling endpoints');

test('resignation day remains inclusive and replacement starts the following day', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $admin = allocationDisablingAdmin();
    $user = User::factory()->create();
    $successor = User::factory()->create();
    $role = AssignmentRole::firstOrCreate(['alias' => 'Team Leader']);
    $row = disablingAssignment($user, $admin, ['assignmentrole_id' => $role->id]);
    $this->withToken($admin->createToken('test')->plainTextToken);

    $this->postJson('/api/users/'.$user->id.'/disable', ['resignation_date' => '2026-11-02'])->assertOk();
    $payload = ['type' => 'project', 'project_id' => $row->project_id, 'user_id' => $successor->id,
        'assignment_role_id' => $role->id, 'start_date' => '2026-11-02'];
    $this->postJson('/api/allocations', $payload)->assertUnprocessable();
    $this->postJson('/api/allocations', array_merge($payload, ['start_date' => '2026-11-03']))->assertCreated();

    expect($row->fresh()->endwork_date->toDateString())->toBe('2026-11-02');
});

test('invalid resignation does not change assignments or cancel future work', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $admin = allocationDisablingAdmin();
    $user = User::factory()->create();
    $row = disablingAssignment($user, $admin);
    $future = disablingAssignment($user, $admin, ['startwork_date' => '2026-11-10']);
    $before = $row->fresh()->getAttributes();
    $futureBefore = $future->fresh()->getAttributes();

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->postJson('/api/users/'.$user->id.'/disable', ['resignation_date' => '2026-11-03'])->assertUnprocessable();

    expect($row->fresh()->getAttributes())->toBe($before);
    expect($future->fresh()->getAttributes())->toBe($futureBefore);
    expect($user->fresh()->trashed())->toBeFalse();
});

test('a late disable failure rolls back assignment dates cancellations user state and tokens', function () {
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Europe/Rome'));
    $admin = allocationDisablingAdmin();
    $user = User::factory()->create();
    $row = disablingAssignment($user, $admin);
    $future = disablingAssignment($user, $admin, ['startwork_date' => '2026-11-10']);
    $before = $row->fresh()->getAttributes();
    $futureBefore = $future->fresh()->getAttributes();
    $token = $user->createToken('keep-on-failure');
    Event::listen('eloquent.deleting: '.User::class, function (User $deleting) use ($user) {
        if ($deleting->id === $user->id) {
            throw new RuntimeException('Simulated disable failure');
        }
    });

    try {
        $this->withToken($admin->createToken('test')->plainTextToken)
            ->postJson('/api/users/'.$user->id.'/disable', ['resignation_date' => '2026-11-01'])->assertServerError();
    } finally {
        Event::forget('eloquent.deleting: '.User::class);
    }

    expect($row->fresh()->getAttributes())->toBe($before);
    expect($future->fresh()->getAttributes())->toBe($futureBefore);
    expect($user->fresh()->trashed())->toBeFalse();
    expect($user->fresh()->resignation_date)->toBeNull();
    $this->assertModelExists($token->accessToken);
});
