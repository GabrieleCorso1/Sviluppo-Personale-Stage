<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contracts';

    protected $fillable = [
        'user_id',
        'contract_start_date',
        'contract_end_date',
        'weekly_hours',
        'contract_type',
    ];

    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'weekly_hours' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
