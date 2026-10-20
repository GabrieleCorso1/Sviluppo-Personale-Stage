<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'teamcategories';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'alias',
        'description',
        'ideal_velocity',
        'filestorage_id',
        'color_code',
    ];

    protected function casts(): array
    {
        return [
            'ideal_velocity' => 'decimal:2',
        ];
    }

    public function filestorage(): BelongsTo
    {
        return $this->belongsTo(
            FileStorage::class,
            'filestorage_id'
        );
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class, 'teamcategory_id');
    }
}