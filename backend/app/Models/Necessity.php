<?php

namespace App\Models;

use App\Support\ProjectCalendar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Necessity extends Model
{
    use HasFactory;

    public static function closeCovered(?int $projectId = null): void
    {
        $today = ProjectCalendar::today();

        static::query()->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->whereHas('project', fn ($query) => $query->where('is_timesheet', false)->where('is_course', false))
            ->whereExists(function ($query) use ($today) {
                $query->select(DB::raw(1))->from('project_user')
                    ->join('users', 'users.id', '=', 'project_user.user_id')
                    ->whereColumn('project_user.project_id', 'necessities.project_id')
                    ->whereColumn('project_user.assignmentrole_id', 'necessities.assignmentrole_id')
                    ->where('project_user.allocation_type', 'project')
                    ->whereNull('project_user.deleted_at')->whereNull('users.deleted_at')
                    ->where('users.isAllocation_visible', true)
                    ->whereDate('project_user.startwork_date', '<=', $today)
                    ->where(fn ($period) => $period->whereNull('project_user.endwork_date')
                        ->orWhereDate('project_user.endwork_date', '>=', $today));
            })->delete();
    }

    protected $table = 'necessities';

    protected $fillable = [
        'project_id',
        'assignmentrole_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignmentRole(): BelongsTo
    {
        return $this->belongsTo(
            AssignmentRole::class,
            'assignmentrole_id'
        );
    }
}
