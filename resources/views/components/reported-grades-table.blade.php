@props(['record', 'showHistoricalEquivalents' => false])
@php
    $columns = array_diff_key(\App\Models\AcademicRecordGrade::COLUMNS, ['equivalent' => true]);
    $columns['average'] = 'Reported average';
    $summary = app(\App\Services\Eligibility\AcademicGradeComputation::class)->forRecord($record);
@endphp
<section class="dashboard-card records-card">
    <h2 class="records-section-title">Subject percentage grades</h2>
    <div class="records-table-wrap"><table class="records-table"><caption class="sr-only">Reported components and backend-computed subject averages</caption><thead><tr><th scope="col">Subject</th>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Computed average</th><th scope="col">Subject result</th><th scope="col">Grade status</th><th scope="col">Remarks</th></tr></thead><tbody>
        @forelse($record->grades as $grade)
            @php($calculation = $summary['rows'][$loop->index])
            <tr><td>{{ $grade->subject_name }}<small class="records-muted">{{ $grade->subject_code }}</small></td>@foreach($columns as $column => $label)<td>{{ $grade->$column ?? 'Not recorded' }}</td>@endforeach
                <td>{{ $calculation['average_display'] ?? 'Not complete' }}@if($calculation['computed_average'] !== null)<small class="records-muted">Unrounded: {{ $calculation['computed_average'] }}</small>@endif</td>
                <td>{{ match($calculation['result']) { 'inc' => 'Incomplete / INC', 'dropped' => 'Dropped / DRP', 'missing' => 'Missing grades', default => Str::headline($calculation['result']) } }}@if($calculation['numeric_result'] && $calculation['numeric_result'] !== $calculation['result'])<small class="records-muted">Calculated: {{ ucfirst($calculation['numeric_result']) }}</small>@endif</td>
                <td>{{ $calculation['grade_status'] }}@foreach($calculation['reasons'] as $reason)<small class="records-muted">{{ $reason }}</small>@endforeach</td><td>{{ $grade->remarks ?: '—' }}</td>
            </tr>
        @empty<tr><td colspan="{{ count($columns) + 5 }}">No reported subject grades on this revision.</td></tr>@endforelse
    </tbody></table></div>
    @if($showHistoricalEquivalents && $record->grades->contains(fn ($grade) => $grade->equivalent !== null))
        <details><summary>Retained historical equivalent grades — not used in any calculation or eligibility check</summary><ul class="records-timeline">@foreach($record->grades->whereNotNull('equivalent') as $grade)<li><strong>{{ $grade->subject_name }}</strong><span>{{ $grade->equivalent }}</span></li>@endforeach</ul></details>
    @endif
</section>
<x-academic-grade-summary :summary="$summary" />