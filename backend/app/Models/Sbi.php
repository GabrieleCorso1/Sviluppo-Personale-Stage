<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'parent_id',
    'is_positive',
    'writer_id',
    'receiver_id',
    'situation',
    'behavior',
    'impact',
    'suggestion',
    'written_at',
    'answered_at',
])]
class Sbi extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_positive' => 'boolean',
            'written_at' => 'date',
            'answered_at' => 'date',
        ];
    }

    /**
     * SBI principale a cui questa risposta appartiene.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Sbi::class, 'parent_id');
    }

    /**
     * Risposte collegate a questo SBI.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Sbi::class, 'parent_id');
    }

    /**
     * Utente che ha scritto l'SBI.
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'writer_id');
    }

    /**
     * Utente che riceve l'SBI.
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
