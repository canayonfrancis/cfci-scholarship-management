<?php

namespace App\Models;

use App\Services\Records\ScholarProfile;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'landbank_account_number',
        'user_id',
        'record_status',
        'lrn', 'philsys_id', 'four_ps_id', 'suffix', 'sex', 'birthdate',
        'academic_program_id', 'year_level', 'academic_period_id',
        'father_last_name', 'father_first_name', 'father_middle_name',
        'mother_maiden_last_name', 'mother_maiden_first_name', 'mother_maiden_middle_name',
        'street_barangay', 'city_municipality', 'province', 'region', 'zip_code',
        'disability_type', 'solo_parent_dependent', 'first_generation_student', 'indigenous_people_group',
    ];

    public const PROTECTED_IDENTIFIERS = ['lrn', 'philsys_id', 'four_ps_id', 'landbank_account_number'];

    protected $hidden = self::PROTECTED_IDENTIFIERS;

    protected function casts(): array
    {
        return [
            'lrn' => 'encrypted', 'philsys_id' => 'encrypted', 'four_ps_id' => 'encrypted',
            'landbank_account_number' => 'encrypted',
            'sex' => 'integer', 'birthdate' => 'immutable_date', 'year_level' => 'integer',
            'solo_parent_dependent' => 'boolean', 'first_generation_student' => 'boolean',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name, $this->suffix], fn ($part) => filled($part))));
    }

    public function maskedLandbankAccountNumber(): ?string
    {
        return ScholarProfile::maskBankAccount($this->landbank_account_number);
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function missingProfileFields(): array
    {
        return ScholarProfile::missing($this);
    }

    public function hasCompleteProfile(): bool
    {
        return $this->missingProfileFields() === [];
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(ScholarshipAssignment::class, 'student_id')->where('status', 'active');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ScholarshipAssignment::class, 'student_id');
    }

    public function batchMemberships(): HasMany
    {
        return $this->hasMany(ScholarshipBatchMembership::class);
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(AcademicRecord::class, 'student_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScholarshipApplication::class, 'student_id');
    }

    public function externalApprovals(): HasMany
    {
        return $this->hasMany(ExternalApproval::class, 'student_id');
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'student_id');
    }

    public function payouts(): HasManyThrough
    {
        return $this->hasManyThrough(Payout::class, BillingItem::class, 'student_id', 'billing_item_id');
    }
}
