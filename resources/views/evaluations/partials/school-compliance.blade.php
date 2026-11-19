@php($hasComplianceBasis = (bool) ($policy && $policyApplicable))
@php($demonstration = (bool) ($policy['demonstration'] ?? false))
<section class="dashboard-card records-card">
    <h2 class="records-section-title">School compliance</h2>
    <dl class="evaluation-summary-grid">
        <div><dt>Current semester enrollment</dt><dd>{{ $academic ? Str::headline($academic->enrollment_status).' / '.ucfirst($academic->verification_status) : 'Unverified - no current-period source' }}</dd></div>
        <div><dt>Applicable compliance basis</dt><dd>{{ $hasComplianceBasis ? $policy['version'] : 'Institution/provider confirmation required' }}@if($hasComplianceBasis)<span class="records-muted">{{ $workspace['officialConfirmed'] ? 'Confirmed for this semester' : 'Recorded semester confirmation required' }}</span>@endif</dd></div>
        <div><dt>Latest school result</dt><dd>{{ $latest ? Str::headline($latest->school_decision) : 'Not evaluated' }}@if($latest && !$workspace['current'])<span class="records-muted">Requires reassessment; original decision retained in History.</span>@endif</dd></div>
    </dl>
    <p class="record-help">Enrollment, school compliance and external scholarship approval are separate checks. An enrolled scholar is not automatically qualified or payable.</p>
    @unless($hasComplianceBasis)
        @if(!$demonstrationIdentityAllowed)
        <p class="records-alert">Demonstration setup or sample identity is incomplete. Certification assessment requires the enabled isolated demonstration site and new UAT-DEF- sample scholars with an @example.invalid email. No actual beneficiary can be approved with this fictional basis.</p>
        @else
        <p class="records-alert">There is no approved school-compliance basis for {{ $application->program->code }} in {{ $application->academicPeriod->academic_year }} / Semester {{ $application->academicPeriod->semester_code }}. No TDP/TES checklist is imposed without an approved program/semester mapping. Record Needs review until the applicable requirements and acceptance of certification are confirmed.</p>
        @if($canRecordInstitutionalBasis)<p class="record-help">An authorized Coordinator can confirm the applicable certification requirements for this program and semester below. No approving authority entry or program-level evidence upload is required. Each scholar's enrollment, school evaluation and external award approval remain separate.</p>@endif
        @endif
    @endunless
    <p class="record-help"><a class="records-link" href="{{ route('evaluations.show', ['application' => $application, 'tab' => 'supporting-evidence']) }}" @click.prevent="selectTab('supporting-evidence', true)">Review supporting documents and references</a>. Accepted uploads remain evidence; they do not approve an unsupported program's compliance rules.</p>
    @if($application->documents->isNotEmpty())<details><summary>Recorded document references</summary><ul>@foreach($application->documents->sortBy('id')->groupBy('document_type')->map->last() as $document)<li>{{ $documentTypes[$document->document_type] ?? Str::headline($document->document_type) }} / {{ $document->original_name }} / Review status: {{ ucfirst($document->review_status) }}</li>@endforeach</ul></details>@endif
</section>
@if(!$hasComplianceBasis && $canRecordInstitutionalBasis)
    @can('evaluate', $application)
    <details class="evaluation-accordion" @if($failedForm === 'policy') open @endif data-has-errors="{{ $failedForm === 'policy' && $errors->any() ? 'true' : 'false' }}">
        <summary><strong>Record approved certification basis</strong><span class="records-badge is-amber">Semester confirmation required</span></summary>
        <div class="evaluation-accordion-body">
            @if($application->terms->confirmed_at)
                <p class="records-alert">This semester already has an immutable confirmation, but its basis or authorizing evidence is not usable. Review the retained confirmation and original evidence. This form cannot replace historical criteria; a separately reviewed correction is required if the recorded basis is incompatible.</p>
                <x-input-error :messages="$errorsFor('policy', 'confirm_policy')" />
            @else
                <p class="record-help">Record the applicable requirements once for {{ $application->program->code }} / {{ $application->academicPeriod->academic_year }} / Semester {{ $application->academicPeriod->semester_code }}. If the requirements are unknown, retain Needs review. No separate approving authority or program evidence upload is needed.</p>
                <form id="evaluation-policy-form" method="POST" action="{{ route('evaluations.policy', $application) }}" data-workspace-form="policy" data-tab="school-compliance">
                    @csrf<input type="hidden" name="_workspace_form" value="policy"><input type="hidden" name="institutional_basis" value="1">
                    <div class="records-form-grid">
                        <x-record-field id="basis-source" name="source" label="Approval reference" :value="$oldFor('policy', 'source')" maxlength="1000" required />
                        <x-record-field id="basis-approved-on" name="approved_on" label="Approval date" type="date" :value="$oldFor('policy', 'approved_on')" :max="now('Asia/Manila')->toDateString()" required />
                    </div>
                    <x-record-field id="basis-requirements" name="requirements_reference" label="Applicable mandatory requirements / acceptance of certification" type="textarea" :value="$oldFor('policy', 'requirements_reference')" maxlength="3000" required help="Identify the approved requirements and provision accepting school certification for this program and semester. This records the existing authority; it does not create eligibility rules." />
                    <label for="confirm_policy"><input id="confirm_policy" name="confirm_policy" type="checkbox" value="1" required @checked($checkedFor('policy', 'confirm_policy')) aria-describedby="confirm_policy-error"> I reviewed the applicable requirements and confirm that institutional certification is accepted for this exact program and semester. Mandatory provider requirements and external award approval remain required.</label>
                    <x-input-error id="confirm_policy-error" :messages="$errorsFor('policy', 'confirm_policy')" />
                    <div class="records-form-footer"><x-primary-button class="records-submit">Record approved basis</x-primary-button></div>
                </form>
            @endif
        </div>
    </details>
    @endcan
@endif
@if($hasComplianceBasis)
    <details class="evaluation-accordion" @if($failedForm === 'policy') open @endif data-has-errors="{{ $failedForm === 'policy' && $errors->any() ? 'true' : 'false' }}">
        <summary><strong>Applicable {{ $application->program->code }} rules</strong><span class="records-badge {{ $workspace['officialConfirmed'] ? 'is-green' : 'is-amber' }}">{{ $workspace['officialConfirmed'] ? 'Confirmed' : 'Confirmation required' }}</span></summary>
        <div class="evaluation-accordion-body">
            @if($demonstration)
                <p class="records-alert">{{ $policy['source'] }}. This sample is not institutional or provider authorization for actual CoScho scholars. Requirements: Registrar-verified enrollment, reviewed fictional certification, accepted certification and enrollment evidence. External award approval remains separate.</p>
            @elseif($institutional)
                <p>{{ $policy['source'] }} / {{ $policy['approved_on'] }}</p>
                <p class="record-help">{{ $policy['requirements_reference'] }}</p>
                @if(isset($policy['authorization_document']))<p class="record-help">This historical confirmation retains its original authority and private evidence requirements.</p>@endif
                <p class="record-help">Each scholar requires an individual supported determination; external approval is recorded separately.</p>
            @else
                <p><a class="records-link" href="{{ $policy['source'] }}" target="_blank" rel="noopener">{{ $policy['version'] }}</a></p>
            @endif
            @if($application->terms->confirmed_at)
                <p class="record-help">Recorded confirmation: {{ $application->terms->confirmedBy?->name ?? 'Historical actor' }} / {{ $application->terms->confirmed_at->timezone('Asia/Manila')->format('M j, Y') }}.</p>
                @unless($workspace['officialConfirmed'])<p class="records-alert">The retained rule confirmation does not match the applicable basis. It cannot authorize a positive decision.</p>@endunless
            @else @can('evaluate', $application)
                <form id="evaluation-policy-form" method="POST" action="{{ route('evaluations.policy', $application) }}" data-workspace-form="policy" data-tab="school-compliance">@csrf<input type="hidden" name="_workspace_form" value="policy">
                    <label for="confirm_policy"><input id="confirm_policy" name="confirm_policy" type="checkbox" value="1" required @checked($checkedFor('policy', 'confirm_policy')) aria-invalid="{{ $errorsFor('policy', 'confirm_policy') ? 'true' : 'false' }}" aria-describedby="confirm_policy-error"> I reviewed these existing rules and confirm their applicability to this program and semester.</label>
                    <x-input-error id="confirm_policy-error" :messages="$errorsFor('policy', 'confirm_policy')" />
                    <div class="records-form-footer"><x-primary-button class="records-submit">Confirm semester rules</x-primary-button></div>
                </form>
            @endcan @endif
            <p class="record-help">External beneficiary selection, prioritization and funding remain outside school compliance. Actual HEI admission/retention and applicable provider requirements still need documented verification.</p>
        </div>
    </details>
@endif
@can('evaluate', $application)
<section class="dashboard-card records-card"><h2 class="records-section-title">Record school evaluation</h2>
    <form id="evaluation-school-form" method="POST" action="{{ route('evaluations.run', $application) }}" data-workspace-form="evaluate" data-tab="school-compliance">
        @csrf<input type="hidden" name="_workspace_form" value="evaluate"><input type="hidden" name="submission_revision" value="{{ $application->submission_revision }}"><input type="hidden" name="previous_run" value="{{ $latest?->run_number ?? 0 }}">
        <x-input-error :messages="$errorsFor('evaluate', 'submission_revision')" /><x-input-error :messages="$errorsFor('evaluate', 'previous_run')" />
        @if($hasComplianceBasis && ($demonstration || $institutional))
            @if($demonstration)<p class="records-alert">{{ \App\Services\Eligibility\OfficialRuleCatalog::DEMO_LABEL }}. Record the finding from the fictional institutional certification. An Eligible selection still requires verified current enrollment, confirmed semester criteria and accepted supporting evidence; it cannot override missing requirements.</p>
            @else<p class="record-help">Record this scholar's actual compliance finding against the confirmed requirements and reviewed certification. An Eligible finding still requires verified current enrollment and accepted certification and enrollment evidence. Unknown requirements must remain Needs review.</p>@endif
            <x-record-field name="school_decision" label="School compliance result" type="select" required>
                @foreach(['needs_information' => 'Needs review', 'qualified' => 'Eligible', 'not_qualified' => 'Ineligible'] as $value => $label)
                    <option value="{{ $value }}" @selected($oldFor('evaluate', 'school_decision', $prior['school_decision'] ?? 'needs_information') === $value)>{{ $label }}</option>
                @endforeach
            </x-record-field>
            <x-record-field name="certification_reference" label="Supporting certification / reference" :value="$oldFor('evaluate', 'certification_reference', $prior['certification_reference'] ?? $prior['fact_sources']['authenticity'] ?? '')" maxlength="1000" required :help="$demonstration ? 'Use the UAT- reference printed on the fictional certification. Upload and accept certification and enrollment documents through Supporting evidence.' : 'Identify the genuine individual certification. Upload and accept its private file and current enrollment evidence through Supporting evidence.'" />
            <label for="certification_verified"><input id="certification_verified" name="certification_verified" type="checkbox" value="1" required @checked($checkedFor('evaluate', 'certification_verified')) aria-invalid="{{ $errorsFor('evaluate', 'certification_verified') ? 'true' : 'false' }}" aria-describedby="certification_verified-error"> {{ $demonstration ? 'I reviewed the fictional institutional certification, its scholar identity, semester, supporting evidence and recorded finding.' : 'I verified this scholar, semester, supporting evidence and recorded finding against the approved certification basis and all applicable mandatory requirements.' }}</label>
            <x-input-error id="certification_verified-error" :messages="$errorsFor('evaluate', 'certification_verified')" />
        @elseif($hasComplianceBasis)
            <x-record-field name="applicant_type" label="Applicable beneficiary category" type="select" required x-model="applicantType">@foreach(['new' => 'New beneficiary', 'continuing' => 'Continuing grantee'] as $value => $label)<option value="{{ $value }}" @selected($beneficiary === $value)>{{ $label }}</option>@endforeach</x-record-field>
            <details id="compliance-details" class="evaluation-accordion" @if($failedForm === 'evaluate' && $errors->any()) open @endif data-has-errors="{{ $failedForm === 'evaluate' && $errors->any() ? 'true' : 'false' }}">
                <summary><strong>Detailed {{ $application->program->code }} compliance checks</strong><span class="records-badge is-amber" x-text="groupStatus('compliance-details')">Review required findings</span></summary>
                <div class="evaluation-accordion-body">
                    <p class="record-help">Only the existing approved program/category mapping is assessed. Select Unverified if a finding or its source is unknown. Hidden category findings are not changed to Yes or Not applicable.</p>
                    <x-input-error :messages="$errorsFor('evaluate', 'facts')" /><x-input-error :messages="$errorsFor('evaluate', 'fact_sources')" />
                    @foreach($facts as $key => $label)<x-evaluation-fact :field="$key" :label="$label" :value="$oldFor('evaluate', 'facts.'.$key, $prior['facts'][$key] ?? 'unverified')" :source="$oldFor('evaluate', 'fact_sources.'.$key, $prior['fact_sources'][$key] ?? '')" :messages="$errorsFor('evaluate', 'facts.'.$key)" :source-messages="$errorsFor('evaluate', 'fact_sources.'.$key)" />@endforeach
                    @php($conditions = ['returning' => 'Returning after LOA', 'transferee' => 'Transferee'] + ($application->program->code === 'TDP' ? ['smart_same_semester' => 'Receiving SMART this semester (TDP)'] : []) + ($application->program->code === 'TES' ? ['legacy_pnsl_transfer' => 'Legacy PNSL transfer (TES)', 'claiming_pwd' => 'Claiming TES-3A disability allowance'] : []))
                    <div class="records-form-grid evaluation-condition-grid">
                        @foreach($conditions as $key => $label)<div data-condition="{{ $key }}"><x-record-field :name="$key" :label="$label" type="select">@foreach(['unverified', 'yes', 'no'] as $value)<option value="{{ $value }}" @selected($oldFor('evaluate', $key, $prior[$key] ?? 'unverified') === $value)>{{ ucfirst($value) }}</option>@endforeach</x-record-field></div>@endforeach
                        <div data-condition="special_case"><x-record-field name="special_case" label="Special category requiring separate guidelines" type="select">@foreach(['unverified', 'none', 'pnsl', 'sardo', 'other'] as $value)<option value="{{ $value }}" @selected($oldFor('evaluate', 'special_case', $prior['special_case'] ?? 'unverified') === $value)>{{ Str::headline($value) }}</option>@endforeach</x-record-field></div>
                        <div data-condition="duration_review"><x-record-field name="duration_review" label="Duration review" type="select">@foreach(['unverified' => 'Unverified', 'within_limit' => 'Within applicable duration', 'approved_exception' => 'Documented approved exception', 'exceeded' => 'Potentially exceeded - review required'] as $value => $label)<option value="{{ $value }}" @selected($oldFor('evaluate', 'duration_review', $prior['duration_review'] ?? 'unverified') === $value)>{{ $label }}</option>@endforeach</x-record-field></div>
                        <div data-condition="duration_basis"><x-record-field name="duration_basis" label="Duration / exception source reference" type="textarea" :value="$oldFor('evaluate', 'duration_basis', $prior['duration_basis'] ?? '')" maxlength="3000" /></div>
                        <div data-condition="exceptions_reviewed"><label for="exceptions_reviewed"><input id="exceptions_reviewed" name="exceptions_reviewed" type="checkbox" value="1" @checked(in_array($oldFor('evaluate', 'exceptions_reviewed', $prior['exceptions_reviewed'] ?? false), [true, 1, '1'], true)) aria-invalid="{{ $errorsFor('evaluate', 'exceptions_reviewed') ? 'true' : 'false' }}" aria-describedby="exceptions_reviewed-error"> Applicable grace periods, LOA/transfer rules and approved exceptions were checked against evidence.</label><x-input-error id="exceptions_reviewed-error" :messages="$errorsFor('evaluate', 'exceptions_reviewed')" /></div>
                    </div>
                </div>
            </details>
        @endif
        <x-record-field name="evaluator_notes" label="Evaluator remarks / supporting reference" type="textarea" :value="$oldFor('evaluate', 'evaluator_notes', $prior['evaluator_notes'] ?? '')" maxlength="3000" :required="$hasComplianceBasis && ($demonstration || $institutional)" />
        <div class="evaluation-submit-footer"><p class="record-help">{{ $hasComplianceBasis ? 'The existing service records Eligible, Ineligible or Needs review from the actual applicable findings and usable evidence. There is no manual eligibility override.' : 'Only a needs-review assessment can be recorded without an approved compliance basis. Supporting certification or external approval does not automatically qualify this award.' }} Prior decisions remain in History; activation and billing stay separate.</p><x-primary-button class="records-submit" :disabled="!$demonstrationIdentityAllowed">{{ $hasComplianceBasis ? 'Evaluate and record school decision' : 'Record needs-review assessment' }}</x-primary-button></div>
    </form>
</section>
@else<section class="dashboard-card records-card"><h2 class="records-section-title">School compliance</h2><p class="record-help">This account has read-only access. Coordinator or Administrator authorization is required to record an assessment.</p></section>@endcan
