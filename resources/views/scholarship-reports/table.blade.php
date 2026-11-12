<div class="records-table-wrap"><table class="records-table">
    <caption>{{ $result['title'] }} · monetary amounts in Philippine pesos</caption>
    <thead><tr>@foreach($result['columns'] as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Source record</th></tr></thead>
    <tbody>@forelse($reportRows as $row)<tr>@foreach($result['columns'] as $key => $label)<td style="white-space:pre-line">{{ $row[$key] ?? 'Unconfirmed' }}</td>@endforeach<td><a class="records-link" href="{{ $row['_url'] }}">Open source</a></td></tr>@empty<tr><td colspan="{{ count($result['columns']) + 1 }}">No qualifying records match these filters. Review any separate evidence/history exceptions below.</td></tr>@endforelse</tbody>
</table></div>
