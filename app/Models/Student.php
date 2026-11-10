<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    protected $fillable = [
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'user_id',
        'record_status',
    ];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name], fn ($part) => filled($part))));
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
