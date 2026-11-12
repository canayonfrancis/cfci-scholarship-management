<?php

namespace App\Services\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\Deduction;
use App\Models\Document;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\User;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinancialEvidence
{
    public const TYPES = [
        'receipt' => ['receipt_evidence'], 'payout' => ['payment_evidence', 'signed_acknowledgment'],
        'deduction' => ['deduction_authority', 'deduction_settlement'], 'liquidation' => ['liquidation_support'],
    ];

    public function __construct(private PrivateEvidence $files, private RecordAudit $audit) {}

    public function upload(Model $owner, User $actor, UploadedFile $file, string $type): Document
    {
        Gate::forUser($actor)->authorize('financial-evidence');
        $allowed = match ($owner::class) {
            FundingReceipt::class => self::TYPES['receipt'], Payout::class => self::TYPES['payout'],
            Deduction::class => self::TYPES['deduction'], Liquidation::class => self::TYPES['liquidation'], default => [],
        };
        abort_unless(in_array($type, $allowed, true), 404);
        if ($type === 'deduction_authority') {
            Gate::forUser($actor)->authorize('financial-verify');
        }
        $document = null;
        try {
            return DB::transaction(function () use ($owner, $actor, $file, $type, &$document) {
                $document = $this->files->store($owner, $actor, $file, $type);
                $this->audit->record($actor, $document, 'financial_evidence_uploaded');

                return $document;
            });
        } catch (Throwable $error) {
            if ($document) {
                Storage::disk($document->disk)->delete($document->storage_path);
            }
            throw $error;
        }
    }

    public function review(Document $document, User $actor, string $decision, string $notes): void
    {
        Gate::forUser($actor)->authorize('financial-verify');
        DB::transaction(function () use ($document, $actor, $decision, $notes) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->review_status === 'accepted' || $document->uploaded_by === $actor->id) {
                throw ValidationException::withMessages(['evidence' => 'Accepted evidence is immutable; a different Coordinator must review uploaded evidence.']);
            }
            if (! $this->intact($document)) {
                throw ValidationException::withMessages(['evidence' => 'Evidence is unavailable or its checksum changed. Upload a new, intact copy.']);
            }
            $before = $document->only($document->getFillable());
            $document->update(['review_status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
            $this->audit->record($actor, $document, 'financial_evidence_reviewed', $before);
        }, 3);
    }

    public function intact(Document $document): bool
    {
        return $document->disk === 'scholarship' && str_starts_with($document->storage_path, 'evidence/') && $document->checksum
            && Storage::disk('scholarship')->exists($document->storage_path)
            && hash_equals($document->checksum, hash('sha256', Storage::disk('scholarship')->get($document->storage_path)));
    }

    public function approved(Model $owner, string $type): bool
    {
        return $owner->documents()->where('document_type', $type)->get()->contains(fn ($d) => $this->files->usable($d));
    }
}
