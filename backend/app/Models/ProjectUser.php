<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'project_id',
    'user_id',
    'assignmentrole_id',
    'startwork_date',
    'endwork_date',
    'work_allocation',
])]
class ProjectUser extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'project_user';

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
