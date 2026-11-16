<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

function addCalendarLeaveBalance(User $user): void
{
    $contract = $user->contracts()->create([
        'contract_start_date' => '2025-01-01',
        'weekly_hours' => 40,
        'contract_type' => 'indeterminato',
    ]);
    $contract->workingDays()->createMany(\App\WorkSchedule::fullTime());
    $user->leaveBalances()->create([
        'leave_type' => 'ferie',
        'reference_month' => '2026-12-01',
        'remaining_hours' => 16,
        'accrued_hours' => 16,
        'monthly_accrual' => 0,
    ]);
}

test('admins can manage only their own in-app notifications', function () {
    $role = Role::firstOrCreate(['alias' => 'Admin'], ['description' => 'Admin']);
    $admin = User::factory()->create();
    $other = User::factory()->create();
    $admin->roles()->attach($role);

    $admin->notify(new InAppNotification('calendar', 'New request'));
    $admin->notify(new InAppNotification('wiki', 'New resource'));
    $other->notify(new InAppNotification('calendar', 'Private request'));
    $ownId = $admin->notifications()->where('data->category', 'calendar')->firstOrFail()->id;
    $otherId = $other->notifications()->firstOrFail()->id;
    $url = '/api/admin/notifications';

    Sanctum::actingAs($other);
    $this->getJson($url)->assertForbidden();
    $this->patchJson($url, ['ids' => [$ownId], 'read' => true])->assertForbidden();
    $this->deleteJson($url, ['ids' => [$ownId]])->assertForbidden();

    Sanctum::actingAs($admin);
    $this->getJson($url.'?category=calendar&unread=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownId)
        ->assertJsonPath('unread_count', 2)->assertJsonPath('unread_by_category.calendar', 1);

    $ids = ['ids' => [$ownId, $otherId]];
    $this->patchJson($url, [...$ids, 'read' => true])->assertOk()->assertJsonPath('affected', 1);
    $this->getJson($url.'?category=calendar&unread=1')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson($url, [...$ids, 'read' => false])->assertOk()->assertJsonPath('affected', 1);
    $this->deleteJson($url, $ids)->assertOk()->assertJsonPath('affected', 1);
    $this->assertDatabaseHas('notifications', ['id' => $otherId, 'read_at' => null]);
    $this->assertDatabaseMissing('notifications', ['id' => $ownId]);
});

test('calendar requests notify admins and can be decided once', function () {
    $role = Role::firstOrCreate(['alias' => 'Admin'], ['description' => 'Admin']);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $requester = User::factory()->create();
    addCalendarLeaveBalance($requester);
    $first->roles()->attach($role);
    $second->roles()->attach($role);
    \App\Models\CalendarTypology::firstOrCreate(['alias' => 'Ferie']);

    Sanctum::actingAs($requester);
    $requestId = $this->postJson('/api/calendar/requests', [
        'type' => 'Ferie',
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-02',
    ])->assertCreated()->assertJsonPath('status', 'pending')->json('id');

    expect((float) $requester->leaveBalances()->sole()->remaining_hours)->toBe(16.0);

    $firstId = $first->notifications()->sole()->id;
    $secondId = $second->notifications()->sole()->id;
    $url = '/api/admin/notifications';
    $this->patchJson("$url/$firstId/decision", ['status' => 'approved'])->assertForbidden();

    Sanctum::actingAs($first);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.0.data.calendar_request_id', $requestId)
        ->assertJsonPath('data.0.data.calendar_status', 'pending');
    $this->patchJson("$url/$secondId/decision", ['status' => 'approved'])->assertNotFound();
    $this->patchJson("$url/$firstId/decision", ['status' => 'approved'])
        ->assertOk()->assertJsonPath('status', 'approved');

    Sanctum::actingAs($second);
    $this->patchJson("$url/$secondId/decision", ['status' => 'rejected'])->assertConflict();
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.data.calendar_status', 'approved');
    $this->assertDatabaseHas('calendar', ['id' => $requestId, 'status' => 'approved']);
    expect((float) $requester->leaveBalances()->sole()->remaining_hours)->toBe(0.0);
});

test('active project leaders receive calendar requests for information but cannot decide them', function () {
    $requester = User::factory()->create();
    addCalendarLeaveBalance($requester);
    $project = \App\Models\Project::factory()->create();
    $memberRole = \App\Models\AssignmentRole::firstOrCreate(['alias' => 'Sviluppatore Front-end']);
    $attach = fn ($user, $role) => $project->projectUsers()->create([
        'user_id' => $user->id,
        'assignmentrole_id' => $role->id,
        'startwork_date' => '2025-01-01',
        'work_allocation' => 10,
    ]);
    $attach($requester, $memberRole);

    $leaders = collect(['Project Manager', 'Team Leader'])->mapWithKeys(function ($alias) use ($attach) {
        $role = Role::firstOrCreate(['alias' => $alias], ['description' => $alias]);
        $assignmentRole = \App\Models\AssignmentRole::firstOrCreate(['alias' => $alias]);
        $leader = User::factory()->create();
        $leader->roles()->attach($role);
        $outsider = User::factory()->create();
        $outsider->roles()->attach($role);

        return [$alias => [$leader, $outsider, $attach($leader, $assignmentRole)]];
    });

    \App\Models\CalendarTypology::firstOrCreate(['alias' => 'Ferie']);
    Sanctum::actingAs($requester);
    $this->postJson('/api/calendar/requests', [
        'type' => 'Ferie', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
    ])->assertCreated();

    $url = '/api/admin/notifications';
    foreach ($leaders as [$leader, $outsider, $assignment]) {
        $id = $leader->notifications()->sole()->id;
        Sanctum::actingAs($outsider);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("$url/$id/decision", ['status' => 'approved'])->assertNotFound();
        Sanctum::actingAs($leader);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.calendar_request.can_decide', false)
            ->assertJsonPath('data.0.calendar_request.balance_hours', null)
            ->assertJsonPath('data.0.calendar_request.notes', null);
        $this->patchJson("$url/$id/decision", ['status' => 'approved'])->assertForbidden();
    }

    [$manager, , $assignment] = $leaders['Project Manager'];
    $assignment->delete();
    Sanctum::actingAs($manager);
    $id = $manager->notifications()->sole()->id;
    $this->patchJson("$url/$id/decision", ['status' => 'approved'])->assertForbidden();

    [$teamLeader] = $leaders['Team Leader'];
    Sanctum::actingAs($teamLeader);
    $id = $teamLeader->notifications()->sole()->id;
    $this->patchJson("$url/$id/decision", ['status' => 'rejected'])->assertForbidden();
});

test('HR with leave permission receives details and approves with a single balance deduction', function () {
    $hr = User::factory()->create();
    $role = Role::create(['alias' => 'HR Manager']);
    $permission = \App\Models\Permission::create(['name' => 'users.leaves.manage', 'description' => 'Ferie']);
    $role->permissions()->attach($permission);
    $hr->roles()->attach($role);
    $requester = User::factory()->create();
    addCalendarLeaveBalance($requester);
    \App\Models\CalendarTypology::firstOrCreate(['alias' => 'Ferie']);
    Sanctum::actingAs($requester);
    $this->postJson('/api/calendar/requests', [
        'type' => 'Ferie', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'notes' => 'Nota della richiesta',
    ])->assertCreated();
    $id = $hr->notifications()->sole()->id;
    Sanctum::actingAs($hr);
    $this->getJson('/api/notifications/'.$id)->assertOk()
        ->assertJsonPath('calendar_request.requester', $requester->first_name.' '.$requester->last_name)
        ->assertJsonPath('calendar_request.start_date', '2026-12-01')
        ->assertJsonPath('calendar_request.end_date', '2026-12-01')
        ->assertJsonPath('calendar_request.requested_hours', 8)
        ->assertJsonPath('calendar_request.balance_hours', 16)
        ->assertJsonPath('calendar_request.notes', 'Nota della richiesta')
        ->assertJsonPath('calendar_request.can_decide', true);
    $this->getJson('/api/admin/notifications')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson('/api/admin/notifications/'.$id.'/decision', ['status' => 'approved'])->assertOk();
    expect((float) $requester->leaveBalances()->sole()->remaining_hours)->toBe(8.0);
    $this->getJson('/api/notifications/'.$id)->assertOk()
        ->assertJsonPath('calendar_request.can_decide', false)
        ->assertJsonPath('calendar_request.balance_hours', 8);
    $this->patchJson('/api/admin/notifications/'.$id.'/decision', ['status' => 'approved'])->assertConflict();
    expect((float) $requester->leaveBalances()->sole()->remaining_hours)->toBe(8.0);
    $role->permissions()->detach($permission);
    $this->patchJson('/api/admin/notifications/'.$id.'/decision', ['status' => 'rejected'])->assertForbidden();
});

test('notification details tolerate missing contract data without allowing an unchecked approval', function () {
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::create(['alias' => 'Admin']));
    $requester = User::factory()->create();
    $type = \App\Models\CalendarTypology::create(['alias' => 'Permesso']);
    $entry = $requester->calendarEntries()->create([
        'typology_id' => $type->id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01',
        'start_time' => '09:00', 'end_time' => '10:10', 'status' => 'pending',
    ]);
    $admin->notify(new InAppNotification('calendar', 'Permesso', $requester->id, $entry->id));
    $id = $admin->notifications()->sole()->id;
    Sanctum::actingAs($admin);
    $this->getJson('/api/notifications/'.$id)->assertOk()
        ->assertJsonPath('calendar_request.start_time', '09:00')
        ->assertJsonPath('calendar_request.end_time', '10:10')
        ->assertJsonPath('calendar_request.requested_hours', null);
    $this->patchJson('/api/admin/notifications/'.$id.'/decision', ['status' => 'approved'])->assertUnprocessable();
    $this->assertDatabaseHas('calendar', ['id' => $entry->id, 'status' => 'pending']);
});

test('authenticated users can filter and manage their own personal notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->notify(new InAppNotification('wiki', 'Wiki'));
    $user->notify(new InAppNotification('calendar', 'Calendario'));
    $user->notify(new InAppNotification('projects', 'Progetti'));
    $other->notify(new InAppNotification('wiki', 'Altrui'));

    $wiki = $user->notifications()->where('data->category', 'wiki')->sole()->id;
    $foreign = $other->notifications()->sole()->id;
    $url = '/api/notifications';
    $this->getJson($url)->assertUnauthorized();

    Sanctum::actingAs($user);
    $this->getJson($url.'?categories[]=wiki&categories[]=calendar&unread=1')
        ->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('unread_count', 3)
        ->assertJsonFragment(['category' => 'wiki'])
        ->assertJsonFragment(['category' => 'calendar'])
        ->assertJsonMissing(['category' => 'projects'])
        ->assertJsonMissing(['id' => $foreign]);
    $selection = ['ids' => [$wiki, $foreign]];
    $this->patchJson($url, [...$selection, 'read' => true])->assertOk()->assertJsonPath('affected', 1);
    $this->patchJson($url, [...$selection, 'read' => false])->assertOk()->assertJsonPath('affected', 1);
    $this->deleteJson($url, $selection)->assertOk()->assertJsonPath('affected', 1);
    $this->assertDatabaseHas('notifications', ['id' => $foreign, 'read_at' => null]);
    $this->assertDatabaseMissing('notifications', ['id' => $wiki]);
});

test('notification categories include zero counts and are ordered independently of list filters', function () {
    $user = User::factory()->create();
    $user->notify(new InAppNotification('wiki', 'Prima risorsa'));
    $user->notify(new InAppNotification('wiki', 'Seconda risorsa'));
    $user->notify(new InAppNotification('projects', 'Progetto'));
    $user->notify(new InAppNotification('calendar', 'Calendario'));
    Sanctum::actingAs($user);

    $this->getJson('/api/notifications?categories[]=calendar')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(13, 'categories')
        ->assertJsonPath('categories.0', ['key' => 'wiki', 'label' => 'Wiki', 'unread_count' => 2])
        ->assertJsonPath('categories.1.key', 'calendar')
        ->assertJsonPath('categories.2.key', 'projects')
        ->assertJsonPath('categories.3', ['key' => 'shares', 'label' => 'Condivisioni', 'unread_count' => 0]);
});

test('select all applies the active filters across pages for the authenticated recipient only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->notify(new InAppNotification('wiki', 'Prima'));
    $user->notify(new InAppNotification('wiki', 'Seconda'));
    $user->notify(new InAppNotification('projects', 'Progetto'));
    $other->notify(new InAppNotification('wiki', 'Altrui'));
    Sanctum::actingAs($user);

    $url = '/api/notifications';
    $selection = ['all' => true, 'filters' => ['categories' => ['wiki'], 'unread' => true]];
    $this->getJson($url.'?categories[]=wiki&unread=1&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
    $this->patchJson($url, [...$selection, 'read' => true])->assertOk()->assertJsonPath('affected', 2);
    $this->deleteJson($url, $selection)->assertOk()->assertJsonPath('affected', 0);
    $this->deleteJson($url, ['all' => true, 'filters' => ['categories' => ['wiki']]])
        ->assertOk()->assertJsonPath('affected', 2);
    $this->deleteJson($url, ['all' => false])->assertUnprocessable();
    $this->deleteJson($url, ['all' => true, 'filters' => ['typo' => true]])->assertUnprocessable();
    $this->deleteJson($url, ['all' => true, 'ids' => [$user->notifications()->sole()->id]])->assertUnprocessable();
    expect($other->notifications()->unread()->count())->toBe(1);
});

test('a personal notification can be opened directly only by its recipient', function () {
    $recipient = User::factory()->create();
    $other = User::factory()->create();
    $recipient->notify(new InAppNotification('wiki', 'Guida del progetto'));
    $item = $recipient->notifications()->sole();
    $path = '/api/notifications/'.$item->id;

    $this->getJson($path)->assertUnauthorized();
    $this->withToken($other->createToken('test')->plainTextToken)->getJson($path)->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->withToken($recipient->createToken('test')->plainTextToken)->getJson($path)
        ->assertOk()->assertJsonPath('id', $item->id)
        ->assertJsonPath('category_label', 'Wiki')
        ->assertJsonPath('data.message', 'Guida del progetto');
});

test('notification avatars are available only to the recipient', function () {
    Storage::fake('local');
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $recipient->notify(new InAppNotification('sbi', 'New feedback', $actor->id));
    $notificationId = $recipient->notifications()->sole()->id;
    $url = '/api/notifications/'.$notificationId.'/avatar';

    Sanctum::actingAs($recipient);
    $this->getJson('/api/notifications')->assertOk()
        ->assertJsonPath('data.0.actor.avatar_url', null);
    $this->get($url)->assertNotFound();

    $actor->filestorage()->update(['path' => 'users/avatars', 'name' => 'sender']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aASsAAAAASUVORK5CYII=');
    Storage::disk('local')->put('users/avatars/sender.png', $png);

    $this->getJson('/api/notifications')->assertOk()
        ->assertJsonPath('data.0.actor.avatar_url', $url.'?v='.$actor->filestorage_id);

    $replacement = $actor->filestorage()->firstOrFail()->replicate();
    $replacement->name = 'replacement';
    $replacement->save();
    Storage::disk('local')->put('users/avatars/replacement.png', $png);
    $actor->filestorage()->associate($replacement)->save();
    $this->getJson('/api/notifications')->assertOk()
        ->assertJsonPath('data.0.actor.avatar_url', $url.'?v='.$replacement->id);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

    Sanctum::actingAs($actor);
    $this->get($url)->assertNotFound();
});
