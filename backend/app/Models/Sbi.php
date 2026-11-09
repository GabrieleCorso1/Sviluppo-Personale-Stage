<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sbi extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sbis';

    protected $fillable = [
        'writer_id',
        'receiver_id',
        'project_id',
    ];

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'writer_id')->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SbiRevision::class)
            ->orderBy('revision_number');
    }

    public function latestRevision(): HasOne
    {
        return $this->hasOne(SbiRevision::class)->ofMany('revision_number', 'max');
    }
}
