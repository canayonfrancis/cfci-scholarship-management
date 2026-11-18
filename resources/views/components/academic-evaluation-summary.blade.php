@props(['application', 'context', 'gradeSource', 'academic', 'requirements', 'check', 'ready'])
<section class="dashboard-card records-card">
    <h2 class="records-section-title">Program academic evaluation</h2>
    @unless($ready)<p class="records-alert">The academic computation migration is pending. The developer must apply it before new policy versions or evaluations can be saved.</p>@endunless
    <dl class="records-details">
        <div><dt>Active validation period</dt><dd>{{ $context->activePeriod ? $context->activePeriod->academic_year.' / '.$context->activePeriod->semester_code : 'Not configured' }}</dd></div>
        <div><dt>Source grading period</dt><dd>{{ $context->sourcePeriod ? $context->sourcePeriod->academic_year.' / '.$context->sourcePeriod->semester_code : 'Not confirmed' }}</dd></div>
        <div><dt>Current enrollment</dt><dd>{{ $academic ? Str::headline($academic->enrollment_status).' — '.ucfirst($academic->verification_status) : 'No current-period record' }}</dd></div>
        <div><dt>Registrar academic standing (source period)</dt><dd>{{ $gradeSource ? ucfirst($gradeSource->academic_standing).' — '.ucfirst($gradeSource->verification_status) : 'No source-period record' }}</dd></div>
        <div><dt>Academic requirement version</dt><dd>{{ $requirements ? 'Revision '.$requirements->revision.' — '.ucfirst($requirements->status).' — '.$requirements->version_reference : 'Unconfigured' }}</dd></div>
        <div><dt>Minimum required percentage GWA</dt><dd>{{ ($requirements?->criteria['minimum_gwa_required'] ?? null) === false ? 'Explicitly not required' : ($requirements?->criteria['minimum_gwa'] ?? 'Unconfigured') }}</dd></div>
        <div><dt>Academic requirement result</dt><dd>{{ match($check['status']) { 'pass' => 'Academic requirements met; other compliance gates still apply', 'fail' => 'Approved academic requirement not met', default => 'Pending academic review' } }}</dd></div>
    </dl>
    <p class="{{ $check['status'] === 'pass' ? 'record-help' : 'records-alert' }}">{{ $check['reason'] }}</p>
    @can('update', $application->program)<a class="records-link" href="{{ route('scholarship-programs.show', ['program' => $application->program, 'academic_policy_period_id' => $application->academic_period_id]) }}">Review this program's academic requirements for this evaluation period</a>@endcan
    <p class="record-help">Provisional GWA never supplies an official minimum-GWA decision. A current, Registrar-certified percentage GWA is required where an approved policy specifies a minimum. Numeric failed subjects do not automatically disqualify a scholar. No calculation activates an award.</p>
</section>
