<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class ProjectUser extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'project_user';

    protected $fillable = [
        'project_id',
        'user_id',
        'assignmentrole_id',
        'startwork_date',
        'endwork_date',
        'work_allocation',
    ];

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            // Serialize assignment changes on the same project, including moves between projects.
            $projectIds = array_filter([$this->project_id, $this->getOriginal('project_id')]);
            $this->getConnection()->table('projects')->whereIn('id', $projectIds)
                ->orderBy('id')->lockForUpdate()->get(['id']);

            if (! $this->trashed()) {
                $this->validatePeriod();
                $this->validateProjectManagerCompatibility();
                $this->validateSingleLeadership();
            }

            return parent::save($options);
        });
    }

    private function validatePeriod(): void
    {
        if ($this->startwork_date !== null && $this->endwork_date !== null
            && $this->endwork_date->lessThan($this->startwork_date)) {
            throw ValidationException::withMessages([
                'endwork_date' => 'La data di fine collaborazione non puo precedere la data di inizio.',
            ]);
        }
    }

    private function validateProjectManagerCompatibility(): void
    {
        $role = $this->getConnection()->table('assignmentroles')
            ->where('id', $this->assignmentrole_id)->value('alias');
        if ($role === null || $this->startwork_date === null) {
            return;
        }

        $isProjectManager = $role === 'Project Manager';
        $conflicts = $this->newQuery()
            ->where('project_id', $this->project_id)
            ->where('user_id', $this->user_id)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->whereHas('assignmentRole', fn ($query) => $query->withTrashed()
                ->where('alias', $isProjectManager ? '!=' : '=', 'Project Manager'))
            ->where(function ($query) {
                $query->whereNull('endwork_date')
                    ->orWhereDate('endwork_date', '>=', $this->startwork_date->toDateString());
            });

        if ($this->endwork_date !== null) {
            $conflicts->whereDate('startwork_date', '<=', $this->endwork_date->toDateString());
        }

        if ($conflicts->exists()) {
            throw ValidationException::withMessages([
                'assignmentrole_id' => 'Un Project Manager non puo avere altri incarichi nello stesso progetto in periodi sovrapposti.',
            ]);
        }
    }

    private function validateSingleLeadership(): void
    {
        $role = $this->getConnection()->table('assignmentroles')
            ->where('id', $this->assignmentrole_id)->value('alias');
        if (! in_array($role, ['Team Leader', 'Scrum Master'], true) || $this->startwork_date === null) {
            return;
        }

        $conflicts = $this->newQuery()->where('project_id', $this->project_id)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->whereHas('assignmentRole', fn ($query) => $query->withTrashed()->where('alias', $role))
            ->where(function ($query) {
                $query->whereNull('endwork_date')
                    ->orWhereDate('endwork_date', '>=', $this->startwork_date->toDateString());
            });
        if ($this->endwork_date !== null) {
            $conflicts->whereDate('startwork_date', '<=', $this->endwork_date->toDateString());
        }
        if ($conflicts->exists()) {
            throw ValidationException::withMessages([
                'assignmentrole_id' => 'Il progetto ha gia un '.$role.' assegnato in un periodo sovrapposto.',
            ]);
        }
    }

    protected function casts(): array
    {
        return [
            'startwork_date' => 'date',
            'endwork_date' => 'date',
            'work_allocation' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignmentRole(): BelongsTo
    {
        return $this->belongsTo(
            AssignmentRole::class,
            'assignmentrole_id'
        );
    }
}
