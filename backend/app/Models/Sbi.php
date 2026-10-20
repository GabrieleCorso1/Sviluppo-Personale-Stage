<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sbi extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sbis';

    protected $fillable = [
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
    ];

    protected function casts(): array
    {
        return [
            'is_positive' => 'boolean',
            'written_at' => 'date',
            'answered_at' => 'date',
        ];
    }

    /**
     * Sbi padre, se questa Sbi è una risposta.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Risposte o Sbi figlie.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Utente che ha scritto la Sbi.
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'writer_id');
    }

    /**
     * Utente che riceve la Sbi.
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
