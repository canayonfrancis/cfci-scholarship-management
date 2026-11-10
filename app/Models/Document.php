<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'disk',
        'storage_path',
        'original_name',
        'document_type',
        'mime_type',
        'size_bytes',
        'checksum',
        'uploaded_by',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'application_id',
        'academic_record_id',
        'external_approval_id',
        'funding_receipt_id',
        'payout_id',
        'deduction_id',
        'liquidation_id',
        'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'application_id');
    }

    public function academicRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'academic_record_id');
    }

    public function externalApproval(): BelongsTo
    {
        return $this->belongsTo(ExternalApproval::class, 'external_approval_id');
    }

    public function fundingReceipt(): BelongsTo
    {
        return $this->belongsTo(FundingReceipt::class, 'funding_receipt_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(Deduction::class, 'deduction_id');
    }

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class, 'liquidation_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'supersedes_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Document::class, 'supersedes_id');
    }
}
