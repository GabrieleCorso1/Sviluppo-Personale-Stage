<?php

use App\Models\AssignmentRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

function userFormAdministrator(): User
{
    $actor = User::factory()->create();
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.manage', 'description' => 'Manage users']));
    $role->permissions()->attach(Permission::firstOrCreate(['name' => 'users.view'], ['description' => 'View users']));
    $actor->roles()->attach($role);

    return $actor;
}

function userFormPayload(User $actor, Role $role): array
{
    return [
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'nuovo@example.com',
        'azure_email' => 'nuovo@azure.example.com', 'company_email' => 'nuovo@company.example.com',
        'birth_date' => '1995-01-01', 'hiring_date' => '2026-11-01',
        'filestorage_id' => $actor->filestorage_id, 'contract_start_date' => '2026-11-01',
        'weekly_hours' => 20, 'role_id' => $role->id,
        'working_days' => array_map(fn ($day) => ['day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '13:00', 'is_working_day' => true], ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']),
    ];
}

test('creation persists the selected role assignment and expertise with user and contract data', function () {
    $actor = userFormAdministrator();
    $role = Role::create(['alias' => 'User']);
    $assignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $payload = userFormPayload($actor, $role) + ['assignment_role_id' => $assignment->id, 'expertise' => 'junior'];

    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', $payload)->assertCreated()
        ->assertJsonPath('data.roles.0.id', $role->id)
        ->assertJsonPath('data.assignment_roles.0.pivot.expertise', 'junior')
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    $user = User::findOrFail($response->json('data.id'));

    expect(Hash::check('Password123!', $user->password))->toBeFalse();
    $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $role->id]);
    $this->assertDatabaseHas('assignmentrole_user', ['user_id' => $user->id, 'assignmentrole_id' => $assignment->id, 'expertise' => 'junior']);
    $this->assertDatabaseHas('contract_working_days', ['contract_id' => $user->contracts()->firstOrFail()->id, 'day_of_week' => 'monday']);
});

test('creation accepts users without a professional assignment', function () {
    $actor = userFormAdministrator();
    $role = Role::create(['alias' => 'User']);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', userFormPayload($actor, $role))->assertCreated()
        ->assertJsonCount(0, 'data.assignment_roles')->assertJsonCount(1, 'data.roles');
});

test('editing replaces the single role and assignment and preserves the password', function () {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $oldRole = Role::create(['alias' => 'User']);
    $newRole = Role::create(['alias' => 'Team Leader']);
    $oldAssignment = AssignmentRole::create(['alias' => 'Sviluppatore Front-end']);
    $newAssignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $user->roles()->attach($oldRole);
    $user->assignmentRoles()->attach($oldAssignment, ['expertise' => 'junior']);
    $password = $user->password;

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, [
            'first_name' => 'Aggiornato', 'role_id' => $newRole->id,
            'assignment_role_id' => $newAssignment->id, 'expertise' => 'senior',
        ])->assertOk()->assertJsonPath('data.first_name', 'Aggiornato')
        ->assertJsonCount(1, 'data.roles')->assertJsonCount(1, 'data.assignment_roles');

    $this->assertDatabaseMissing('role_user', ['user_id' => $user->id, 'role_id' => $oldRole->id]);
    $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $newRole->id]);
    $this->assertDatabaseMissing('assignmentrole_user', ['user_id' => $user->id, 'assignmentrole_id' => $oldAssignment->id]);
    $this->assertDatabaseHas('assignmentrole_user', ['user_id' => $user->id, 'assignmentrole_id' => $newAssignment->id, 'expertise' => 'senior']);
    expect($user->fresh()->password)->toBe($password);
});

test('anagraphic edits preserve existing associations and allow the unchanged email', function () {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $role = Role::create(['alias' => 'User']);
    $assignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $user->roles()->attach($role);
    $user->assignmentRoles()->attach($assignment, ['expertise' => 'senior']);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['last_name' => 'Modificato', 'email' => $user->email])
        ->assertOk()->assertJsonPath('data.last_name', 'Modificato');
    $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $role->id]);
    $this->assertDatabaseHas('assignmentrole_user', ['user_id' => $user->id, 'assignmentrole_id' => $assignment->id, 'expertise' => 'senior']);
});

test('sending null assignment and expertise removes the assignment only', function () {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $role = Role::create(['alias' => 'User']);
    $assignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $user->roles()->attach($role);
    $user->assignmentRoles()->attach($assignment, ['expertise' => 'junior']);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['assignment_role_id' => null, 'expertise' => null])
        ->assertOk()->assertJsonCount(0, 'data.assignment_roles');
    expect($user->assignmentRoles()->count())->toBe(0);
    expect($user->roles()->count())->toBe(1);
});

test('invalid associations return 422 without modifying the user', function (array $changes, string $field) {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, $changes + ['first_name' => 'Invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect($user->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseMissing('role_user', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('assignmentrole_user', ['user_id' => $user->id]);
})->with([
    'unknown role' => [['role_id' => 99999], 'role_id'],
    'null role' => [['role_id' => null], 'role_id'],
    'unknown assignment' => [['assignment_role_id' => 99999, 'expertise' => 'junior'], 'assignment_role_id'],
    'expertise without assignment' => [['expertise' => 'senior'], 'assignment_role_id'],
    'invalid expertise' => [['expertise' => 'expert', 'assignment_role_id' => 99999], 'expertise'],
]);

test('assignment selection requires expertise and rejects deleted assignments', function () {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $assignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->putJson('/api/users/'.$user->id, ['assignment_role_id' => $assignment->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['expertise']);
    $assignment->delete();
    $this->putJson('/api/users/'.$user->id, ['assignment_role_id' => $assignment->id, 'expertise' => 'junior'])
        ->assertUnprocessable()->assertJsonValidationErrors(['assignment_role_id']);
    $this->assertDatabaseMissing('assignmentrole_user', ['user_id' => $user->id]);
});

test('creation requires a role and rejects incomplete assignment data without creating records', function () {
    $actor = userFormAdministrator();
    $role = Role::create(['alias' => 'User']);
    $payload = userFormPayload($actor, $role);
    unset($payload['role_id']);
    $payload['expertise'] = 'junior';

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id', 'assignment_role_id']);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('contracts', 0);
});

test('the edit endpoint returns related data and hides credentials', function () {
    $actor = userFormAdministrator();
    $role = Role::create(['alias' => 'User']);
    $this->withToken($actor->createToken('test')->plainTextToken);
    $created = $this->postJson('/api/users', userFormPayload($actor, $role))->assertCreated();

    $this->getJson('/api/users/'.$created->json('data.id'))->assertOk()
        ->assertJsonPath('data.roles.0.id', $role->id)
        ->assertJsonPath('data.contracts.0.weekly_hours', 20)
        ->assertJsonPath('data.contracts.0.working_days.0.day_of_week', 'monday')
        ->assertJsonPath('data.filestorage.id', $actor->filestorage_id)
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
});

test('the edit endpoint requires authentication and management permission', function () {
    $user = User::factory()->create();
    $this->getJson('/api/users/'.$user->id)->assertUnauthorized();
    $viewer = User::factory()->create();
    $role = Role::create(['alias' => 'User']);
    $role->permissions()->attach(Permission::create(['name' => 'users.view', 'description' => 'View users']));
    $viewer->roles()->attach($role);
    $this->withToken($viewer->createToken('test')->plainTextToken)
        ->getJson('/api/users/'.$user->id)->assertForbidden();
});

test('the edit endpoint returns 404 for missing and disabled users', function () {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $user->delete();
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->getJson('/api/users/'.$user->id)->assertNotFound();
    $this->getJson('/api/users/99999')->assertNotFound();
});

test('expertise can change on its own while preserving the single professional assignment', function (string $from, string $to) {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $assignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $user->assignmentRoles()->attach($assignment, ['expertise' => $from]);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['expertise' => $to])
        ->assertOk()->assertJsonCount(1, 'data.assignment_roles')
        ->assertJsonPath('data.assignment_roles.0.id', $assignment->id)
        ->assertJsonPath('data.assignment_roles.0.pivot.expertise', $to);
    expect($user->assignmentRoles()->count())->toBe(1);
})->with([['junior', 'senior'], ['senior', 'junior']]);

test('expertise alone rejects invalid or empty levels without changing the assignment', function ($level) {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $assignment = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $user->assignmentRoles()->attach($assignment, ['expertise' => 'junior']);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['expertise' => $level])
        ->assertUnprocessable()->assertJsonValidationErrors('expertise');
    expect($user->assignmentRoles()->firstOrFail()->pivot->expertise)->toBe('junior');
})->with([['expert'], [null], [''], [['junior', 'senior']]]);

test('expertise alone cannot guess between legacy multiple assignments and rolls back profile changes', function () {
    $actor = userFormAdministrator();
    $user = User::factory()->create();
    $first = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $second = AssignmentRole::create(['alias' => 'Team Leader']);
    $user->assignmentRoles()->attach([$first->id => ['expertise' => 'junior'], $second->id => ['expertise' => 'senior']]);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['first_name' => 'Rollback', 'expertise' => 'senior'])
        ->assertUnprocessable()->assertJsonValidationErrors('assignment_role_id');
    expect($user->fresh()->first_name)->toBe($user->first_name);
    expect($user->assignmentRoles()->count())->toBe(2);
    expect($user->assignmentRoles()->whereKey($first->id)->firstOrFail()->pivot->expertise)->toBe('junior');
});

test('allocation visibility defaults to true and honors an explicit creation choice', function (array $extra, bool $expected) {
    $actor = userFormAdministrator();
    $role = Role::create(['alias' => 'User']);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', userFormPayload($actor, $role) + $extra)->assertCreated()
        ->assertJsonPath('data.isAllocation_visible', $expected);
})->with([
    'omitted' => [[], true],
    'explicit no' => [['isAllocation_visible' => false], false],
    'explicit yes' => [['isAllocation_visible' => true], true],
]);

test('editing other user fields preserves allocation visibility', function (bool $visible) {
    $actor = userFormAdministrator();
    $user = User::factory()->create(['isAllocation_visible' => $visible]);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->putJson('/api/users/'.$user->id, ['first_name' => 'Changed'])->assertOk()
        ->assertJsonPath('data.isAllocation_visible', $visible);
    expect($user->fresh()->isAllocation_visible)->toBe($visible);
})->with([true, false]);

test('an explicit null visibility is rejected rather than saved to a non nullable column', function () {
    $actor = userFormAdministrator();
    $role = Role::create(['alias' => 'User']);
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->postJson('/api/users', userFormPayload($actor, $role) + ['isAllocation_visible' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('isAllocation_visible');
});
