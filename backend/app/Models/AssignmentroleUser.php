<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'assignmentrole_id',
    'expertise',
])]
class AssignmentRoleUser extends Model
{
    use HasFactory;

    protected $table = 'assignmentrole_user';

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
