<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'alias' => fake()->unique()->words(3, true), 'description' => null,
            'is_daily' => false, 'is_azure' => false, 'is_ux_ui' => false,
            'status' => 'in corso', 'project_manager_id' => User::factory(),
            'team_leader_id' => fn (array $attributes) => $attributes['project_manager_id'],
            'scrum_master_id' => fn (array $attributes) => $attributes['project_manager_id'],
            'azure_team_name' => '', 'bug_count_url' => '', 'note' => '', 'priority' => 'media',
            'is_timesheet' => false, 'is_course' => false, 'start_date' => '2026-01-01',
            'filestorage_id' => fn (array $attributes) => User::findOrFail($attributes['project_manager_id'])->filestorage_id,
        ];
    }
}
