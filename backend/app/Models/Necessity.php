<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Necessity extends Model
{
    use HasFactory;

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