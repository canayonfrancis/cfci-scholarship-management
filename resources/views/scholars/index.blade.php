<x-records-page title="Scholar records" description="Find scholars, maintain their details, and follow their scholarship history.">
    <x-slot:actions>@can('create', \App\Models\Student::class)<a class="records-button is-primary" href="{{ route('scholars.create') }}">Add scholar <x-dashboard-icon name="arrow-right" :size="16" /></a>@endcan</x-slot:actions>
    <section class="dashboard-card records-card" aria-label="Scholar directory">
        <form class="records-filters" action="{{ route('scholars.index') }}" method="GET">
            <x-record-field name="q" label="Search scholars" :value="$filters['q'] ?? ''" placeholder="Student number, name, or email" maxlength="100" type="search" />
            <x-record-field name="program_id" label="Active scholarship" type="select"><option value="">All programs</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($filters['program_id'] ?? '') == $program->id)>{{ $program->code }}</option>@endforeach</x-record-field>
            <x-record-field name="record_status" label="Record status" type="select"><option value="">All records</option><option value="active" @selected(($filters['record_status'] ?? '') === 'active')>Active</option><option value="archived" @selected(($filters['record_status'] ?? '') === 'archived')>Archived</option></x-record-field>
            <div class="records-actions"><x-primary-button class="records-submit">Search</x-primary-button><a class="records-button" href="{{ route('scholars.index') }}">Clear</a></div>
        </form>
        <div class="records-table-wrap"><table class="records-table"><caption class="sr-only">Scholar directory</caption><thead><tr><th scope="col">Student number</th><th scope="col">Scholar</th><th scope="col">Contact</th><th scope="col">Active scholarship</th><th scope="col">Record</th><th scope="col">Actions</th></tr></thead><tbody>
            @forelse($scholars as $scholar)
                <tr><td>{{ $scholar->student_number }}</td><td><a class="records-link" href="{{ route('scholars.show', $scholar) }}">{{ $scholar->full_name }}</a><small class="records-muted">{{ $scholar->assignments_count }} scholarship {{ Str::plural('record', $scholar->assignments_count) }}</small></td><td>{{ $scholar->email ?: '—' }}@if($scholar->phone)<small class="records-muted">{{ $scholar->phone }}</small>@endif</td><td>{{ $scholar->activeAssignment?->program?->code ?? 'No active scholarship' }}</td><td><span class="records-badge {{ $scholar->record_status === 'active' ? 'is-green' : '' }}">{{ ucfirst($scholar->record_status) }}</span></td><td><div class="records-actions"><a class="records-link" href="{{ route('scholars.show', $scholar) }}">View</a>@can('update', $scholar)<a class="records-link" href="{{ route('scholars.edit', $scholar) }}">Edit</a>@endcan</div></td></tr>
            @empty<tr><td colspan="6"><div class="records-empty"><x-dashboard-icon name="users" :size="36" /><strong>No scholars found</strong><p>Try another search, or add a scholar to get started.</p></div></td></tr>@endforelse
        </tbody></table></div>
        <div class="records-pagination">{{ $scholars->links() }}</div>
    </section>
</x-records-page>
