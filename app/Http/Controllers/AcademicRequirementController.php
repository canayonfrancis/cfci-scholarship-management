<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Http\Requests\AcademicRequirementRequest;
use App\Models\AuditEvent;
use App\Models\ProgramAcademicRequirement;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcademicRequirementController extends Controller
{
    public function store(AcademicRequirementRequest $request, ScholarshipProgram $program, RecordAudit $audit): RedirectResponse
    {
        if (! ProgramAcademicRequirement::storageReady()) {
            throw ValidationException::withMessages(['academic_policy_period_id' => 'The developer must apply the academic computation migration before saving requirements.']);
        }
        $data = $request->validated();
        DB::transaction(function () use ($request, $program, $data, $audit) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $actor->setRelation('roles', $actor->roles()->lockForUpdate()->get());
            $program = ScholarshipProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $program);
            $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $program->id, 'academic_period_id' => $data['academic_policy_period_id']]);
            $latest = ProgramAcademicRequirement::where('program_period_term_id', $terms->id)->orderByDesc('revision')->lockForUpdate()->first();
            if (($latest?->revision ?? 0) !== (int) $data['previous_version']) {
                throw ValidationException::withMessages(['previous_version' => 'Academic requirements changed. Reload the selected period before saving.']);
            }
            $confirmed = $data['action'] === 'confirm';
            $criteria = [];
            foreach (['minimum_gwa_required', 'require_verified_standing', 'require_current_enrollment', 'require_completed_grades'] as $flag) {
                $criteria[$flag] = isset($data[$flag]) ? (bool) $data[$flag] : null;
            }
            $criteria += [
                'minimum_gwa' => $data['minimum_gwa'] ?? null,
                'max_failed_subjects' => isset($data['max_failed_subjects']) ? (int) $data['max_failed_subjects'] : null,
                // A draft may retain a proposed limit, but it is not an approved requirement.
                'failed_limit_approved' => $confirmed && isset($data['max_failed_subjects']) && $request->boolean('failed_limit_approved'),
                'incomplete_handling' => $data['incomplete_handling'] ?? null,
                'dropped_handling' => $data['dropped_handling'] ?? null,
            ];
            $version = ProgramAcademicRequirement::create([
                'program_period_term_id' => $terms->id, 'revision' => ($latest?->revision ?? 0) + 1,
                'criteria' => $criteria, 'version_reference' => $data['version_reference'] ?? null,
                'source_reference' => $data['source_reference'] ?? null,
                'status' => $confirmed ? 'confirmed' : 'draft', 'created_by' => $actor->id,
                'confirmed_by' => $confirmed ? $actor->id : null, 'confirmed_at' => $confirmed ? now() : null,
            ]);
            if (! $version->exists) {
                throw new \RuntimeException('Academic policy version could not be saved.');
            }
            $action = $confirmed ? 'academic_policy_confirmed' : 'academic_policy_draft_recorded';
            $audit->record($actor, $version, $action);
            if (! AuditEvent::where('subject_type', ProgramAcademicRequirement::class)->where('subject_id', $version->id)->where('actor_id', $actor->id)->where('action', $action)->exists()) {
                throw new \RuntimeException('Academic policy audit could not be saved.');
            }
        }, 3);

        return to_route('scholarship-programs.show', ['program' => $program, 'academic_policy_period_id' => $data['academic_policy_period_id']], 303)
            ->with('status', 'A new academic requirement version was recorded. Prior evaluations retain their snapshots; changed requirements need reassessment.');
    }
}
