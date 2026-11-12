<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAcknowledgment extends Model
{
    protected $fillable = ['payout_id', 'document_id', 'student_id', 'verified_by', 'verified_at', 'document_reference', 'page_line', 'institutional_student_id', 'beneficiary_name', 'paid_on', 'amount_received', 'payment_reference', 'document_checksum', 'policy_reference', 'evidence_snapshot'];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'paid_on' => 'immutable_date', 'amount_received' => 'decimal:2', 'evidence_snapshot' => 'array'];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
