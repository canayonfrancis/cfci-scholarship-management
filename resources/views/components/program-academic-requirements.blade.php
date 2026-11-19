@props(['program', 'periods', 'period', 'versions', 'ready'])
<details class="dashboard-card records-card">
    <summary>Historical academic policy versions (read-only)</summary>
    <p class="record-help">Retained records only. Grade policies are no longer operational evaluation prerequisites.</p>
    <p class="record-help">If a retained policy records a mandatory provider or institutional requirement, obtain confirmation before treating it as waived. Admission and retention compliance still require actual supporting sources.</p>
    <form method="GET" action="{{ route('scholarship-programs.show', $program) }}" class="records-filters">
        <x-record-field name="academic_policy_period_id" label="Historical policy period" type="select" required><option value="">Select period</option>@foreach($periods as $choice)<option value="{{ $choice->id }}" @selected($period?->id === $choice->id)>{{ $choice->academic_year }} / Semester {{ $choice->semester_code }}</option>@endforeach</x-record-field>
        <button class="records-button" type="submit">View retained versions</button>
    </form>
    @forelse($versions as $version)<details><summary>Revision {{ $version->revision }} / {{ ucfirst($version->status) }} / {{ $version->version_reference }}</summary><p class="record-help">{{ $version->source_reference }} / {{ $version->confirmedBy?->name ?? 'Not confirmed' }}</p><x-evaluation-snapshot :value="$version->snapshot()" /></details>
    @empty<p class="record-help">No retained versions for the selected period.</p>@endforelse
</details>
