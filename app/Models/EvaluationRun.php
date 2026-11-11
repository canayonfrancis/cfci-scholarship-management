<?php

namespace App\Models;

use App\Services\Eligibility\PrivateEvidence;
use App\Services\Eligibility\SchoolGradingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    protected $fillable = [
        'application_id',
        'student_id',
        'program_id',
        'academic_period_id',
        'run_number',
        'application_revision',
        'grade_record_id',
        'enrollment_record_id',
        'rule_version',
        'input_snapshot',
        'rule_snapshot',
        'check_results',
        'automatic_outcome',
        'school_decision',
        'coordinator_id',
        'decided_at',
        'completed_at',
        'decision_reason',
        'override_reason',
        'evaluated_by',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'application_revision' => 'integer',
            'input_snapshot' => 'array',
            'rule_snapshot' => 'array',
            'check_results' => 'array',
            'decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'evaluated_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'application_id');
    }

    /** A historical decision is never implicitly reusable after its inputs change. */
    public function isCurrent(): bool
    {
        $run = $this->fresh();
        if (! $run || ! $run->completed_at || ! $run->decided_at || $run->school_decision === 'pending') {
            return false;
        }
        if (($run->input_snapshot['assessment_scope'] ?? null) !== SchoolGradingPolicy::SCOPE ||
            ($run->input_snapshot['grading_policy_check']['status'] ?? 'review') !== 'pass') {
            return false;
        }
        $context = AcademicValidationContext::current();
        $pinnedContext = $run->input_snapshot['academic_context'] ?? [];
        if ($context->active_period_id !== $run->academic_period_id || $context->revision !== ($pinnedContext['revision'] ?? null) ||
            $context->source_period_id !== ($pinnedContext['source_period_id'] ?? null)) {
            return false;
        }
        $application = $run->application()->first();
        if (! $application || $application->status === 'withdrawn' || $application->submission_revision !== $run->application_revision ||
            $application->evaluationRuns()->where('run_number', '>', $run->run_number)->exists()) {
            return false;
        }
        if (array_key_exists('conflicting_assignment_id', $run->input_snapshot ?? [])) {
            $conflict = $application->student->assignments()->where('status', 'active')->whereKeyNot($application->assignment_id)->value('id');
            if ($conflict !== $run->input_snapshot['conflicting_assignment_id']) {
                return false;
            }
        }
        foreach ($run->input_snapshot['evidence'] ?? [] as $snapshot) {
            $document = $application->documents()->find($snapshot['id']);
            if (! $document || $document->checksum !== $snapshot['checksum'] || ! app(PrivateEvidence::class)->usable($document)) {
                return false;
            }
        }
        foreach (['grade' => $run->gradeRecord()->first(), 'enrollment' => $run->enrollmentRecord()->first()] as $kind => $record) {
            $snapshot = $run->input_snapshot['source_records'][$kind] ?? null;
            $pinnedVerified = $snapshot && ($snapshot['id'] ?? null) === $record?->id && ($snapshot['revision'] ?? null) === $record?->revision
                && ($snapshot['lock_version'] ?? null) === $record?->lock_version && ($snapshot['verification_status'] ?? null) === 'verified'
                && ($snapshot['verified_by'] ?? null) === $record?->verified_by;
            if (! $record || $record->verification_status !== 'verified' || ! $record->verified_at || ! $record->isLatestRevision()) {
                return false;
            }
            if ($record->verified_at->gt($run->completed_at) || $record->verified_at->gt($run->decided_at)) {
                return false;
            }
            if (! $pinnedVerified && ($record->verified_at->equalTo($run->completed_at) || $record->verified_at->equalTo($run->decided_at))) {
                return false;
            }
        }

        return true;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function gradeRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'grade_record_id');
    }

    public function enrollmentRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'enrollment_record_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    public function resultLabel(): string
    {
        return ['passed' => 'eligible', 'failed' => 'ineligible', 'needs_review' => 'needs_review', 'pending' => 'needs_review'][$this->automatic_outcome];
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'evaluation_run_id');
    }
}
