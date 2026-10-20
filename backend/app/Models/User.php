<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'gender',
        'azure_email',
        'company_email',
        'is_intern',
        'isAllocation_visible',
        'email',
        'password',
        'birth_date',
        'hiring_date',
        'resignation_date',
        'remember_me',
        'filestorage_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_intern' => 'boolean',
            'isAllocation_visible' => 'boolean',
            'remember_me' => 'boolean',
            'birth_date' => 'date',
            'hiring_date' => 'date',
            'resignation_date' => 'date',
            'password' => 'hashed',
        ];
    }

    public function filestorage(): BelongsTo
    {
        return $this->belongsTo(FileStorage::class, 'filestorage_id');
    }
    public function roles(): BelongsToMany
    {
    return $this->belongsToMany(Role::class);
    }

    public function projectUsers(): HasMany
    {
    return $this->hasMany(ProjectUser::class);
    }


    public function assignmentRoles(): BelongsToMany
    {
        return $this->belongsToMany(
            AssignmentRole::class,
            'assignmentrole_user',
            'user_id',
            'assignmentrole_id'
        )
        ->withPivot('expertise')
        ->withTimestamps();
    }

    public function calendarEntries(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(UserLeaveBalance::class);
    }

    public function hoursDistributions(): HasMany
    {
        return $this->hasMany(HoursDistribution::class);
    }

    public function capacities(): HasMany
    {
        return $this->hasMany(Capacity::class, 'user_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'user_id');
    }
}
