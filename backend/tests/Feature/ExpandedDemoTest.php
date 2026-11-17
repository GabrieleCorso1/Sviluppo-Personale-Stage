<?php

use App\Models\Calendar;
use App\Models\Contract;
use App\Models\Sbi;
use App\Models\User;
use App\Support\CalendarLeave;
use App\WorkSchedule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

test('expanded demo accounts have valid schedules and usable role scoped access', function () {
    $this->seed(DatabaseSeeder::class);
    expect(Contract::count())->toBe(20);
    foreach (Contract::with('workingDays')->get() as $contract) {
        expect(WorkSchedule::validate($contract->workingDays->toArray(), $contract->weekly_hours))->toHaveCount(7);
    }
    foreach (['mario.rossi', 'elena.ferrari', 'matteo.ricci', 'gabriele.didio'] as $email) {
        $this->postJson('/api/login', ['email' => $email.'@example.com', 'password' => 'Password123!', 'remember' => false])->assertOk();
    }
    $this->postJson('/api/login', ['email' => 'marco.fontana@example.com', 'password' => 'Password123!', 'remember' => false])->assertUnprocessable();
    Sanctum::actingAs(User::where('email', 'matteo.ricci@example.com')->firstOrFail());
    $this->getJson('/api/projects?is_timesheet=0')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/users')->assertForbidden();
    Sanctum::actingAs(User::where('email', 'elena.ferrari@example.com')->firstOrFail());
    $this->getJson('/api/users')->assertOk();
});

test('demo requests can be approved from their notifications and pending requests have not spent the balance', function () {
    $this->seed(DatabaseSeeder::class);
    $requester = User::where('email', 'giulia.neri@example.com')->firstOrFail();
    $entry = Calendar::where('user_id', $requester->id)->where('notes', 'Demo presentazione: richiesta 3')->with('typology')->firstOrFail();
    expect($entry->status)->toBe('pending');
    $before = CalendarLeave::availableBalance($requester, 'Ferie', 0)->remaining_hours;
    // Giulia's existing two-day approved holiday has already spent 16 hours.
    expect((float) $before)->toBe(64.0);
    $hr = User::where('email', 'elena.ferrari@example.com')->firstOrFail();
    $notification = $hr->notifications()->get()->first(fn ($notification) => ($notification->data['calendar_request_id'] ?? null) === $entry->id);
    expect($notification)->not->toBeNull();
    Sanctum::actingAs($hr);
    $this->patchJson('/api/admin/notifications/'.$notification->id.'/decision', ['status' => 'approved'])->assertOk();
    expect((float) CalendarLeave::availableBalance($requester, 'Ferie', 0)->remaining_hours)->toBe((float) $before - 8);
    $this->patchJson('/api/admin/notifications/'.$notification->id.'/decision', ['status' => 'approved'])->assertConflict();
});

test('demo SBI recipients belong to their project and the deliberate overload is visible in allocations', function () {
    $this->seed(DatabaseSeeder::class);
    foreach (Sbi::with('project')->get() as $sbi) {
        expect($sbi->project->projectUsers()->where('user_id', $sbi->receiver_id)->exists())->toBeTrue();
    }
    $davide = User::where('email', 'davide.romano@example.com')->firstOrFail();
    expect((int) DB::table('project_user')->where('user_id', $davide->id)->where('allocation_type', 'project')->sum('work_allocation'))->toBeGreaterThan(100);
    Sanctum::actingAs(User::where('email', 'mario.rossi@example.com')->firstOrFail());
    $this->getJson('/api/allocations/resources/'.$davide->id.'?type=project')->assertOk();
    $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(6, 'projects');
});
