<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = ['payroll_id', 'billing_batch_id', 'billing_item_id', 'institutional_student_id', 'beneficiary_name', 'effective_amount_snapshot', 'posted_gross_snapshot', 'planned_gross_amount', 'basis_snapshot'];

    protected function casts(): array
    {
        return ['effective_amount_snapshot' => 'decimal:2', 'posted_gross_snapshot' => 'decimal:2', 'planned_gross_amount' => 'decimal:2', 'basis_snapshot' => 'array'];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function billingItem(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class);
    }
}
