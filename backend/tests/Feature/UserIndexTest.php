<?php

use App\Models\AssignmentRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function userListViewer(): User
{
    $actor = User::factory()->create(['first_name' => 'Viewer', 'last_name' => 'Tester', 'email' => 'viewer@fixture.test']);
    $role = Role::create(['alias' => 'Admin']);
    $role->permissions()->attach(Permission::create(['name' => 'users.view', 'description' => 'View users']));
    $actor->roles()->attach($role);

    return $actor;
}

test('active and disabled users have separate paginated lists', function () {
    $actor = userListViewer();
    $active = User::factory()->create(['first_name' => 'Anna']);
    $disabled = User::factory()->create(['resignation_date' => '2026-10-31']);
    $disabled->delete();
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->getJson('/api/users?per_page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.data.0.id', $active->id);
    $this->getJson('/api/users?status=disabled&per_page=1')->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $disabled->id);
    $this->getJson('/api/users?page=2&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.id', $actor->id);
});

test('search matches first names surnames full names and existing email searches', function (string $search) {
    $actor = userListViewer();
    $match = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario.rossi@example.com']);
    User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Bianchi', 'email' => 'other@fixture.test']);
    $disabled = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
    $disabled->delete();

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?'.http_build_query(['search' => $search]))->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $match->id);
})->with(['surname' => 'Rossi', 'full name' => 'Mario Rossi', 'reverse name' => 'Rossi Mario', 'email' => 'mario.rossi@example.com', 'extra spaces' => '  Mario   Rossi  ']);

test('blank searches do not filter the list', function () {
    $actor = userListViewer();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?search=')->assertOk()->assertJsonPath('data.total', 1);
});

test('role allocation internship and gender filters combine without dropping false values', function () {
    $actor = userListViewer();
    $role = Role::create(['alias' => 'User']);
    $match = User::factory()->create(['isAllocation_visible' => false, 'is_intern' => false, 'gender' => 'female']);
    $match->roles()->attach($role);
    $other = User::factory()->create(['isAllocation_visible' => true, 'is_intern' => false, 'gender' => 'female']);
    $other->roles()->attach($role);
    User::factory()->create(['isAllocation_visible' => false, 'is_intern' => false, 'gender' => 'female']);
    $intern = User::factory()->create(['isAllocation_visible' => false, 'is_intern' => true, 'gender' => 'female']);
    $intern->roles()->attach($role);

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?'.http_build_query([
            'role_id' => $role->id, 'isAllocation_visible' => 0, 'is_intern' => 0, 'gender' => 'female',
        ]))->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $match->id);
});

test('expertise must belong to the selected assignment role', function () {
    $actor = userListViewer();
    $backend = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $frontend = AssignmentRole::create(['alias' => 'Sviluppatore Front-end']);
    $match = User::factory()->create();
    $match->assignmentRoles()->attach($backend, ['expertise' => 'senior']);
    $other = User::factory()->create();
    $other->assignmentRoles()->attach($backend, ['expertise' => 'junior']);
    $other->assignmentRoles()->attach($frontend, ['expertise' => 'senior']);
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->getJson('/api/users?assignment_role_id='.$backend->id.'&expertise=senior')->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $match->id)
        ->assertJsonPath('data.data.0.assignment_roles.0.pivot.expertise', 'senior');
    $this->getJson('/api/users?expertise=senior')->assertOk()->assertJsonPath('data.total', 2);
    $this->getJson('/api/users?assignment_role_id='.$backend->id)->assertOk()->assertJsonPath('data.total', 2);
});

test('name ordering supports both directions with stable pagination', function (string $direction, array $expected) {
    $actor = userListViewer();
    $first = User::factory()->create(['first_name' => 'Anna', 'last_name' => 'Bianchi']);
    $second = User::factory()->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $third = User::factory()->create(['first_name' => 'Zeno', 'last_name' => 'Verdi']);
    $ids = [$first->id, $second->id, $third->id];
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?search=example&sort_by=name&sort_direction='.$direction)->assertOk();
    // Exclude the viewer by id because its generated name participates in the same ordering.
    $actual = collect($response->json('data.data'))->pluck('id')->reject(fn ($id) => $id === $actor->id)->values()->all();
    expect($actual)->toBe(array_map(fn ($index) => $ids[$index], $expected));
})->with(['ascending' => ['asc', [0, 1, 2]], 'descending' => ['desc', [2, 1, 0]]]);

test('date ordering supports hiring and resignation dates in both directions', function (string $field, string $status, string $direction, bool $earliestFirst) {
    $actor = userListViewer();
    $first = User::factory()->create(['first_name' => 'Target', $field => '2020-01-01']);
    $second = User::factory()->create(['first_name' => 'Target', $field => '2025-01-01']);
    if ($status === 'disabled') {
        $first->delete();
        $second->delete();
    }
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?'.http_build_query([
            'search' => 'Target', 'status' => $status, 'sort_by' => $field, 'sort_direction' => $direction,
        ]))->assertOk();

    expect(collect($response->json('data.data'))->pluck('id')->all())
        ->toBe($earliestFirst ? [$first->id, $second->id] : [$second->id, $first->id]);
})->with([
    ['hiring_date', 'active', 'asc', true], ['hiring_date', 'active', 'desc', false],
    ['resignation_date', 'disabled', 'asc', true], ['resignation_date', 'disabled', 'desc', false],
]);

test('pagination links retain validated filters and sorting', function () {
    $actor = userListViewer();
    User::factory()->count(2)->create(['first_name' => 'Target']);
    $response = $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?search=Target&per_page=1&sort_by=hiring_date&sort_direction=desc')->assertOk();
    parse_str(parse_url($response->json('data.next_page_url'), PHP_URL_QUERY), $query);

    expect($query)->toMatchArray(['search' => 'Target', 'per_page' => '1', 'sort_by' => 'hiring_date', 'sort_direction' => 'desc', 'page' => '2']);
});

test('invalid list parameters return 422', function (array $parameters, string $field) {
    $actor = userListViewer();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?'.http_build_query($parameters))->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'unknown status' => [['status' => 'all'], 'status'],
    'invalid page size' => [['per_page' => 0], 'per_page'],
    'excessive page size' => [['per_page' => 101], 'per_page'],
    'non numeric page size' => [['per_page' => 'all'], 'per_page'],
    'invalid page' => [['page' => -1], 'page'],
    'arbitrary sort column' => [['sort_by' => 'password'], 'sort_by'],
    'invalid direction' => [['sort_direction' => 'desc; DROP TABLE users'], 'sort_direction'],
    'unknown role' => [['role_id' => 99999], 'role_id'],
    'unknown assignment' => [['assignment_role_id' => 99999], 'assignment_role_id'],
    'invalid expertise' => [['expertise' => 'expert'], 'expertise'],
    'invalid allocation' => [['isAllocation_visible' => 'yes'], 'isAllocation_visible'],
    'invalid internship' => [['is_intern' => 'yes'], 'is_intern'],
    'invalid gender' => [['gender' => 'invalid'], 'gender'],
    'array search' => [['search' => ['Mario']], 'search'],
    'long search' => [['search' => str_repeat('a', 256)], 'search'],
]);

test('filter options expose roles and active assignments with expertise values', function () {
    $actor = userListViewer();
    $active = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $deleted = AssignmentRole::create(['alias' => 'Sviluppatore Front-end']);
    $deleted->delete();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users/options')->assertOk()
        ->assertJsonFragment(['alias' => 'Admin'])
        ->assertJsonCount(1, 'data.assignment_roles')
        ->assertJsonPath('data.assignment_roles.0.id', $active->id)
        ->assertJsonPath('data.expertise', ['junior', 'senior']);

    $this->getJson('/api/users?assignment_role_id='.$deleted->id)->assertUnprocessable()
        ->assertJsonValidationErrors(['assignment_role_id']);
});

test('filter options return 401 without authentication and 403 without view permission', function () {
    $this->getJson('/api/users/options')->assertUnauthorized();
    $actor = User::factory()->create();
    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users/options')->assertForbidden();
});
test('equal names use ids to keep consecutive pages stable', function () {
    $actor = userListViewer();
    $first = User::factory()->create(['first_name' => 'Target', 'last_name' => 'Rossi']);
    $second = User::factory()->create(['first_name' => 'Target', 'last_name' => 'Rossi']);
    $this->withToken($actor->createToken('test')->plainTextToken);

    $this->getJson('/api/users?search=Target&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.data.0.id', $first->id);
    $this->getJson('/api/users?search=Target&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.id', $second->id);
});

test('disabled users support the same filters without including active users', function () {
    $actor = userListViewer();
    $role = Role::create(['alias' => 'User']);
    $match = User::factory()->create(['first_name' => 'Target', 'isAllocation_visible' => false]);
    $match->roles()->attach($role);
    $match->delete();
    $active = User::factory()->create(['first_name' => 'Target', 'isAllocation_visible' => false]);
    $active->roles()->attach($role);
    $other = User::factory()->create(['first_name' => 'Target', 'isAllocation_visible' => true]);
    $other->roles()->attach($role);
    $other->delete();

    $this->withToken($actor->createToken('test')->plainTextToken)
        ->getJson('/api/users?status=disabled&search=Target&isAllocation_visible=0&role_id='.$role->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $match->id);
});
