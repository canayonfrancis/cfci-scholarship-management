@props(['record'])
<section class="dashboard-card records-card"><h2 class="records-section-title">Reported subject grades</h2><div class="records-table-wrap"><table class="records-table"><thead><tr><th>Subject</th>@foreach(\App\Models\AcademicRecordGrade::COLUMNS as $label)<th>{{ $label }}</th>@endforeach<th>Remarks</th></tr></thead><tbody>
    @forelse($record->grades as $grade)<tr><td>{{ $grade->subject_name }}<small class="records-muted">{{ $grade->subject_code }}</small></td>@foreach(\App\Models\AcademicRecordGrade::COLUMNS as $column => $label)<td>{{ $grade->$column ?? 'Not recorded' }}</td>@endforeach<td>{{ $grade->remarks ?: '—' }}</td></tr>@empty<tr><td colspan="9">No reported subject grades on this revision.</td></tr>@endforelse
    </tbody></table></div>
</section>
