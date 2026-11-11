@props(['summaries'])
<div class="records-table-wrap"><table class="records-table"><caption class="sr-only">Scholarship fund and budget summary in Philippine pesos</caption><thead><tr><th>Program / semester</th><th>Approved budget</th><th>Approved allocations</th><th>Remaining budget</th><th>Awaiting approval</th><th>Actually received</th><th>Net cash released</th></tr></thead><tbody>
@forelse($summaries as $summary)
    <tr><td>{{ $summary['terms']->program->code }}<small class="records-muted">{{ $summary['terms']->academicPeriod->academic_year }} / Semester {{ $summary['terms']->academicPeriod->semester_code }}</small></td>
        <td>{{ $summary['budget'] ? '₱'.number_format((float) $summary['budget']->amount, 2) : 'Unconfigured' }}</td>
        <td>₱{{ number_format((float) $summary['allocated'], 2) }}<small class="records-muted">{{ $summary['scholar_count'] }} scholars; adjustments ₱{{ number_format((float) $summary['adjustments'], 2) }}</small></td>
        <td>{{ $summary['remaining'] === null ? 'Unconfigured' : '₱'.number_format((float) $summary['remaining'], 2) }}</td>
        <td>{{ $summary['pending'] === null ? 'Unconfirmed' : '₱'.number_format((float) $summary['pending'], 2) }}</td>
        <td>₱{{ number_format((float) $summary['received'], 2) }}</td><td>₱{{ number_format((float) $summary['released_net'], 2) }}<small class="records-muted">Direct-to-student: ₱{{ number_format((float) $summary['direct_paid_net'], 2) }} (excluded from school cash)</small></td></tr>
@empty<tr><td colspan="7">No semester fund configurations match these filters.</td></tr>@endforelse
</tbody></table></div>
