<?php

use App\Models\AssignmentRole;
use App\Models\FileStorage;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\AssignmentRoleSeeder;
use Database\Seeders\FileStorageSeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    AssignmentRole::create(['alias' => 'Project Manager']);
    $this->admin = User::factory()->create();
    $role = Role::create(['alias' => 'Admin', 'description' => 'Admin']);
    $this->admin->roles()->attach($role);
    Sanctum::actingAs($this->admin);
});

test('a timesheet accepts its form fields and defaults normal project fields', function () {
    $this->postJson('/api/projects', [
        'alias' => 'Timesheet interno',
        'is_timesheet' => true,
        'description' => 'Attivita interne',
        'azure_project_name' => 'Internal',
        'azure_team_name' => 'Team A',
        'bug_count_url' => 'https://example.com/bugs',
    ])->assertCreated()->assertJsonPath('data.is_daily', false);

    $project = Project::firstOrFail();
    expect($project->status)->toBeNull()
        ->and($project->priority)->toBeNull()
        ->and($project->project_manager_id)->toBeNull()
        ->and($project->start_date)->toBeNull();

    $this->patchJson('/api/projects/'.$project->id, ['description' => 'Aggiornata'])
        ->assertOk()->assertJsonPath('data.description', 'Aggiornata');
});

test('timesheets reject normal project fields even when empty or false', function ($field, $value) {
    $this->postJson('/api/projects', [
        'alias' => 'Timesheet', 'is_timesheet' => true, $field => $value,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Project::count())->toBe(0);

    $project = Project::create([
        'alias' => 'Timesheet', 'is_timesheet' => true,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $this->patchJson('/api/projects/'.$project->id, [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['project_manager_id', null], ['start_date', '2026-10-30'],
    ['status', 'in corso'], ['status', null], ['priority', 'alta'],
    ['is_daily', false], ['is_azure', true], ['is_ux_ui', false],
    ['team_leader_id', null], ['scrum_master_id', null],
    ['delivery_plan', null], ['end_date', null], ['is_course', false],
]);

test('normal projects still require their fields and allow valid partial updates', function () {
    $this->postJson('/api/projects', ['alias' => 'Normale', 'is_timesheet' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_manager_id', 'start_date', 'status', 'priority']);

    $response = $this->postJson('/api/projects', [
        'alias' => 'Normale', 'is_timesheet' => false,
        'project_manager_id' => $this->admin->id, 'start_date' => '2026-10-30',
        'status' => 'in corso', 'priority' => 'media',
    ])->assertCreated();

    $id = $response->json('data.id');
    $this->patchJson('/api/projects/'.$id, ['priority' => 'alta'])
        ->assertOk()->assertJsonPath('data.priority', 'alta');
    $this->patchJson('/api/projects/'.$id, ['priority' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('priority');
    $this->getJson('/api/projects?is_timesheet=0&status=in%20corso&priority=alta')
        ->assertOk()->assertJsonPath('total', 1);
});

test('timesheet lists reject status and priority filters', function () {
    $this->getJson('/api/projects?is_timesheet=1')->assertOk();
    $this->getJson('/api/projects?is_timesheet=1&status=in%20corso')
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/projects?is_timesheet=1&priority=')
        ->assertUnprocessable()->assertJsonValidationErrors('priority');
});

test('project responses expose a public default logo consistently', function () {
    $response = $this->postJson('/api/projects', ['alias' => 'Timesheet', 'is_timesheet' => true])
        ->assertCreated()
        ->assertJsonPath('data.logo_requires_auth', false)
        ->assertJsonPath('data.logo_url', url('/images/defaults/default-project.svg'));
    $id = $response->json('data.id');
    $this->getJson('/api/projects/'.$id)
        ->assertOk()->assertJsonPath('data.logo_requires_auth', false);
    $this->patchJson('/api/projects/'.$id, ['alias' => 'Nuovo nome'])
        ->assertOk()->assertJsonPath('data.logo_requires_auth', false);
    $this->getJson('/api/projects?is_timesheet=1')
        ->assertOk()->assertJsonPath('data.0.logo_url', url('/images/defaults/default-project.svg'));
});

test('uploaded logos remain private and expose their authenticated route', function () {
    Storage::fake('local');
    $project = Project::create([
        'alias' => 'Timesheet', 'is_timesheet' => true,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $file = UploadedFile::fake()->createWithContent('logo.png', $png);
    $this->post('/api/projects/'.$project->id.'/logo', ['logo' => $file], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.logo_requires_auth', true);
    $this->getJson('/api/projects/'.$project->id)
        ->assertOk()
        ->assertJsonPath('data.logo_url', route('projects.logo', $project))
        ->assertJsonPath('data.logo_requires_auth', true)
        ->assertJsonMissingPath('data.filestorage');
    $this->get('/api/projects/'.$project->id.'/logo')->assertOk();
    $stored = $project->fresh()->filestorage;
    Storage::disk('local')->assertExists($stored->path.'/'.$stored->name.'.'.$stored->extension);
    auth()->forgetGuards();
    $this->getJson('/api/projects/'.$project->id.'/logo')->assertUnauthorized();
});

test('participants include only current assignments and current roles', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00', config('app.timezone')));
    $project = Project::create([
        'alias' => 'Progetto partecipanti', 'is_timesheet' => true,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $developer = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $leader = AssignmentRole::create(['alias' => 'Team Leader']);
    $current = User::factory()->create(['first_name' => 'Luca']);
    $endsToday = User::factory()->create();
    $createAssignment = function ($user, $start, $end, $role = null) use ($project, $developer) {
        return $project->projectUsers()->create([
            'user_id' => $user->id, 'assignmentrole_id' => ($role ?? $developer)->id,
            'startwork_date' => $start, 'endwork_date' => $end, 'work_allocation' => 50,
        ]);
    };
    $createAssignment($current, '2026-10-30', null);
    $createAssignment($current, '2026-01-01', '2026-10-29', $leader);
    $createAssignment($endsToday, '2026-01-01', '2026-10-30');
    $createAssignment(User::factory()->create(), '2026-10-31', null);
    $createAssignment(User::factory()->create(), '2026-01-01', '2026-10-29');
    $createAssignment(User::factory()->create(), '2026-01-01', null)->delete();
    $deletedUser = User::factory()->create();
    $createAssignment($deletedUser, '2026-01-01', null);
    $deletedUser->delete();
    $other = $project->replicate();
    $other->alias = 'Altro progetto';
    $other->save();
    $other->projectUsers()->create([
        'user_id' => User::factory()->create()->id, 'assignmentrole_id' => $developer->id,
        'startwork_date' => '2026-01-01', 'endwork_date' => null, 'work_allocation' => 50,
    ]);

    $response = $this->getJson('/api/projects/'.$project->id.'/users')
        ->assertOk()->assertJsonPath('total', 2);
    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$current->id, $endsToday->id])->sort()->values()->all());
    $this->getJson('/api/projects/'.$project->id.'/users?search=Luca')
        ->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.assignment_roles.0.alias', 'Sviluppatore Back-end')
        ->assertJsonCount(1, 'data.0.assignment_roles');
    $this->travelBack();
});

test('project seeds keep multiple roles for one person without duplicating them on rerun', function () {
    $this->seed([
        FileStorageSeeder::class,
        AccessControlSeeder::class,
        AssignmentRoleSeeder::class,
        UserSeeder::class,
        ProjectSeeder::class,
    ]);
    $this->seed(ProjectSeeder::class);
    $luca = User::where('email', 'luca.verdi@example.com')->firstOrFail();

    foreach (['Portale Clienti', 'API Aziendali'] as $alias) {
        $project = Project::where('alias', $alias)->firstOrFail();
        expect($project->is_timesheet)->toBeFalse();
        $assignments = $project->projectUsers()->where('user_id', $luca->id)
            ->with('assignmentRole')->get();
        expect($assignments)->toHaveCount(3);
        expect($assignments->firstWhere('assignmentRole.alias', 'Sviluppatore Back-end')->work_allocation)->toBe(50);
        expect($assignments->firstWhere('assignmentRole.alias', 'Team Leader')->work_allocation)->toBe(0);
        $this->getJson('/api/projects/'.$project->id.'/users?search=Luca')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(3, 'data.0.assignment_roles');
    }
});

test('project team leader comes from current assignments rather than the legacy column', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00', config('app.timezone')));
    $oldLeader = User::factory()->create();
    $leader = User::factory()->create(['first_name' => 'Luca']);
    $role = AssignmentRole::create(['alias' => 'Team Leader']);
    $project = Project::create([
        'alias' => 'Progetto leader', 'is_timesheet' => false,
        'team_leader_id' => $oldLeader->id,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $project->projectUsers()->create([
        'user_id' => $oldLeader->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-01-01', 'endwork_date' => '2026-10-29', 'work_allocation' => 0,
    ]);
    $assignment = $project->projectUsers()->create([
        'user_id' => $leader->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-10-30', 'endwork_date' => '2026-11-01', 'work_allocation' => 0,
    ]);
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.team_leader_id', $leader->id)
        ->assertJsonPath('data.team_leader.first_name', 'Luca')
        ->assertJsonMissingPath('data.current_team_leader_assignment');
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()
        ->assertJsonPath('data.0.team_leader_id', $leader->id);
    $assignment->update(['endwork_date' => '2026-10-30']);
    $this->travelTo(Carbon::parse('2026-10-31 12:00:00', config('app.timezone')));
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.team_leader_id', null)->assertJsonPath('data.team_leader', null);
    expect($project->fresh()->team_leader_id)->toBe($oldLeader->id);
    $this->travelBack();
});

test('delivery plans are optional private PDFs that can be replaced', function () {
    Storage::fake('local');
    $project = Project::create([
        'alias' => 'Progetto PDF', 'is_timesheet' => false,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $endpoint = '/api/projects/'.$project->id.'/delivery-plan';
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.delivery_plan_url', null);
    $this->getJson($endpoint)->assertNotFound();
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    $this->post($endpoint, ['delivery_plan' => UploadedFile::fake()->createWithContent('plan.pdf', $pdf)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.delivery_plan_requires_auth', true)
        ->assertJsonPath('data.delivery_plan_url', route('projects.delivery-plan', $project));
    $firstPath = $project->fresh()->delivery_plan;
    Storage::disk('local')->assertExists($firstPath);
    $this->get($endpoint)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->post($endpoint, ['delivery_plan' => UploadedFile::fake()->createWithContent('replacement.pdf', $pdf)], ['Accept' => 'application/json'])
        ->assertOk();
    expect($project->fresh()->delivery_plan)->not->toBe($firstPath);
    $this->postJson($endpoint, [])->assertUnprocessable()->assertJsonValidationErrors('delivery_plan');
    $this->post($endpoint, ['delivery_plan' => UploadedFile::fake()->createWithContent('fake.pdf', 'plain text')->mimeType('text/plain')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('delivery_plan');
    $this->post($endpoint, ['delivery_plan' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('delivery_plan');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson($endpoint)->assertForbidden();
    $this->postJson($endpoint, [])->assertForbidden();
    auth()->forgetGuards();
    $this->getJson($endpoint)->assertUnauthorized();
    $this->postJson($endpoint, [])->assertUnauthorized();
});

test('timesheets cannot upload or read delivery plans', function () {
    Storage::fake('local');
    $project = Project::create([
        'alias' => 'Timesheet', 'is_timesheet' => true,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $this->post('/api/projects/'.$project->id.'/delivery-plan', [
        'delivery_plan' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->getJson('/api/projects/'.$project->id.'/delivery-plan')->assertNotFound();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('responsible person filters combine and team leader uses only current assignments', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00', config('app.timezone')));
    $leader = User::factory()->create();
    $other = User::factory()->create();
    $role = AssignmentRole::create(['alias' => 'Team Leader']);
    $base = [
        'is_timesheet' => false, 'is_daily' => false, 'is_azure' => false,
        'is_ux_ui' => false, 'is_course' => false, 'status' => 'in corso', 'priority' => 'alta',
        'project_manager_id' => $this->admin->id, 'scrum_master_id' => $other->id,
    ];
    $project = Project::create(array_merge($base, ['alias' => 'Attuale', 'team_leader_id' => $other->id]));
    $old = Project::create(array_merge($base, ['alias' => 'Passato', 'team_leader_id' => $leader->id]));
    foreach ([[$project, null], [$old, '2026-10-29']] as [$target, $end]) {
        $target->projectUsers()->create([
            'user_id' => $leader->id, 'assignmentrole_id' => $role->id,
            'startwork_date' => '2026-01-01', 'endwork_date' => $end, 'work_allocation' => 0,
        ]);
    }
    $scrumRole = AssignmentRole::create(['alias' => 'Scrum Master']);
    foreach ([$project, $old] as $target) {
        $target->projectUsers()->create([
            'user_id' => $other->id, 'assignmentrole_id' => $scrumRole->id,
            'startwork_date' => '2026-01-01', 'endwork_date' => null, 'work_allocation' => 0,
        ]);
    }
    foreach ([$project, $old] as $target) {
        $target->projectUsers()->create([
            'user_id' => $this->admin->id,
            'assignmentrole_id' => AssignmentRole::where('alias', 'Project Manager')->value('id'),
            'startwork_date' => '2026-01-01', 'endwork_date' => null, 'work_allocation' => 0,
        ]);
    }
    $url = '/api/projects?is_timesheet=0&team_leader_id='.$leader->id;
    $this->getJson($url)->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $project->id);
    $this->getJson($url.'&project_manager_id='.$this->admin->id.'&scrum_master_id='.$other->id.'&status=in%20corso&priority=alta')
        ->assertOk()->assertJsonPath('total', 1);
    $this->getJson($url.'&project_manager_id='.$other->id)->assertOk()->assertJsonPath('total', 0);
    $this->getJson('/api/projects?is_timesheet=0&scrum_master_id='.$other->id)
        ->assertOk()->assertJsonPath('total', 2);
    $this->travelBack();
});

test('responsible person filters reject invalid users and timesheet usage', function ($field) {
    $this->getJson('/api/projects?is_timesheet=0&'.$field.'=abc')
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->getJson('/api/projects?is_timesheet=0&'.$field.'=999999')
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $deleted = User::factory()->create();
    $deleted->delete();
    $this->getJson('/api/projects?is_timesheet=0&'.$field.'='.$deleted->id)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->getJson('/api/projects?is_timesheet=1&'.$field.'='.$this->admin->id)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['project_manager_id', 'team_leader_id', 'scrum_master_id']);

test('project scrum master comes from current assignments rather than the legacy column', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00', config('app.timezone')));
    $oldLeader = User::factory()->create();
    $leader = User::factory()->create(['first_name' => 'Luca']);
    $role = AssignmentRole::create(['alias' => 'Scrum Master']);
    $project = Project::create([
        'alias' => 'Progetto leader', 'is_timesheet' => false,
        'scrum_master_id' => $oldLeader->id,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $project->projectUsers()->create([
        'user_id' => $oldLeader->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-01-01', 'endwork_date' => '2026-10-29', 'work_allocation' => 0,
    ]);
    $assignment = $project->projectUsers()->create([
        'user_id' => $leader->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => '2026-10-30', 'endwork_date' => '2026-11-01', 'work_allocation' => 0,
    ]);
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.scrum_master_id', $leader->id)
        ->assertJsonPath('data.scrum_master.first_name', 'Luca')
        ->assertJsonMissingPath('data.current_scrum_master_assignment');
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()
        ->assertJsonPath('data.0.scrum_master_id', $leader->id);
    $assignment->update(['endwork_date' => '2026-10-30']);
    $this->travelTo(Carbon::parse('2026-10-31 12:00:00', config('app.timezone')));
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.scrum_master_id', null)->assertJsonPath('data.scrum_master', null);
    expect($project->fresh()->scrum_master_id)->toBe($oldLeader->id);
    $this->travelBack();
});

test('next sprint review uses the nearest non concluded sprint end date from today onward', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00', config('app.timezone')));
    $categoryId = DB::table('teamcategories')->insertGetId([
        'alias' => 'Test', 'description' => 'Test', 'ideal_velocity' => 1,
        'filestorage_id' => $this->admin->filestorage_id, 'color_code' => '#005080',
    ]);
    $project = Project::create([
        'alias' => 'Review', 'is_timesheet' => false,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $number = 0;
    $makeSprint = function ($end, $concluded = false) use ($project, $categoryId, &$number) {
        return $project->sprints()->create([
            'number' => ++$number, 'start_date' => '2026-10-01', 'end_date' => $end,
            'teamcategory_id' => $categoryId, 'is_concluded' => $concluded,
        ]);
    };
    $makeSprint('2026-10-29');
    $makeSprint('2026-10-30', true);
    $makeSprint('2026-10-30')->delete();
    $later = $makeSprint('2026-11-15');
    $nearest = $makeSprint('2026-11-01');
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.next_sprint_review_date', '2026-11-01');
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()
        ->assertJsonPath('data.0.next_sprint_review_date', '2026-11-01');
    $today = $makeSprint('2026-10-30');
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.next_sprint_review_date', '2026-10-30');
    foreach ([$today, $nearest, $later] as $sprint) {
        $sprint->update(['is_concluded' => true]);
    }
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.next_sprint_review_date', null);
    $this->travelBack();
});

function csvTestProject($admin, bool $timesheet = false): array
{
    $category = DB::table('teamcategories')->insertGetId([
        'alias' => 'CSV', 'description' => 'Test', 'ideal_velocity' => 1,
        'filestorage_id' => $admin->filestorage_id, 'color_code' => '#005080',
    ]);
    $project = Project::create([
        'alias' => 'CSV '.(Project::withTrashed()->count() + 1), 'is_timesheet' => $timesheet,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $sprint = $project->sprints()->create([
        'number' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-15',
        'teamcategory_id' => $category, 'is_concluded' => true,
    ]);

    return [$project, $sprint];
}

test('CSV groups and counters distinguish concluded billing pilot and ongoing sprints', function () {
    [$project, $missing] = csvTestProject($this->admin);
    $billing = $missing->replicate();
    $billing->fill(['number' => 2, 'is_billing' => true])->save();
    $pilot = $missing->replicate();
    $pilot->fill(['number' => 3, 'is_pilot' => true])->save();
    $pending = $missing->replicate();
    $pending->fill(['number' => 4, 'is_concluded' => false])->save();
    $removed = $missing->replicate();
    $removed->fill(['number' => 5])->save();
    $removed->delete();
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertOk()
        ->assertJsonPath('counts.missing', 2)->assertJsonPath('counts.not_required', 1)
        ->assertJsonPath('counts.pending', 1)->assertJsonPath('counts.uploaded', 0);
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.missing_csv_count', 2);
    $typeUrl = '/api/projects/'.$project->id.'/sprints/'.$missing->id.'/csv-type';
    $this->patchJson($typeUrl, ['is_billing' => true, 'is_pilot' => false])->assertOk()
        ->assertJsonPath('data.csv_status', 'not_required');
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.missing_csv_count', 1);
    $this->patchJson($typeUrl, ['is_billing' => false, 'is_pilot' => true])->assertOk()
        ->assertJsonPath('data.csv_status', 'missing');
    $this->patchJson($typeUrl, ['is_billing' => true, 'is_pilot' => true])->assertUnprocessable();
});

test('CSV attachments are stored downloaded and replaced without parsing contents', function () {
    Storage::fake('local');
    [$project, $sprint] = csvTestProject($this->admin);
    $url = '/api/projects/'.$project->id.'/sprints/'.$sprint->id.'/csv';
    $content = "qualsiasi;colonna\nnessuna;importazione\n";
    $file = fn () => UploadedFile::fake()->createWithContent('sprint.csv', $content)->mimeType('text/plain');
    $this->getJson($url)->assertNotFound();
    $this->post($url, ['csv' => $file()], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.csv_status', 'uploaded')->assertJsonPath('data.has_csv', true);
    $first = $sprint->fresh()->csv_path;
    expect(Storage::disk('local')->get($first))->toBe($content);
    $this->get($url)->assertOk()->assertDownload('sprint-1.csv');
    $this->post($url, ['csv' => $file()], ['Accept' => 'application/json'])->assertOk();
    expect($sprint->fresh()->csv_path)->not->toBe($first);
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertOk()->assertJsonPath('counts.uploaded', 1);
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.missing_csv_count', 0);
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('csv');
    $this->post($url, ['csv' => UploadedFile::fake()->create('file.xlsx', 1, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('csv');
    $this->post($url, ['csv' => UploadedFile::fake()->create('big.csv', 10241, 'text/csv')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('csv');
    $sprint->update(['is_billing' => true]);
    $this->post($url, ['csv' => $file()], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('CSV endpoints enforce authentication admin and project ownership', function () {
    Storage::fake('local');
    [$project, $sprint] = csvTestProject($this->admin);
    [$other] = csvTestProject($this->admin);
    $wrong = '/api/projects/'.$other->id.'/sprints/'.$sprint->id;
    $this->patchJson($wrong.'/csv-type', ['is_billing' => true, 'is_pilot' => false])->assertNotFound();
    $this->getJson($wrong.'/csv')->assertNotFound();
    [$timesheet, $timesheetSprint] = csvTestProject($this->admin, true);
    $this->getJson('/api/projects/'.$timesheet->id.'/csv')->assertNotFound();
    $this->patchJson('/api/projects/'.$timesheet->id.'/sprints/'.$timesheetSprint->id.'/csv-type', ['is_billing' => true, 'is_pilot' => false])
        ->assertNotFound();
    Sanctum::actingAs(User::factory()->create());
    $base = '/api/projects/'.$project->id.'/sprints/'.$sprint->id;
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertForbidden();
    $this->getJson($base.'/csv')->assertForbidden();
    $this->postJson($base.'/csv', [])->assertForbidden();
    $this->patchJson($base.'/csv-type', [])->assertForbidden();
    auth()->forgetGuards();
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertUnauthorized();
    $this->postJson($base.'/csv', [])->assertUnauthorized();
});

test('creation assigns the first PM and project editing cannot replace assignments', function () {
    $response = $this->postJson('/api/projects', [
        'alias' => 'PM iniziale', 'is_timesheet' => false,
        'project_manager_id' => $this->admin->id, 'start_date' => now()->toDateString(),
        'status' => 'in corso', 'priority' => 'media',
    ])->assertCreated()->assertJsonPath('data.project_managers.0.id', $this->admin->id)
        ->assertJsonMissingPath('data.project_manager_id');
    $id = $response->json('data.id');
    expect(Project::findOrFail($id)->projectUsers()->count())->toBe(1);
    $this->patchJson('/api/projects/'.$id, ['project_manager_id' => $this->admin->id])
        ->assertUnprocessable()->assertJsonValidationErrors('project_manager_id');
});

test('multiple equal PMs are shown and filtered using only current assignments', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00', config('app.timezone')));
    $project = Project::create([
        'alias' => 'PM multipli', 'is_timesheet' => false,
        'project_manager_id' => $this->admin->id,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false, 'is_course' => false,
    ]);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $ended = User::factory()->create();
    $future = User::factory()->create();
    $role = AssignmentRole::where('alias', 'Project Manager')->firstOrFail();
    foreach ([[$first, '2026-10-01', null], [$second, '2026-10-30', null], [$ended, '2026-01-01', '2026-10-29'], [$future, '2026-10-31', null]] as [$user, $start, $end]) {
        $project->projectUsers()->create([
            'user_id' => $user->id, 'assignmentrole_id' => $role->id,
            'startwork_date' => $start, 'endwork_date' => $end, 'work_allocation' => 0,
        ]);
    }
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonCount(2, 'data.project_managers');
    foreach ([$first, $second] as $user) {
        $this->getJson('/api/projects?is_timesheet=0&project_manager_id='.$user->id)
            ->assertOk()->assertJsonPath('total', 1);
    }
    foreach ([$ended, $future, $this->admin] as $user) {
        $this->getJson('/api/projects?is_timesheet=0&project_manager_id='.$user->id)
            ->assertOk()->assertJsonPath('total', 0);
    }
    $this->travelBack();
});

test('courses never appear in managed lists or counters even with both flags enabled', function () {
    $base = ['is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false];
    Project::create(array_merge($base, ['alias' => 'Normale', 'is_timesheet' => false, 'is_course' => false]));
    Project::create(array_merge($base, ['alias' => 'Timesheet', 'is_timesheet' => true, 'is_course' => false]));
    Project::create(array_merge($base, ['alias' => 'Corso', 'is_timesheet' => false, 'is_course' => true]));
    Project::create(array_merge($base, ['alias' => 'Corso doppio flag', 'is_timesheet' => true, 'is_course' => true]));
    foreach ([0, 1] as $type) {
        $this->getJson('/api/projects?is_timesheet='.$type)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('counts.projects', 1)
            ->assertJsonPath('counts.timesheets', 1)->assertJsonPath('data.0.is_course', false);
    }
});

test('management APIs reject course creation and conversion', function () {
    foreach ([true, false] as $value) {
        $this->postJson('/api/projects', ['alias' => 'Corso', 'is_timesheet' => true, 'is_course' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors('is_course');
    }
    [$project] = csvTestProject($this->admin);
    $this->patchJson('/api/projects/'.$project->id, ['is_course' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('is_course');
    expect($project->fresh()->is_course)->toBeFalse();
});

test('course IDs are inaccessible through project management routes without changing their data', function () {
    Storage::fake('local');
    [$course, $sprint] = csvTestProject($this->admin);
    $course->update(['is_course' => true]);
    $base = '/api/projects/'.$course->id;
    foreach ([$base, $base.'/users', $base.'/logo', $base.'/delivery-plan', $base.'/csv', $base.'/sprints/'.$sprint->id.'/csv'] as $url) {
        $this->getJson($url)->assertNotFound();
    }
    $this->patchJson($base, ['alias' => 'Alterato'])->assertNotFound();
    $this->patchJson($base.'/note', ['note' => 'Alterata'])->assertNotFound();
    $this->deleteJson($base)->assertNotFound();
    foreach ([$base.'/logo', $base.'/delivery-plan', $base.'/sprints/'.$sprint->id.'/csv'] as $url) {
        $this->postJson($url, [])->assertNotFound();
    }
    $this->patchJson($base.'/sprints/'.$sprint->id.'/csv-type', ['is_billing' => true, 'is_pilot' => false])->assertNotFound();
    expect($course->fresh()->alias)->toBe('CSV 1')->and($course->fresh()->deleted_at)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('names are reserved within each managed type including after soft deletion', function () {
    $payload = ['alias' => 'Nome riservato', 'is_timesheet' => true];
    $response = $this->postJson('/api/projects', $payload)->assertCreated();
    $id = $response->json('data.id');
    $this->postJson('/api/projects', $payload)->assertUnprocessable()->assertJsonValidationErrors('alias');
    $this->patchJson('/api/projects/'.$id, ['alias' => 'Nome riservato'])->assertOk();
    $this->postJson('/api/projects', [
        'alias' => 'Nome riservato', 'is_timesheet' => false,
        'project_manager_id' => $this->admin->id, 'start_date' => now()->toDateString(),
        'status' => 'in corso', 'priority' => 'media',
    ])->assertCreated();
    $second = $this->postJson('/api/projects', ['alias' => 'Altro nome', 'is_timesheet' => true])->assertCreated()->json('data.id');
    $this->patchJson('/api/projects/'.$second, ['alias' => 'Nome riservato'])
        ->assertUnprocessable()->assertJsonValidationErrors('alias');
    $this->deleteJson('/api/projects/'.$id)->assertOk();
    $this->postJson('/api/projects', $payload)->assertUnprocessable()->assertJsonValidationErrors('alias');
});

test('database uniqueness excludes courses and rejects duplicate managed names', function () {
    $base = ['alias' => 'Condiviso', 'is_timesheet' => true, 'is_course' => false,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false];
    $project = Project::create($base);
    $project->delete();
    Project::create(array_merge($base, ['is_course' => true]));
    Project::create(array_merge($base, ['is_course' => true]));
    Project::create(array_merge($base, ['is_timesheet' => false]));
    expect(fn () => DB::table('projects')->insert($base))->toThrow(UniqueConstraintViolationException::class);
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()->assertJsonMissingPath('data.0.managed_alias');
});

test('PM options expose minimal active user information with name search and pagination', function () {
    $person = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
    $deleted = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
    $deleted->delete();
    $response = $this->getJson('/api/project-options/users?search=Mario%20Rossi&per_page=1')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $person->id);
    expect(array_keys($response->json('data.0')))->toBe(['id', 'first_name', 'last_name']);
    $this->getJson('/api/project-options/users?per_page=101')->assertUnprocessable();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/project-options/users')->assertForbidden();
    auth()->forgetGuards();
    $this->getJson('/api/project-options/users')->assertUnauthorized();
});

test('participant avatars support defaults and private images without exposing unrelated users', function () {
    Storage::fake('local');
    [$project] = csvTestProject($this->admin);
    $participant = User::factory()->create(['first_name' => 'Partecipante']);
    $role = AssignmentRole::create(['alias' => 'Sviluppatore Back-end']);
    $assignment = $project->projectUsers()->create([
        'user_id' => $participant->id, 'assignmentrole_id' => $role->id,
        'startwork_date' => now()->subDay()->toDateString(), 'endwork_date' => null, 'work_allocation' => 0,
    ]);
    $url = '/api/projects/'.$project->id.'/users/'.$participant->id.'/avatar';
    $this->getJson('/api/projects/'.$project->id.'/users')->assertOk()
        ->assertJsonPath('data.0.avatar_url', url('/images/defaults/default-avatar.svg'))
        ->assertJsonPath('data.0.avatar_requires_auth', false);
    $this->get($url)->assertOk();
    $file = $participant->filestorage;
    $file->update(['path' => 'users/avatars', 'name' => 'test', 'extension' => 'png', 'type' => 'image']);
    Storage::disk('local')->put('users/avatars/test.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $this->getJson('/api/projects/'.$project->id.'/users')->assertOk()
        ->assertJsonPath('data.0.avatar_url', route('projects.users.avatar', [$project, $participant]))
        ->assertJsonPath('data.0.avatar_requires_auth', true);
    $this->get($url)->assertOk();
    $unrelated = User::factory()->create();
    $this->getJson('/api/projects/'.$project->id.'/users/'.$unrelated->id.'/avatar')->assertNotFound();
    $assignment->update(['endwork_date' => now()->subDay()->toDateString()]);
    $this->getJson($url)->assertNotFound();
    Sanctum::actingAs($unrelated);
    $this->getJson($url)->assertForbidden();
    auth()->forgetGuards();
    $this->getJson($url)->assertUnauthorized();
});

test('missing custom logo and delivery files do not advertise unavailable attachments', function () {
    Storage::fake('local');
    [$project] = csvTestProject($this->admin);
    $file = FileStorage::create([
        'path' => 'projects/logos', 'name' => 'missing', 'extension' => 'png', 'type' => 'image',
    ]);
    $project->update(['filestorage_id' => $file->id, 'delivery_plan' => 'projects/delivery-plans/missing.pdf']);
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.logo_url', url('/images/defaults/default-project.svg'))
        ->assertJsonPath('data.logo_requires_auth', false)
        ->assertJsonPath('data.delivery_plan_url', null)
        ->assertJsonPath('data.delivery_plan_requires_auth', false);
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()
        ->assertJsonPath('data.0.logo_requires_auth', false)
        ->assertJsonPath('data.0.delivery_plan_url', null)
        ->assertJsonMissingPath('data.0.sprints_requiring_csv');
    $this->get('/api/projects/'.$project->id.'/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->getJson('/api/projects/'.$project->id.'/delivery-plan')->assertNotFound();
    expect($project->fresh()->filestorage_id)->toBe($file->id)
        ->and($project->fresh()->delivery_plan)->toBe('projects/delivery-plans/missing.pdf');
});

test('missing physical CSV files use the same states and counts everywhere', function () {
    Storage::fake('local');
    [$project, $sprint] = csvTestProject($this->admin);
    $sprint->update(['csv_path' => 'sprints/csv/lost.csv', 'has_csv' => true]);
    $csvUrl = '/api/projects/'.$project->id.'/sprints/'.$sprint->id.'/csv';
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertOk()
        ->assertJsonPath('counts.missing', 1)->assertJsonPath('counts.uploaded', 0)
        ->assertJsonPath('data.missing.0.has_csv', false)->assertJsonPath('data.missing.0.csv_url', null);
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.missing_csv_count', 1);
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()->assertJsonPath('data.0.missing_csv_count', 1);
    $this->getJson($csvUrl)->assertNotFound();
    Storage::disk('local')->put('sprints/csv/lost.csv', "a;b\n1;2");
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertOk()->assertJsonPath('counts.uploaded', 1);
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.missing_csv_count', 0);
    Storage::disk('local')->delete('sprints/csv/lost.csv');
    $sprint->update(['is_billing' => true]);
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertOk()
        ->assertJsonPath('counts.not_required', 1)->assertJsonPath('counts.missing', 0);
    $this->getJson('/api/projects/'.$project->id)->assertOk()->assertJsonPath('data.missing_csv_count', 0);
    $sprint->update(['is_billing' => false, 'is_concluded' => false]);
    $this->getJson('/api/projects/'.$project->id.'/csv')->assertOk()->assertJsonPath('counts.pending', 1);
    expect($sprint->fresh()->csv_path)->toBe('sprints/csv/lost.csv');
});

test('project calendar uses Rome dates for participants leaders filters avatars and reviews while timestamps stay UTC', function ($utcInstant, $localDate) {
    config(['app.timezone' => 'UTC', 'projects.calendar_timezone' => 'Europe/Rome']);
    $this->travelTo(Carbon::parse($utcInstant, 'UTC'));
    [$project, $previousSprint] = csvTestProject($this->admin);
    $previousDate = Carbon::parse($localDate)->subDay()->toDateString();
    $previousSprint->update(['is_concluded' => false, 'start_date' => $previousDate, 'end_date' => $previousDate]);
    $todaySprint = $previousSprint->replicate();
    $todaySprint->fill(['number' => 2, 'start_date' => $localDate, 'end_date' => $localDate])->save();
    $tomorrow = Carbon::parse($localDate)->addDay()->toDateString();
    $nextSprint = $previousSprint->replicate();
    $nextSprint->fill(['number' => 3, 'start_date' => $tomorrow, 'end_date' => $tomorrow])->save();
    $people = [];
    foreach (['Project Manager', 'Team Leader', 'Scrum Master'] as $alias) {
        $role = AssignmentRole::firstOrCreate(['alias' => $alias]);
        $person = User::factory()->create();
        $people[$alias] = $person;
        $project->projectUsers()->create([
            'user_id' => User::factory()->create()->id, 'assignmentrole_id' => $role->id,
            'startwork_date' => $previousDate, 'endwork_date' => $previousDate, 'work_allocation' => 0,
        ]);
        $project->projectUsers()->create([
            'user_id' => $person->id, 'assignmentrole_id' => $role->id,
            'startwork_date' => $localDate, 'endwork_date' => $localDate, 'work_allocation' => 0,
        ]);
    }
    $response = $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.project_managers.0.id', $people['Project Manager']->id)
        ->assertJsonPath('data.team_leader.id', $people['Team Leader']->id)
        ->assertJsonPath('data.scrum_master.id', $people['Scrum Master']->id)
        ->assertJsonPath('data.next_sprint_review_date', $localDate);
    expect($response->json('data.created_at'))->toBe(Carbon::parse($utcInstant, 'UTC')->toISOString());
    $this->getJson('/api/projects/'.$project->id.'/users')->assertOk()->assertJsonPath('total', 3);
    $this->get('/api/projects/'.$project->id.'/users/'.$people['Team Leader']->id.'/avatar')->assertOk();
    foreach (['project_manager_id' => 'Project Manager', 'team_leader_id' => 'Team Leader', 'scrum_master_id' => 'Scrum Master'] as $field => $alias) {
        $this->getJson('/api/projects?is_timesheet=0&'.$field.'='.$people[$alias]->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.next_sprint_review_date', $localDate);
    }
    // Move to the next midnight in Rome, which is still the previous day in UTC.
    $nextMidnight = Carbon::parse($tomorrow.' 00:30:00', 'Europe/Rome')->setTimezone('UTC');
    $this->travelTo($nextMidnight);
    $this->getJson('/api/projects/'.$project->id)->assertOk()
        ->assertJsonPath('data.project_managers', [])->assertJsonPath('data.team_leader', null)
        ->assertJsonPath('data.scrum_master', null)->assertJsonPath('data.next_sprint_review_date', $tomorrow);
    $this->getJson('/api/projects/'.$project->id.'/users')->assertOk()->assertJsonPath('total', 0);
    $this->getJson('/api/projects/'.$project->id.'/users/'.$people['Team Leader']->id.'/avatar')->assertNotFound();
    expect(config('app.timezone'))->toBe('UTC');
    $this->travelBack();
})->with([
    ['2026-10-29 23:30:00', '2026-10-30'],
    ['2026-06-29 22:30:00', '2026-06-30'],
    ['2026-10-30 22:30:00', '2026-10-30'],
]);

test('database name conflicts after a successful precheck become alias validation errors', function () {
    $payload = [
        'alias' => 'Nome concorrente', 'is_timesheet' => true, 'is_course' => false,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false,
    ];
    $validator = Validator::make($payload, [
        'alias' => [Rule::unique('projects', 'alias')
            ->where(fn ($query) => $query->where('is_timesheet', true)->where('is_course', false))],
    ]);
    expect($validator->passes())->toBeTrue();
    // Another write wins after validation but before this request saves.
    DB::table('projects')->insert($payload);
    $exception = null;
    try {
        Project::create($payload);
    } catch (ValidationException $caught) {
        $exception = $caught;
    }
    expect($exception)->toBeInstanceOf(ValidationException::class);
    $httpResponse = app(ExceptionHandler::class)->render(
        Request::create('/api/projects', 'POST'), $exception
    );
    expect($httpResponse->getStatusCode())->toBe(422)
        ->and(json_decode($httpResponse->getContent(), true)['errors'])->toHaveKey('alias');
    $other = Project::create(array_merge($payload, ['alias' => 'Altro nome concorrente']));
    expect(fn () => $other->update(['alias' => 'Nome concorrente']))
        ->toThrow(ValidationException::class);
    expect($other->fresh()->alias)->toBe('Altro nome concorrente');
});
