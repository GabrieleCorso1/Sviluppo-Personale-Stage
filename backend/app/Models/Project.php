<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'alias',
        'description',
        'is_daily',
        'is_azure',
        'is_ux_ui',
        'status',
        'azure_project_name',
        'project_manager_id',
        'team_leader_id',
        'scrum_master_id',
        'delivery_plan',
        'sprintreview_id',
        'azure_team_name',
        'bug_count_url',
        'note',
        'priority',
        'pbi_without_feature_url',
        'pbi_past_feature_url',
        'is_timesheet',
        'is_course',
        'start_date',
        'end_date',
        'filestorage_id',
    ];

    protected function casts(): array
    {
        return [
            'is_daily' => 'boolean',
            'is_azure' => 'boolean',
            'is_ux_ui' => 'boolean',
            'is_timesheet' => 'boolean',
            'is_course' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    public function scrumMaster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scrum_master_id');
    }

    public function filestorage(): BelongsTo
    {
        return $this->belongsTo(FileStorage::class, 'filestorage_id');
    }

    public function projectUsers(): HasMany
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class, 'project_id');
    }
}
