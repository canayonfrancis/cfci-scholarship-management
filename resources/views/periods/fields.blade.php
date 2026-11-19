@php
    $formErrors = $errors->getBag('periodForm');
    $fieldErrorBag = $recover ? 'periodForm' : 'freshPeriodForm';
    $fieldValue = fn ($field, $fallback = '') => $recover ? old($field, $fallback) : $fallback;
@endphp
<input type="hidden" name="_registry_form" value="{{ $newYear ? 'year' : 'semester' }}">
@if($recover && $formErrors->any())
    <div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>Please check the highlighted fields.</strong><ul>@foreach($formErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if($identityLocked)<p class="records-notice">This semester is linked to existing records. Its academic year and semester code are preserved.</p>@endif
<p class="record-help">Fields marked * are required. Availability is managed for each semester.</p>
<div class="records-form-grid">
    @if($newYear)
        <x-record-field :id="$prefix.'-year-start'" name="year_start" label="Start year *" type="number" :value="$fieldValue('year_start')" :error-bag="$fieldErrorBag" min="2007" max="9998" step="1" required />
        <x-record-field :id="$prefix.'-year-end'" name="year_end" label="End year *" type="number" :value="$fieldValue('year_end')" :error-bag="$fieldErrorBag" min="2008" max="9999" step="1" required />
        <p class="record-help">Use consecutive years, such as 2026–2027. The year is registered with its first semester below.</p>
    @elseif($identityLocked)
        <x-record-field :id="$prefix.'-year'" name="academic_year" label="Academic year *" :value="$period->academic_year" :error-bag="$fieldErrorBag" readonly required />
    @else
        <x-record-field :id="$prefix.'-year'" name="academic_year" label="Academic year *" type="select" :error-bag="$fieldErrorBag" required>
            <option value="">Select academic year</option>
            @foreach($yearChoices as $year)<option value="{{ $year }}" @selected($fieldValue('academic_year', $period->academic_year ?? ($filters['academic_year'] ?? '')) === $year)>{{ $year }}</option>@endforeach
        </x-record-field>
        <p class="record-help">To register another year, use <a class="records-link" href="{{ route('academic-years.index') }}">Academic Year</a>.</p>
    @endif
    <x-record-field :id="$prefix.'-code'" name="semester_code" label="Semester code *" :value="$identityLocked ? $period->semester_code : $fieldValue('semester_code', $period->semester_code ?? '')" :error-bag="$fieldErrorBag" maxlength="30" required :readonly="$identityLocked" help="Use the institution's approved semester code. No additional semester types are predefined." />
    <x-record-field :id="$prefix.'-start'" name="starts_on" label="Start date (optional)" type="date" :value="$fieldValue('starts_on', $period->starts_on?->format('Y-m-d') ?? '')" :error-bag="$fieldErrorBag" />
    <x-record-field :id="$prefix.'-end'" name="ends_on" label="End date (optional)" type="date" :value="$fieldValue('ends_on', $period->ends_on?->format('Y-m-d') ?? '')" :error-bag="$fieldErrorBag" />
    <x-record-field :id="$prefix.'-open'" name="is_open" label="Semester status *" type="select" :error-bag="$fieldErrorBag" required>
        <option value="0" @selected((string) $fieldValue('is_open', (int) $period->is_open) === '0')>Closed</option>
        <option value="1" @selected((string) $fieldValue('is_open', (int) $period->is_open) === '1')>Open</option>
    </x-record-field>
</div>
