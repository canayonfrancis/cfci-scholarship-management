@php
    $tabs = ['overview' => 'Overview', 'academic-records' => 'Academic records', 'school-compliance' => 'School compliance', 'supporting-evidence' => 'Supporting evidence', 'external-approval' => 'External approval', 'award-activation' => 'Award activation', 'history' => 'History'];
    $formTabs = ['policy' => 'school-compliance', 'evaluate' => 'school-compliance', 'evidence' => 'supporting-evidence', 'review' => 'supporting-evidence', 'external' => 'external-approval', 'activate' => 'award-activation'];
    $formHint = old('_workspace_form');
    $failedForm = is_string($formHint) && isset($formTabs[$formHint]) ? $formHint : null;
    // Legacy/direct requests may not have the presentation-only form hint.
    if ($errors->any() && !$failedForm) {
        $failedForm = $errors->has('award') || $errors->has('evaluation_id') || $errors->has('approval_id') ? 'activate'
            : ($errors->has('confirm_policy') ? 'policy' : (old('previous_approval') !== null ? 'external'
            : (old('review_status') !== null ? 'review' : (old('document_type') !== null ? 'evidence' : 'evaluate'))));
    }
    $requestedTab = request()->query('tab');
    $activeTab = $errors->any() && $failedForm ? $formTabs[$failedForm] : (is_string($requestedTab) && isset($tabs[$requestedTab]) ? $requestedTab : 'overview');
    $prior = $workspace['prior'];
    $lastApproval = $workspace['lastApproval'];
    $oldFor = fn ($form, $key, $fallback = null) => $failedForm === $form ? old($key, $fallback) : $fallback;
    $checkedFor = fn ($form, $key) => in_array($oldFor($form, $key, false), [true, 1, '1', 'yes', 'on', 'true'], true);
    $errorsFor = fn ($form, $key) => $failedForm === $form ? $errors->get($key) : [];
    $hasGroupErrors = fn ($keys) => $failedForm === 'evaluate' && collect($errors->keys())->contains(fn ($error) => collect($keys)->contains(fn ($key) => $error === $key || str_starts_with($error, $key.'.')));
    $beneficiary = $oldFor('evaluate', 'applicant_type', $prior['applicant_type'] ?? 'new');
    $beneficiary = in_array($beneficiary, ['new', 'continuing'], true) ? $beneficiary : 'new';
    $reviewTarget = old('_workspace_document');
    $reviewTarget = is_scalar($reviewTarget) ? (string) $reviewTarget : null;
    $initial = ['activeTab' => $activeTab, 'tabs' => array_keys($tabs), 'program' => $application->program->code, 'requiredFacts' => $workspace['requiredFacts'], 'factKeys' => array_keys($facts), 'applicantType' => $beneficiary, 'hasErrors' => $errors->any(), 'failedForm' => $failedForm, 'reviewDocument' => $reviewTarget, 'errorKeys' => $failedForm === 'evaluate' ? $errors->keys() : [], 'evidenceErrors' => $failedForm === 'evidence' && $errors->any()];
    $gradeSummary = $workspace['gradeSummary'];
@endphp
<x-records-page :title="$application->student->full_name" :description="$application->program->code.' · '.$application->academicPeriod->academic_year.' / Semester '.$application->academicPeriod->semester_code">
    <x-slot:actions><a class="records-button" href="{{ route('evaluations.index') }}">Back to evaluations</a></x-slot:actions>
    <div class="evaluation-workspace" x-data="evaluationWorkspace(@js($initial))" @input="changed($event)" @change="changed($event)" @submit.capture="submit($event)" @invalid.capture="revealInvalid($event)">
        <section class="dashboard-card records-card evaluation-identity" aria-label="Scholar and evaluation summary">
            <div class="evaluation-identity-heading"><div><p class="records-eyebrow">Evaluation workspace</p><strong>{{ $application->program->name }}</strong></div><div class="records-actions"><span class="records-badge">Assignment: {{ $application->assignment?->statusLabel() ?? 'Missing' }}</span><span class="records-badge {{ $workspace['current'] && $latest?->school_decision === 'qualified' ? 'is-green' : 'is-amber' }}">School: {{ $latest ? Str::headline($latest->school_decision) : 'Not evaluated' }}</span>@if($latest && !$workspace['current'])<span class="records-badge is-amber">Requires reassessment</span>@endif</div></div>
            <dl class="evaluation-summary-grid">
                <div><dt>Student ID</dt><dd>{{ $application->student->student_number }}</dd></div>
                <div><dt>Course / year level</dt><dd>{{ $academic?->course ?: ($application->student->academicProgram?->name ?? 'Not recorded') }} · {{ $academic?->year_level ?: ($application->student->year_level ?? 'Not recorded') }}</dd></div>
                <div><dt>Enrollment</dt><dd>{{ $academic ? Str::headline($academic->enrollment_status).' · '.ucfirst($academic->verification_status) : 'No current-period record' }}</dd></div>
                <div><dt>Registrar standing</dt><dd>{{ $gradeSource ? ucfirst($gradeSource->academic_standing).' · '.ucfirst($gradeSource->verification_status) : 'No source-period record' }}</dd></div>
                <div><dt>Provisional percentage GWA</dt><dd>{{ $gradeSummary['gwa_display'] ?? 'Not available' }} <span class="records-badge is-amber">Provisional</span></dd></div>
                <div><dt>Certified GWA for evaluation</dt><dd>{{ $academicCheck['summary']['certified_display'] ?? 'Not recorded / not usable' }}</dd></div>
                <div><dt>Minimum required GWA</dt><dd>{{ ($academicRequirements?->criteria['minimum_gwa_required'] ?? null) === false ? 'Explicitly not required' : ($academicRequirements?->criteria['minimum_gwa'] ?? 'Unconfigured') }}@unless($workspace['policyConfirmed'])<span class="records-muted">Policy unconfirmed</span>@endunless</dd></div>
                <div><dt>Academic requirement result</dt><dd>{{ match($academicCheck['status']) { 'pass' => 'Requirements met', 'fail' => 'Requirement not met', default => 'Pending review' } }}</dd></div>
            </dl>
            <p class="record-help">Provisional GWA is not an officially certified Registrar GWA. Saved source records and decisions determine readiness; opening a tab does not complete a requirement.</p>
        </section>
        @unless($academicPolicyReady)<div class="records-alert">The academic database upgrade is pending. New academic policies and evaluations remain unavailable until the existing upgrade is reviewed and applied.</div>@endunless
        @if($context->active_period_id !== $application->academic_period_id)<div class="records-alert">This evaluation belongs to a historical period. Its retained decisions cannot authorize a current award.</div>@endif
        <ol class="evaluation-progress" aria-label="Evaluation progress">
            @foreach($workspace['stages'] as $stage)<li class="{{ $stage['status'] === 'Completed' ? 'is-complete' : '' }}"><a href="{{ route('evaluations.show', ['application' => $application, 'tab' => $stage['tab']]) }}" @click.prevent="selectTab('{{ $stage['tab'] }}', true)"><span class="evaluation-step-number" aria-hidden="true">{{ $loop->iteration }}</span><span><strong>{{ $stage['title'] }}</strong><span class="records-badge {{ $stage['status'] === 'Completed' ? 'is-green' : 'is-amber' }}">{{ $stage['status'] }}</span></span></a><details><summary>Why this status?</summary><p class="record-help">{{ $stage['reason'] }}</p></details></li>@endforeach
        </ol>
        <nav class="evaluation-tabs" role="tablist" aria-label="Evaluation sections" @keydown="tabKey($event)">
            @foreach($tabs as $key => $label)<a id="evaluation-tab-{{ $key }}" role="tab" href="{{ route('evaluations.show', ['application' => $application, 'tab' => $key]) }}" aria-controls="evaluation-panel-{{ $key }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" :aria-selected="activeTab === '{{ $key }}'" :tabindex="activeTab === '{{ $key }}' ? 0 : -1" :class="activeTab === '{{ $key }}' ? 'is-active' : ''" @click.prevent="selectTab('{{ $key }}')">{{ $label }}@if($errors->any() && $failedForm && $formTabs[$failedForm] === $key)<span class="evaluation-error-dot" aria-label="Contains validation errors">!</span>@endif</a>@endforeach
        </nav>
        @foreach($tabs as $key => $label)
            <section id="evaluation-panel-{{ $key }}" class="evaluation-tab-panel" role="tabpanel" aria-labelledby="evaluation-tab-{{ $key }}" tabindex="0" x-show="activeTab === '{{ $key }}'" @if($activeTab !== $key) style="display:none" @endif>
                @if($errors->any() && $failedForm && $formTabs[$failedForm] === $key)<div class="records-alert" data-workspace-errors tabindex="-1" role="alert"><strong>This operation was not saved. Review the marked fields below.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@if(in_array($failedForm, ['evidence', 'external'], true))<p class="record-help">For security, the browser cannot restore an uploaded file after this reload. Select the file again before resubmitting.</p>@endif</div>@endif
                @include('evaluations.partials.'.$key)
            </section>
        @endforeach
        <noscript><p class="record-help">JavaScript enables tab switching without losing pending values. With JavaScript disabled, tab links reload the page. Save your current form before following them.</p></noscript>
    </div>
</x-records-page>
