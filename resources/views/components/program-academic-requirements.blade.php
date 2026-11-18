@props(['program', 'periods', 'period', 'versions', 'ready'])
@php
    $current = $versions->first();
    $criteria = $current?->criteria ?? [];
@endphp
<section class="dashboard-card records-card">
    <h2 class="records-section-title">Program-specific academic requirements</h2>
    <p class="record-help">Select the evaluation period explicitly. No GWA threshold or failed-subject limit is assumed. These school requirements remain separate from official semester rules and external approval.</p>
    <form method="GET" action="{{ route('scholarship-programs.show', $program) }}" class="records-filters">
        <x-record-field name="academic_policy_period_id" label="Applicable academic year / semester" type="select" required><option value="">Select a period</option>@foreach($periods as $choice)<option value="{{ $choice->id }}" @selected($period?->id === $choice->id)>{{ $choice->academic_year }} / Semester {{ $choice->semester_code }}</option>@endforeach</x-record-field>
        <button type="submit" class="records-button">Load academic requirements</button>
    </form>
    @if(!$ready)<p class="records-alert">The academic database upgrade is pending. The developer must apply the documented migration before configuring requirements.</p>@endif
    @if($period && $ready)
        <p>Selected period: <strong>{{ $period->academic_year }} / Semester {{ $period->semester_code }}</strong></p>
        <p class="record-help">Current version: {{ $current ? 'Revision '.$current->revision.' — '.ucfirst($current->status) : 'Unconfigured' }}. {{ $current?->version_reference }}</p>
        @if(!$current || $current->status !== 'confirmed')<p class="records-alert">Program-specific academic requirements have not yet been confirmed. Academic compliance remains pending review.</p>@endif
        <dl class="records-details"><div><dt>Minimum percentage GWA</dt><dd>{{ ($criteria['minimum_gwa_required'] ?? null) === false ? 'Explicitly not required' : ($criteria['minimum_gwa'] ?? 'Unconfigured') }}</dd></div><div><dt>Maximum failed subjects</dt><dd>{{ $criteria['max_failed_subjects'] ?? 'Unconfigured' }}<small class="records-muted">Manual review reference only.</small>@if(isset($criteria['max_failed_subjects']) && ($current?->status !== 'confirmed' || ($criteria['failed_limit_approved'] ?? false) !== true))<small class="records-muted">Unconfirmed value; not an approved requirement.</small>@endif</dd></div><div><dt>Approved policy source</dt><dd>{{ $current?->source_reference ?? 'Unconfirmed' }}</dd></div><div><dt>Confirmed by</dt><dd>{{ $current?->confirmedBy?->name ?? 'Not confirmed' }}</dd></div></dl>
        @can('update', $program)
            <form method="POST" action="{{ route('scholarship-programs.academic-requirements.store', $program) }}" class="records-form mt-4">
                @csrf
                <input type="hidden" name="academic_policy_period_id" value="{{ $period->id }}"><input type="hidden" name="previous_version" value="{{ $current?->revision ?? 0 }}">
                <div class="records-form-grid">
                    @foreach(['minimum_gwa_required' => 'Minimum percentage GWA required?', 'require_verified_standing' => 'Verified Registrar academic standing required?', 'require_current_enrollment' => 'Current verified enrollment required?', 'require_completed_grades' => 'Completed academic grades required?'] as $flag => $label)
                        @php($selected = old($flag, isset($criteria[$flag]) ? (string) (int) $criteria[$flag] : ''))
                        <x-record-field :name="$flag" :label="$label" type="select"><option value="">Unconfigured</option><option value="1" @selected((string) $selected === '1')>Yes — supported by policy</option><option value="0" @selected((string) $selected === '0')>No — explicitly confirmed by policy</option></x-record-field>
                    @endforeach
                    <x-record-field name="minimum_gwa" label="Minimum percentage GWA, if required" :value="old('minimum_gwa', $criteria['minimum_gwa'] ?? '')" maxlength="30" inputmode="decimal" help="Blank is unknown, not zero. Compared only with verified Registrar-certified percentage GWA, never the provisional result." />
                    <x-record-field name="max_failed_subjects" label="Maximum failed subjects (optional manual-review reference)" :value="old('max_failed_subjects', $criteria['max_failed_subjects'] ?? '')" type="number" min="0" step="1" help="Blank is unconfigured, not zero. A draft may retain an unapproved value. A configured limit needs explicit approval before policy confirmation; no failed-subject disqualification is automated." />
                    @foreach(['incomplete_handling' => 'INC handling', 'dropped_handling' => 'Dropped-subject handling'] as $field => $label)<x-record-field :name="$field" :label="$label" type="select"><option value="">Unconfirmed</option><option value="review" @selected(old($field, $criteria[$field] ?? '') === 'review')>Academic review required</option></x-record-field>@endforeach
                    <x-record-field name="version_reference" label="Academic policy version / approval reference" :value="old('version_reference', $current?->version_reference)" maxlength="100" help="Required for confirmation; each save appends a retained version." />
                    <x-record-field name="source_reference" label="Approved academic policy source" type="textarea" :value="old('source_reference', $current?->source_reference)" maxlength="4000" help="Cite the approved requirement and its applicability to this program and period. Do not invent thresholds." />
                </div>
                <div class="record-field">
                    <label for="failed_limit_approved"><input id="failed_limit_approved" type="checkbox" name="failed_limit_approved" value="1" @checked(old('failed_limit_approved')) aria-invalid="{{ $errors->has('failed_limit_approved') ? 'true' : 'false' }}" aria-describedby="failed-limit-approval-help failed_limit_approved-error"> I verified the explicit approval of the configured failed-subject limit in this source.</label>
                    <p class="record-help" id="failed-limit-approval-help">Required only when confirming a policy containing a failed-subject limit, including zero. Leave unchecked when the limit is blank. This approval is not required to save a draft.</p>
                    <x-input-error id="failed_limit_approved-error" :messages="$errors->get('failed_limit_approved')" />
                </div>
                <div class="record-field mt-4">
                    <label for="confirm_authority"><input id="confirm_authority" type="checkbox" name="confirm_authority" value="1" @checked(old('confirm_authority')) aria-invalid="{{ $errors->has('confirm_authority') ? 'true' : 'false' }}" aria-describedby="policy-confirmation-help confirm_authority-error"> I reviewed these approved requirements and their applicability to this program and period.</label>
                    <p class="record-help" id="policy-confirmation-help">Save academic policy draft allows incomplete requirements and unchecked approval boxes. Confirm new academic policy version requires complete policy selections, approved source/version references and this confirmation.</p>
                    <x-input-error id="confirm_authority-error" :messages="$errors->get('confirm_authority')" />
                </div>
                <p class="record-help">A new version, including a draft, requires reassessment of older decisions before further reliance. Historical decisions remain unchanged. INC, dropped, missing, unverified, discrepant or failed-subject cases keep their review safeguards.</p>
                <div class="records-actions"><x-primary-button name="action" value="draft">Save academic policy draft</x-primary-button><x-primary-button name="action" value="confirm">Confirm new academic policy version</x-primary-button></div>
            </form>
        @endcan
        @if($versions->isNotEmpty())<details class="mt-4"><summary>Retained academic policy versions</summary><ul class="records-timeline">@foreach($versions as $version)<li><strong>Revision {{ $version->revision }} — {{ ucfirst($version->status) }} — {{ $version->version_reference ?? 'Unconfirmed reference' }}</strong><span>{{ $version->source_reference }} · {{ $version->confirmedBy?->name ?? 'Not confirmed' }}</span><pre class="record-help records-preserve-lines">{{ json_encode($version->criteria, JSON_PRETTY_PRINT) }}</pre></li>@endforeach</ul></details>@endif
    @endif
</section>
