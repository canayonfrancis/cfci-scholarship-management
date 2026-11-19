<?php

namespace App\Actions\Records;

use App\Models\AcademicRecord;
use App\Models\ProgramAcademicRequirement;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveAcademicVerification
{
    public function __construct(private RecordAudit $audit) {}

    /** Reuse the retained record action; one outcome/reference per reviewed group. */
    public function enrollment(User $actor, ScholarshipBatch $roster, array $data, array $files = []): int
    {
        Gate::forUser($actor)->authorize('create', AcademicRecord::class);
        Validator::make($data, [
            'enrollment_student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'enrollment_student_ids.*' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'enrollment_records' => ['required', 'array', 'max:200'],
            'enrollment_records.*.id' => ['required', 'integer', 'min:0'],
            'enrollment_records.*.version' => ['required', 'integer', 'min:0'],
            'enrollment_status' => ['required', 'in:enrolled,not_enrolled,unverified'],
            'source_reference' => ['required', 'string', 'max:3000', 'regex:/\S/'],
            'verification_notes' => ['nullable', 'string', 'max:3000'],
            'correction_reason' => ['nullable', 'string', 'max:3000'],
            'confirm_enrollment_review' => ['accepted'],
        ])->validate();
        $stored = [];
        try {
            // File writes cannot be retried; clean up earlier members if the group rolls back.
            return DB::transaction(function () use ($actor, $roster, $data, $files, &$stored) {
                $students = Student::with('academicProgram')->whereIn('id', $data['enrollment_student_ids'])->orderBy('id')->lockForUpdate()->get();
                $currentRoster = ScholarshipBatch::whereKey($roster->id)->lockForUpdate()->firstOrFail();
                if ($currentRoster->program_id !== $roster->program_id || $currentRoster->academic_period_id !== $roster->academic_period_id) {
                    throw ValidationException::withMessages(['enrollment' => 'The roster scope changed. Reload before recording enrollment.']);
                }
                $members = ScholarshipBatchMembership::where('scholarship_batch_id', $roster->id)
                    ->where('program_id', $roster->program_id)->where('academic_period_id', $roster->academic_period_id)
                    ->whereIn('student_id', $data['enrollment_student_ids'])->lockForUpdate()->pluck('student_id');
                if ($students->count() !== count($data['enrollment_student_ids']) || $members->count() !== $students->count()) {
                    throw ValidationException::withMessages(['enrollment_student_ids' => 'Select only existing members of this exact roster and semester.']);
                }
                foreach ($students as $student) {
                    $record = AcademicRecord::where('student_id', $student->id)->where('academic_period_id', $roster->academic_period_id)->orderByDesc('revision')->lockForUpdate()->first();
                    $expected = $data['enrollment_records'][$student->id] ?? null;
                    if (! $expected || (int) $expected['id'] !== (int) ($record?->id ?? 0) || (int) $expected['version'] !== (int) ($record?->lock_version ?? 0)) {
                        throw ValidationException::withMessages(['enrollment' => $student->student_number.': the academic source changed. Reload and review the latest revision.']);
                    }
                    $correction = $record && ($record->verification_status !== 'pending' || $record->verified_at !== null);
                    if ($correction && ! trim($data['correction_reason'] ?? '')) {
                        throw ValidationException::withMessages(['correction_reason' => 'A reason is required when creating a correction of an existing verified or rejected source.']);
                    }
                    $payload = $record ? $record->only(['course', 'year_level']) : [
                        'course' => $student->academicProgram?->name, 'year_level' => $student->year_level,
                    ];
                    $payload = array_merge($payload, [
                        'student_id' => $student->id, 'academic_period_id' => $roster->academic_period_id,
                        'enrollment_status' => $data['enrollment_status'], 'source_reference' => trim($data['source_reference']),
                        'verification_notes' => trim(($data['verification_notes'] ?? '').($record ? "\nEnrollment-only revision; original academic information and evidence remain in source revision ".$record->revision.'.' : '')),
                        'correction_reason' => $data['correction_reason'] ?? null, 'lock_version' => $record?->lock_version ?? 0,
                        'action' => $data['enrollment_status'] === 'unverified' ? 'save' : 'verify',
                    ]);
                    $this->handle($actor, $payload, $files, $record, (bool) $correction, $stored, true);
                }

                return $students->count();
            });
        } catch (Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('academic')->delete($path);
            }
            throw $exception;
        }
    }

    public function handle(User $actor, array $data, array $files = [], ?AcademicRecord $existing = null, bool $correction = false, ?array &$groupFiles = null, bool $enrollmentEvidence = false): AcademicRecord
    {
        if (! $enrollmentEvidence && ! ProgramAcademicRequirement::storageReady()) {
            throw ValidationException::withMessages(['grades' => 'The developer must apply the academic computation migration before saving these academic forms.']);
        }
        $stored = [];
        try {
            // Do not retry a transaction that has written files; cleanup applies to every failure.
            return DB::transaction(function () use ($actor, $data, $files, $existing, $correction, &$stored, &$groupFiles, $enrollmentEvidence) {
                $student = Student::whereKey($existing?->student_id ?? $data['student_id'])->lockForUpdate()->firstOrFail();
                $periodId = $existing?->academic_period_id ?? $data['academic_period_id'];
                $latest = $student->academicRecords()->where('academic_period_id', $periodId)->orderByDesc('revision')->lockForUpdate()->first();
                if ($existing) {
                    $existing = AcademicRecord::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                    Gate::forUser($actor)->authorize($correction ? 'correct' : 'update', $existing);
                    if ($latest?->id !== $existing->id || $existing->lock_version !== (int) $data['lock_version']) {
                        throw ValidationException::withMessages(['lock_version' => 'This record changed. Reload it before saving.']);
                    }
                } else {
                    Gate::forUser($actor)->authorize('create', AcademicRecord::class);
                    if ($latest) {
                        throw ValidationException::withMessages(['academic_period_id' => 'A record already exists for this scholar and semester. Open it to edit a draft or create a correction.']);
                    }
                }

                $originalGrades = $existing && ! $enrollmentEvidence ? $existing->grades()->lockForUpdate()->get()->keyBy('id') : collect();
                $record = $correction || ! $existing || $enrollmentEvidence ? new AcademicRecord : $existing;
                $before = $record->exists ? $record->only($record->getFillable()) : [];
                $record->fill(Arr::only($data, $enrollmentEvidence
                    ? ['course', 'year_level', 'enrollment_status', 'source_reference', 'verification_notes']
                    : ['course', 'year_level', 'enrollment_status', 'academic_standing', 'source_reference', 'verification_notes', 'certified_percentage_gwa', 'certified_gwa_reference']));
                if ($correction && ! $enrollmentEvidence) {
                    $record->grade_average = $existing->grade_average;
                    $record->units_enrolled = $existing->units_enrolled;
                    $record->units_completed = $existing->units_completed;
                    $record->academic_summary = $existing->academic_summary;
                }
                $record->student_id = $student->id;
                $record->academic_period_id = $periodId;
                if (! $record->exists) {
                    $record->revision = ($latest?->revision ?? 0) + 1;
                    $record->supersedes_id = $existing && ($correction || $enrollmentEvidence) ? $existing->id : null;
                    $record->correction_reason = $correction ? $data['correction_reason'] : null;
                } else {
                    $record->lock_version++;
                }
                // Actor and timestamp cannot be supplied by the browser.
                $verifying = $data['action'] === 'verify';
                $record->verification_status = 'pending';
                $record->verified_by = null;
                $record->verified_at = null;
                if (! $record->save()) {
                    throw new \RuntimeException('Academic record save was canceled.');
                }
                if (! $enrollmentEvidence && array_key_exists('grades', $data)) {
                    $grades = [];
                    foreach ($data['grades'] as $row) {
                        $original = isset($row['grade_id']) ? $originalGrades->get($row['grade_id']) : null;
                        if (isset($row['grade_id']) && ! $original) {
                            throw ValidationException::withMessages(['grades' => 'A subject reference does not belong to this academic revision. Reload the record.']);
                        }
                        $grade = Arr::only($row, ['subject_code', 'subject_name', 'units', 'prelim', 'midterm', 'semi_final', 'final', 'average', 'remarks', 'reported_status']);
                        if ($original) {
                            $grade['equivalent'] = $original->equivalent;
                        }
                        $grades[] = $grade;
                    }
                    $record->grades()->delete();
                    $savedGrades = $record->grades()->createMany($grades);
                    if ($savedGrades->contains(fn ($grade) => ! $grade->exists)) {
                        throw new \RuntimeException('Academic subject save was canceled.');
                    }
                } elseif ($correction && ! $enrollmentEvidence) {
                    $savedGrades = $record->grades()->createMany($originalGrades->map(fn ($grade) => $grade->only($grade->getFillable()))->values()->all());
                    if ($savedGrades->contains(fn ($grade) => ! $grade->exists)) {
                        throw new \RuntimeException('Academic subject revision copy was canceled.');
                    }
                }
                if ($verifying) {
                    if (! $record->update(['verification_status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()])) {
                        throw new \RuntimeException('Academic verification was canceled.');
                    }
                }

                foreach ($files as $file) {
                    $path = $file->store('certificates/'.$record->id, 'academic');
                    $stored[] = $path;
                    if ($groupFiles !== null) {
                        $groupFiles[] = $path;
                    }
                    $document = $record->documents()->create([
                        'disk' => 'academic', 'storage_path' => $path,
                        'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                        'document_type' => $enrollmentEvidence ? 'enrollment_certificate' : 'grade_certificate', 'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
                        'uploaded_by' => $actor->id,
                    ]);
                    $this->audit->record($actor, $document, $enrollmentEvidence ? 'enrollment_certificate_uploaded' : 'grade_certificate_uploaded');
                }
                if ($verifying) {
                    $record->documents()->where('review_status', 'pending')->update([
                        'review_status' => 'accepted', 'reviewed_by' => $actor->id, 'reviewed_at' => $record->verified_at,
                    ]);
                }
                $this->audit->record($actor, $record, $correction ? 'academic_record_corrected' : ($verifying ? 'academic_record_verified' : 'academic_record_saved'), $before, $data['correction_reason'] ?? null);

                return $record;
            });
        } catch (Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('academic')->delete($path);
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['academic_period_id' => 'This scholar and semester already have a record. Reload the latest revision.']);
            }
            throw $exception;
        }
    }
}
