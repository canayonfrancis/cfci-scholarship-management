<?php

namespace App\Actions\Eligibility;

use App\Actions\Records\RecordAudit;
use App\Models\ExternalApproval;
use App\Models\ScholarshipApplication;
use App\Models\Student;
use App\Models\User;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecordExternalApproval
{
    public function __construct(private PrivateEvidence $evidence, private RecordAudit $audit) {}

    public function handle(ScholarshipApplication $application, User $actor, array $data, UploadedFile $file): ExternalApproval
    {
        $document = null;
        try {
            return DB::transaction(function () use ($application, $actor, $data, $file, &$document) {
                Student::whereKey($application->student_id)->lockForUpdate()->firstOrFail();
                $application = ScholarshipApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('evaluate', $application);
                $previous = $application->externalApprovals()->where('scope', 'semester')->orderByDesc('id')->lockForUpdate()->first();
                if ((int) ($previous?->id ?? 0) !== (int) $data['previous_approval']) {
                    throw ValidationException::withMessages(['previous_approval' => 'External decisions changed. Reload the latest record.']);
                }
                if ($previous && $previous->decision === $data['decision'] && $previous->reference === $data['reference'] &&
                    $previous->decided_on?->toDateString() === $data['decided_on'] &&
                    $previous->valid_from?->toDateString() === ($data['valid_from'] ?? null) && $previous->valid_until?->toDateString() === ($data['valid_until'] ?? null) &&
                    $previous->documents()->where('checksum', hash_file('sha256', $file->getRealPath()))->exists()) {
                    throw ValidationException::withMessages(['reference' => 'This external decision is already recorded.']);
                }
                $approval = $application->externalApprovals()->create(Arr::only($data, ['decision', 'authority', 'reference', 'decided_on', 'valid_from', 'valid_until']) + [
                    'student_id' => $application->student_id, 'program_id' => $application->program_id,
                    'scope' => 'semester', 'assignment_id' => $application->assignment_id, 'academic_period_id' => $application->academic_period_id,
                    'recorded_by' => $actor->id, 'supersedes_id' => $previous?->id,
                ]);
                $document = $this->evidence->store($approval, $actor, $file, 'external_decision', true);
                $this->audit->record($actor, $approval, 'external_decision_recorded', reason: $data['verification_notes']);
                $this->audit->record($actor, $document, 'external_evidence_verified');

                return $approval;
            });
        } catch (Throwable $exception) {
            if ($document) {
                Storage::disk('scholarship')->delete($document->storage_path);
            }
            throw $exception;
        }
    }
}
