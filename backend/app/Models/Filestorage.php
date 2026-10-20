<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileStorage extends Model
{
    use HasFactory;

    protected $table = 'filestorage';

    protected $fillable = [
        'path',
        'name',
        'extension',
        'type',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'filestorage_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'filestorage_id');
    }
}
