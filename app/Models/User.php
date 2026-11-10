<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const STAFF_ROLES = ['administrator', 'registrar', 'coordinator', 'finance', 'viewer'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function hasAnyRole(array $roles): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(fn (UserRole $membership) => in_array($membership->role, $roles, true));
    }

    public function academicVerifications(): HasMany
    {
        return $this->hasMany(AcademicRecord::class, 'verified_by');
    }

    public function schoolEvaluations(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'coordinator_id');
    }

    public function recordedExternalApprovals(): HasMany
    {
        return $this->hasMany(ExternalApproval::class, 'recorded_by');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'actor_id');
    }
}
