<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SbiRevision extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sbi_id',
        'revision_number',
        'is_positive',
        'event_date',
        'situation',
        'behavior',
        'impact',
        'suggestion',
        'personal_notes',
        'viewed_at',
    ];

    protected $hidden = [
        'personal_notes',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'is_positive' => 'boolean',
            'event_date' => 'date:Y-m-d',
            'viewed_at' => 'datetime',
        ];
    }

    public function sbi(): BelongsTo
    {
        return $this->belongsTo(Sbi::class);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(SbiReply::class);
    }
}
