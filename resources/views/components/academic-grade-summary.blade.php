@props(['summary', 'compact' => false])
<section class="dashboard-card records-card {{ $compact ? 'evaluation-grade-summary' : '' }}" aria-label="Academic grade calculation summary">
    <h2 class="records-section-title">Academic grade summary</h2>
    <dl class="records-details">
        @foreach(['subjects' => 'Number of subjects', 'passed' => 'Computed passed subjects', 'failed' => 'Computed failed subjects', 'incomplete' => 'Incomplete subjects', 'dropped' => 'Dropped subjects', 'missing' => 'Subjects with missing grades', 'reconciliation' => 'Subjects requiring reconciliation', 'unverified' => 'Unverified subjects', 'included' => 'Subjects included in provisional GWA'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $summary['counts'][$key] }}</dd></div>@endforeach
        <div><dt>Total recorded units</dt><dd>{{ $summary['total_units'] }}</dd></div>
        <div><dt>Included units</dt><dd>{{ $summary['included_units'] }}</dd></div>
        <div><dt>Provisional percentage GWA</dt><dd>{{ $summary['gwa_display'] ?? 'Not available' }} <span class="records-badge is-amber">Provisional</span></dd></div>
        @if($summary['reported_gwa'] !== null && $summary['certified_gwa'] === null)<div><dt>Reported GWA awaiting verification</dt><dd>{{ $summary['reported_gwa'] }}<small class="records-muted">Retained as entered; not usable as certified evidence yet.</small></dd></div>@endif
        <div><dt>Registrar-certified percentage GWA</dt><dd>{{ $summary['certified_display'] ?? 'Not recorded / not verified' }}@if($summary['certified_gwa'] !== null)<small class="records-muted">Unrounded certified percentage: {{ $summary['certified_gwa'] }}</small>@endif @if($summary['certified_reference'])<small class="records-muted">{{ $summary['certified_reference'] }}</small>@endif</dd></div>
    </dl>
    @if($compact)<p class="record-help">Provisional GWA is not an officially certified Registrar GWA. Counts and display rounding do not determine scholarship eligibility.</p><details class="mt-4"><summary>Calculation explanation and limitations</summary>@endif
    <p class="record-help">Proposed unit-weighted GWA = sum of complete subject averages × subject units / included units. Completed numeric failing grades are included. These counts describe computed results and can overlap reconciliation/unverified counts; they are not scholarship decisions.</p>
    @if($compact)</details>@endif
    @foreach($summary['warnings'] as $warning)<p class="record-help">{{ $warning }}</p>@endforeach
    @if($summary['excluded'] !== [])
        <details><summary>Excluded subjects and reasons ({{ count($summary['excluded']) }})</summary><ul class="records-timeline">@foreach($summary['excluded'] as $row)<li><strong>{{ $row['subject_name'] }}</strong>@foreach($row['reasons'] as $reason)<p>{{ $reason }}</p>@endforeach</li>@endforeach</ul></details>
    @endif
</section>
