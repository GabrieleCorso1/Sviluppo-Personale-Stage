<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'alias',
    'description',
])]
class AssignmentRole extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'assignmentroles';

    public function projectUsers(): HasMany
    {
        return $this->hasMany(ProjectUser::class, 'assignmentrole_id');
    }
}
