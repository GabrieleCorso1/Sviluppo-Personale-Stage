<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AssignmentRole extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'assignmentroles';

    protected $fillable = [
        'alias',
        'description',
    ];

        public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'assignmentrole_user',
            'assignmentrole_id',
            'user_id'
        )
        ->withPivot('expertise')
        ->withTimestamps();
    }
}