<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BillingBatch extends Model
{
    protected $fillable = [
        'program_period_term_id',
        'scholarship_rate_id',
        'program_id',
        'academic_period_id',
        'batch_number',
        'reference',
        'status',
        'prepared_by',
        'submitted_at',
        'submitted_by',
        'lock_version',
        'finalized_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function terms(): BelongsTo
    {
        return $this->belongsTo(ProgramPeriodTerm::class, 'program_period_term_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function scholarshipBatch(): BelongsTo
    {
        return $this->belongsTo(ScholarshipBatch::class);
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** Sum integer centavos to avoid floating-point arithmetic on either database. */
    public function totalAmount(): ?string
    {
        $centavos = 0;
        foreach ($this->items()->pluck('gross_amount') as $amount) {
            if ($amount === null) {
                return null;
            }
            [$pesos, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
            $centavos += (int) $pesos * 100 + (int) str_pad($fraction, 2, '0');
        }

        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'billing_batch_id');
    }

    public function fundingAllocations(): HasMany
    {
        return $this->hasMany(FundingAllocation::class, 'billing_batch_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'billing_batch_id');
    }

    public function liquidations(): HasMany
    {
        return $this->hasMany(Liquidation::class, 'billing_batch_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRate::class, 'scholarship_rate_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(BillingBatchApproval::class, 'billing_batch_id');
    }

    public function payroll(): HasOne
    {
        return $this->hasOne(Payroll::class);
    }

    public function allocationStatus(): string
    {
        return $this->approval()->exists() || $this->status === 'finalized' ? 'approved' : $this->status;
    }

    public function appliedRate(): ?ScholarshipRate
    {
        if ($this->scholarship_rate_id) {
            return $this->rate;
        }
        $item = $this->items()->orderBy('id')->first();
        if ($item) {
            $rateId = $item->basis_snapshot['rate_id'] ?? null;
            $rates = $this->terms->rates();

            return $rateId ? $rates->find($rateId) : $rates->where('amount', $item->entitlement_snapshot)->orderBy('revision')->first();
        }

        return $this->terms->rates()->orderByDesc('revision')->first();
    }
}
