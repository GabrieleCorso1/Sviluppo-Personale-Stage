<?php

use App\Models\AssignmentRole;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->startOfDay());
    $this->actor = User::factory()->create();
    $this->actor->roles()->attach(Role::firstOrCreate(['alias' => 'Project Manager']));
    $this->receiver = User::factory()->create();
    $this->outsider = User::factory()->create();
    $this->project = Project::factory()->create();
    $pm = AssignmentRole::firstOrCreate(['alias' => 'Project Manager']);
    $developer = AssignmentRole::firstOrCreate(['alias' => 'Sviluppatore Back-end']);
    foreach ([[$this->actor, $pm], [$this->receiver, $developer]] as [$user, $role]) {
        ProjectUser::create([
            'project_id' => $this->project->id, 'user_id' => $user->id,
            'assignmentrole_id' => $role->id, 'startwork_date' => '2026-01-01', 'work_allocation' => 0,
        ]);
    }
    $this->payload = [
        'project_id' => $this->project->id, 'receiver_id' => $this->receiver->id,
        'is_positive' => true, 'event_date' => '2026-11-01',
        'situation' => '<p>Durante la review</p>', 'behavior' => 'Hai chiarito i dubbi',
        'impact' => 'Il team ha lavorato meglio', 'suggestion' => 'Continua così', 'personal_notes' => 'Nota riservata',
    ];
    $this->withToken($this->actor->createToken('sbi-test')->plainTextToken);
});

function sbiLoginAs($test, User $user): void
{
    Auth::forgetGuards();
    $test->withToken($user->createToken('sbi-test')->plainTextToken);
}

function sbiNewRevision(array $payload): array
{
    unset($payload['project_id'], $payload['receiver_id']);

    return $payload;
}

test('SBI complete flow preserves replies across revisions and tracks views only for the recipient', function () {
    $created = $this->postJson('/api/sbis', $this->payload)->assertCreated()
        ->assertJsonPath('data.latest_revision.personal_notes', 'Nota riservata')->json('data');
    $id = $created['id'];
    $first = $created['latest_revision']['id'];
    $this->getJson('/api/sbis/'.$id)->assertOk()->assertJsonPath('data.latest_revision.viewed_at', null);
    sbiLoginAs($this, $this->receiver);
    $this->getJson('/api/notifications?category=sbi')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.data.sbi_id', $id);
    $this->getJson('/api/sbis?direction=received&sbi_id='.$id)->assertOk()
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('counts.unviewed', 1)
        ->assertJsonMissingPath('data.0.latest_revision.personal_notes');
    $view = $this->getJson('/api/sbis/'.$id)->assertOk()->assertJsonMissingPath('data.revisions.0.personal_notes')->json('data.latest_revision.viewed_at');
    expect($view)->not->toBeNull();
    $this->putJson("/api/sbis/$id/revisions/$first/reply", ['body' => '<strong>Grazie</strong>'])->assertOk();
    $this->putJson("/api/sbis/$id/revisions/$first/reply", ['body' => 'Risposta aggiornata'])->assertOk();
    $this->putJson("/api/sbis/$id/revisions/$first/reply", ['body' => 'Risposta aggiornata'])->assertOk();
    $this->assertDatabaseCount('sbi_replies', 1);
    sbiLoginAs($this, $this->actor);
    $this->getJson('/api/notifications?category=sbi')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.data.sbi_id', $id)->assertJsonPath('data.0.data.sbi_reply', true);
    $this->getJson('/api/sbis?direction=sent&sbi_id='.$id)->assertOk()->assertJsonPath('data.0.id', $id);
    $second = $this->putJson('/api/sbis/'.$id, sbiNewRevision($this->payload) + ['unused' => 'ignored'])->assertOk()
        ->assertJsonPath('data.revisions_count', 2)->assertJsonPath('data.latest_revision.revision_number', 2)
        ->assertJsonPath('data.latest_revision.viewed_at', null)->assertJsonPath('data.latest_revision.reply', null)
        ->assertJsonPath('data.revisions.0.reply.body', 'Risposta aggiornata')->json('data.latest_revision.id');
    sbiLoginAs($this, $this->receiver);
    $this->getJson('/api/sbis')->assertOk()->assertJsonPath('counts.unviewed', 1);
    $this->getJson('/api/sbis/'.$id.'?revision_id='.$first)->assertOk()->assertJsonPath('data.latest_revision.viewed_at', null);
    $this->getJson('/api/sbis/'.$id)->assertOk();
    $this->getJson('/api/sbis')->assertOk()->assertJsonPath('counts.unviewed', 0);
    sbiLoginAs($this, $this->actor);
    $this->deleteJson("/api/sbis/$id/revisions/$second")->assertOk()->assertJsonPath('data.latest_revision.id', $first);
    $this->putJson('/api/sbis/'.$id, sbiNewRevision($this->payload))->assertOk()->assertJsonPath('data.latest_revision.revision_number', 3);
    $this->deleteJson('/api/sbis/'.$id)->assertOk();
    $this->getJson('/api/sbis/'.$id)->assertNotFound();
    $this->assertSoftDeleted('sbis', ['id' => $id]);
});

test('SBI admin can read all feedback but cannot see private notes or answer for the recipient', function () {
    $sbi = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data');
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::firstOrCreate(['alias' => 'Admin']));
    sbiLoginAs($this, $admin);
    $id = $sbi['id'];
    $revision = $sbi['latest_revision']['id'];
    $this->getJson('/api/sbis?user_id='.$this->receiver->id)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.latest_revision.personal_notes');
    $this->getJson('/api/sbis/'.$id)->assertOk()->assertJsonMissingPath('data.revisions.0.personal_notes')
        ->assertJsonPath('data.latest_revision.viewed_at', null);
    $this->putJson('/api/sbis/'.$id, sbiNewRevision($this->payload))->assertForbidden();
    $this->putJson("/api/sbis/$id/revisions/$revision/reply", ['body' => 'Altro'])->assertForbidden();
    $this->deleteJson('/api/sbis/'.$id)->assertForbidden();
});

test('SBI outsiders and ordinary members cannot read details private images or other inboxes', function () {
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    sbiLoginAs($this, $this->outsider);
    $this->getJson('/api/sbis')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/sbis?user_id='.$this->receiver->id)->assertForbidden();
    $this->getJson('/api/sbis/'.$id)->assertForbidden();
    $this->getJson('/api/sbis/'.$id.'/logo')->assertForbidden();
    $this->getJson('/api/sbis/'.$id.'/users/'.$this->receiver->id.'/avatar')->assertForbidden();
    $this->postJson('/api/sbis', $this->payload)->assertForbidden();
});

test('SBI cross-project managers see only feedback for projects they manage', function () {
    $sbi = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data');
    $other = Project::factory()->create();
    $this->outsider->roles()->attach(Role::firstOrCreate(['alias' => 'Project Manager']));
    ProjectUser::create([
        'project_id' => $other->id, 'user_id' => $this->outsider->id,
        'assignmentrole_id' => AssignmentRole::where('alias', 'Project Manager')->firstOrFail()->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 0,
    ]);
    sbiLoginAs($this, $this->outsider);
    $this->getJson('/api/sbis?user_id='.$this->receiver->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/sbis/'.$sbi['id'])->assertForbidden();
    $this->postJson('/api/sbis', $this->payload)->assertForbidden();
});

test('SBI creation requires another recipient assigned at the event date', function () {
    $this->postJson('/api/sbis', array_replace($this->payload, ['receiver_id' => $this->outsider->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('receiver_id');
    $this->postJson('/api/sbis', array_replace($this->payload, ['receiver_id' => $this->actor->id]))->assertUnprocessable();
    $this->postJson('/api/sbis', array_replace($this->payload, ['event_date' => '2025-01-01']))->assertUnprocessable();
    $this->assertDatabaseCount('sbis', 0);
});

test('SBI validates complete revisions future dates and forbids identity spoofing', function (array $extra, string $field) {
    $this->postJson('/api/sbis', array_replace($this->payload, $extra))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('sbis', 0);
})->with([
    [['writer_id' => 999], 'writer_id'], [['revision_number' => 2], 'revision_number'],
    [['viewed_at' => '2026-01-01'], 'viewed_at'], [['event_date' => '2999-01-01'], 'event_date'],
    [['situation' => '<p><br></p>'], 'situation'], [['behavior' => ''], 'behavior'],
    [['is_positive' => 'invalid'], 'is_positive'],
]);

test('SBI rich text removes executable markup and unsafe links while retaining formatting', function () {
    $data = array_replace($this->payload, ['situation' => '<p onclick="alert(1)"><strong>Test</strong><a href="javascript:alert(1)">Link</a><img src="x" onerror="alert(1)"></p>']);
    $text = $this->postJson('/api/sbis', $data)->assertCreated()->json('data.latest_revision.situation');
    expect($text)->toContain('<strong>Test</strong>')->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('<img');
});

test('SBI cannot move a feedback to a different recipient or project during revision', function () {
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    $this->putJson('/api/sbis/'.$id, $this->payload)->assertUnprocessable()->assertJsonValidationErrors(['project_id', 'receiver_id']);
    $this->assertDatabaseCount('sbi_revisions', 1);
});

test('SBI nested revisions are scoped and last active revision is protected', function () {
    $first = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data');
    $second = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data');
    $id = $first['id'];
    $revision = $first['latest_revision']['id'];
    $foreign = $second['latest_revision']['id'];
    $this->deleteJson("/api/sbis/$id/revisions/$revision")->assertUnprocessable();
    $this->deleteJson("/api/sbis/$id/revisions/$foreign")->assertNotFound();
    $this->getJson("/api/sbis/$id?revision_id=$foreign")->assertNotFound();
    sbiLoginAs($this, $this->receiver);
    $this->putJson("/api/sbis/$id/revisions/$foreign/reply", ['body' => 'Risposta'])->assertNotFound();
    $this->assertDatabaseCount('sbi_replies', 0);
});

test('SBI lists use latest revision filters without marking it as read', function () {
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    $this->putJson('/api/sbis/'.$id, array_replace(sbiNewRevision($this->payload), ['is_positive' => false, 'event_date' => '2026-11-04']))->assertOk();
    $this->getJson('/api/sbis?direction=sent&is_positive=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('counts.sent', 1);
    $this->getJson('/api/sbis?direction=sent&is_positive=0&date_from=2026-11-03&date_to=2026-11-05')->assertOk()->assertJsonCount(1, 'data');
    sbiLoginAs($this, $this->receiver);
    $this->getJson('/api/sbis?response_status=pending&view_status=unviewed')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/sbis?date_to=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/sbis?per_page=0')->assertUnprocessable();
    $this->getJson('/api/sbis?date_from=2026-11-05&date_to=2026-11-01')->assertUnprocessable();
});

test('SBI options and attachments use feedback permissions', function () {
    $this->getJson('/api/sbis/options?project_id='.$this->project->id)->assertOk()->assertJsonCount(1, 'data.receivers');
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    sbiLoginAs($this, $this->receiver);
    $this->get('/api/sbis/'.$id.'/logo')->assertOk();
    $this->get('/api/sbis/'.$id.'/users/'.$this->actor->id.'/avatar')->assertOk();
    $this->get('/api/sbis/'.$id.'/users/'.$this->outsider->id.'/avatar')->assertNotFound();
});

test('SBI routes require authentication', function (string $method, string $path) {
    Auth::forgetGuards();
    $this->withHeader('Authorization', '')->json($method, $path)->assertUnauthorized();
})->with([
    ['GET', '/api/sbis'], ['POST', '/api/sbis'], ['GET', '/api/sbis/options'],
    ['GET', '/api/sbis/1'], ['PUT', '/api/sbis/1'], ['DELETE', '/api/sbis/1'],
    ['PUT', '/api/sbis/1/revisions/1/reply'], ['DELETE', '/api/sbis/1/revisions/1'],
    ['GET', '/api/sbis/1/logo'], ['GET', '/api/sbis/1/users/1/avatar'],
]);

test('SBI revision dates must remain within the recipient project assignment', function () {
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    $this->putJson('/api/sbis/'.$id, array_replace(sbiNewRevision($this->payload), ['event_date' => '2025-01-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('event_date');
    $this->assertDatabaseCount('sbi_revisions', 1);
});

test('SBI current project leaders can inspect team feedback while personal notes remain private', function () {
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    $this->outsider->roles()->attach(Role::firstOrCreate(['alias' => 'Team Leader']));
    ProjectUser::create([
        'project_id' => $this->project->id, 'user_id' => $this->outsider->id,
        'assignmentrole_id' => AssignmentRole::firstOrCreate(['alias' => 'Team Leader'])->id,
        'startwork_date' => '2026-01-01', 'work_allocation' => 0,
    ]);
    sbiLoginAs($this, $this->outsider);
    $this->getJson('/api/sbis?user_id='.$this->receiver->id)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.latest_revision.personal_notes');
    $this->getJson('/api/sbis/'.$id)->assertOk()->assertJsonMissingPath('data.revisions.0.personal_notes')
        ->assertJsonPath('data.latest_revision.viewed_at', null);
});

test('SBI history remains readable to its participants after a project or author is disabled', function () {
    $id = $this->postJson('/api/sbis', $this->payload)->assertCreated()->json('data.id');
    $this->project->delete();
    $this->actor->delete();
    sbiLoginAs($this, $this->receiver);
    $this->getJson('/api/sbis/'.$id)->assertOk()->assertJsonPath('data.writer.id', $this->actor->id);
    $this->getJson('/api/sbis')->assertOk()->assertJsonCount(1, 'data');
});

test('SBI user search exposes only minimal team member fields to project leaders', function () {
    $this->getJson('/api/sbis/users')->assertOk()->assertJsonPath('data.total', 2)
        ->assertJsonMissingPath('data.data.0.email')->assertJsonMissingPath('data.data.0.birth_date');
    $this->getJson('/api/sbis/users?search='.urlencode($this->receiver->first_name.' '.$this->receiver->last_name))
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $this->receiver->id);
    sbiLoginAs($this, $this->receiver);
    $this->getJson('/api/sbis/users')->assertForbidden();
});
