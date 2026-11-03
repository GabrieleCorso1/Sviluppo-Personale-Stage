<?php

namespace App\Models;

use App\Support\ProjectCalendar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = ['managed_alias'];

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

    public function save(array $options = [])
    {
        try {
            return parent::save($options);
        } catch (UniqueConstraintViolationException $exception) {
            $message = $exception->getMessage();
            $nameConstraint = str_contains($message, 'projects_managed_name_type_unique')
                || (str_contains($message, 'projects.managed_alias') && str_contains($message, 'projects.is_timesheet'));
            if (! $nameConstraint) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'alias' => 'Esiste gia un progetto con questo nome nello stesso tipo, anche tra quelli eliminati.',
            ]);
        }
    }

    public function replicate(?array $except = null)
    {
        return parent::replicate(array_merge($except ?? [], ['managed_alias']));
    }

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
            'next_sprint_review_date' => 'date:Y-m-d',
        ];
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function currentProjectManagerAssignments(): HasMany
    {
        $today = ProjectCalendar::today();

        return $this->hasMany(ProjectUser::class)
            ->whereHas('assignmentRole', fn ($query) => $query->where('alias', 'Project Manager'))
            ->whereHas('user')
            ->whereDate('startwork_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $today);
            })
            ->orderBy('id');
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    public function currentTeamLeaderAssignment(): HasOne
    {
        $today = ProjectCalendar::today();

        return $this->hasOne(ProjectUser::class)
            ->whereHas('assignmentRole', fn ($query) => $query->where('alias', 'Team Leader'))
            ->whereHas('user')
            ->whereDate('startwork_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('endwork_date')
                    ->orWhereDate('endwork_date', '>=', $today);
            })
            ->orderBy('id');
    }

    public function currentScrumMasterAssignment(): HasOne
    {
        $today = ProjectCalendar::today();

        return $this->hasOne(ProjectUser::class)
            ->whereHas('assignmentRole', fn ($query) => $query->where('alias', 'Scrum Master'))
            ->whereHas('user')
            ->whereDate('startwork_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('endwork_date')
                    ->orWhereDate('endwork_date', '>=', $today);
            })
            ->orderBy('id');
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

    public function upcomingReviewSprints(): HasMany
    {
        return $this->sprints()
            ->where('is_concluded', false)
            ->whereDate('end_date', '>=', ProjectCalendar::today());
    }

    public function sprintsRequiringCsv(): HasMany
    {
        return $this->sprints()->where('is_concluded', true)
            ->where('is_billing', false);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class, 'project_id');
    }
}
