<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Sbi;
use App\Models\Sprint;
use App\Models\User;
use Database\Seeders\CapacitySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\SprintSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('full seed creates coherent projects assignments defaults and CSV states and can be repeated', function () {
    $this->seed(DatabaseSeeder::class);
    expect(User::count())->toBe(18)
        ->and(User::onlyTrashed()->count())->toBe(2)
        ->and(Project::where('is_timesheet', false)->where('is_course', false)->count())->toBe(8)
        ->and(Project::where('is_timesheet', true)->where('is_course', false)->count())->toBe(3)
        ->and(Project::where('is_course', true)->count())->toBe(0)
        ->and(Sprint::where('has_csv', true)->whereNull('csv_path')->count())->toBe(0);

    foreach (Project::where('is_timesheet', false)->get() as $project) {
        expect($project->currentProjectManagerAssignments)->toHaveCount(1)
            ->and($project->currentTeamLeaderAssignment?->user_id)->toBe($project->team_leader_id)
            ->and($project->currentScrumMasterAssignment?->user_id)->toBe($project->scrum_master_id);
        expect($project->currentProjectManagerAssignments->first()->user_id)->toBe($project->project_manager_id);
    }
    $timesheet = Project::where('alias', 'Attivita interne')->where('is_timesheet', true)->sole();
    expect($timesheet->status)->toBeNull()->and($timesheet->priority)->toBeNull()
        ->and($timesheet->start_date)->toBeNull()->and($timesheet->project_manager_id)->toBeNull()
        ->and($timesheet->projectUsers()->count())->toBe(1);

    $counts = [User::withTrashed()->count(), Project::count(), ProjectUser::count(), Sprint::count(),
        Sbi::count(), DB::table('notifications')->count(),
        DB::table('user_leave_balances')->sum('remaining_hours')];
    $this->seed(DatabaseSeeder::class);
    expect([User::withTrashed()->count(), Project::count(), ProjectUser::count(), Sprint::count(),
        Sbi::count(), DB::table('notifications')->count(),
        DB::table('user_leave_balances')->sum('remaining_hours')])->toBe($counts);
});

test('sprint and capacity seeders resolve ordinary projects even when another type shares the same name', function () {
    $this->seed(DatabaseSeeder::class);
    $ordinary = Project::where('alias', 'Portale Clienti')->where('is_timesheet', false)->sole();
    $timesheet = Project::create([
        'alias' => $ordinary->alias, 'is_timesheet' => true, 'is_course' => false,
        'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false,
    ]);
    $this->seed([ProjectSeeder::class, SprintSeeder::class, CapacitySeeder::class]);
    expect($timesheet->sprints()->count())->toBe(0)
        ->and($ordinary->sprints()->count())->toBe(5);
});
