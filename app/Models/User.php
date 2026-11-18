<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Accounts\StudentAccess;
use App\Services\Accounts\StudentCredentialDelivery;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const STUDENT_ROLE = 'student';

    public const OPERATIONAL_ROLES = ['coordinator', 'registrar'];

    // The user explicitly retains the existing Administrator for full-system testing.
    // This exception is not a newly provisionable operational role.
    public const TECHNICAL_ROLES = ['administrator'];

    // Finance/Viewer remain legacy compatibility memberships, not provisioning choices.
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
            'student_setup_required' => 'boolean',
            'student_access_disabled_at' => 'immutable_datetime',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if (app(StudentAccess::class)->usesStudentCredentials($this)) {
            app(StudentCredentialDelivery::class)->send($this, $token);

            return;
        }
        if (! app(StudentCredentialDelivery::class)->available()) {
            throw new \RuntimeException('Credential delivery is unavailable.');
        }
        $this->notify(new ResetPassword($token));
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

    public function isStudentOnly(): bool
    {
        return $this->hasAnyRole([self::STUDENT_ROLE]) && ! $this->hasAnyRole(self::STAFF_ROLES);
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
