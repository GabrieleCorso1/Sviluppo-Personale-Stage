<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SbiReply extends Model
{
    protected $fillable = [
        'sbi_revision_id',
        'body',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            SbiRevision::class,
            'sbi_revision_id'
        );
    }
}
