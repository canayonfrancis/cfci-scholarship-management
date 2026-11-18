<x-records-page title="Semester scholarship rosters" description="Assign scholars before enrollment verification or billing. One roster can support multiple separate billing submissions.">
    <x-slot:actions><a class="records-button" href="{{ route('scholars.index') }}">Scholars</a></x-slot:actions>
    <section class="dashboard-card records-card">
        <form class="records-filters" method="GET">
            <x-record-field name="program_id" label="Scholarship program" type="select"><option value="">All programs</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($filters['program_id'] ?? '') == $program->id)>{{ $program->code }}</option>@endforeach</x-record-field>
            <x-record-field name="academic_period_id" label="Academic year / semester" type="select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(($filters['academic_period_id'] ?? '') == $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</x-record-field>
            <button class="records-button" type="submit">Filter</button>
        </form>
        <div class="records-table-wrap"><x-table-state :paginator="$batches" /><table class="records-table"><thead><tr><th>Roster batch</th><th>Program</th><th>Academic year / semester</th><th>Members</th></tr></thead><tbody>
            @forelse($batches as $batch)<tr><td><a class="records-link" href="{{ route('scholarship-batches.show', $batch) }}">{{ $batch->batch_number }}</a></td><td>{{ $batch->program->code }}</td><td>{{ $batch->academicPeriod->academic_year }} / {{ $batch->academicPeriod->semester_code }}</td><td>{{ $batch->memberships_count }}</td></tr>@empty<tr><td colspan="4">No semester rosters match this scope.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $batches->links() }}</div>
    </section>
    @can('create', \App\Models\ScholarshipBatch::class)
        <section class="dashboard-card records-card"><h2>Create semester roster</h2><p class="record-help">No approved rate, enrollment finding or billing record is needed. Add students through their scholar records after recording a pending program assignment.</p>
            <form method="POST" action="{{ route('scholarship-batches.store') }}">@csrf<div class="records-form-grid">
                <x-record-field name="program_id" label="Scholarship program" type="select" required><option value="">Select program</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(old('program_id', $filters['program_id'] ?? '') == $program->id)>{{ $program->code }}</option>@endforeach</x-record-field>
                <x-record-field name="academic_period_id" label="Academic year / semester" type="select" required><option value="">Select period</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(old('academic_period_id', $filters['academic_period_id'] ?? '') == $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</x-record-field>
                <x-record-field name="batch_number" label="Roster batch name" :value="old('batch_number')" maxlength="60" required />
            </div><button class="records-button is-primary" type="submit">Create roster</button></form>
        </section>
    @endcan
</x-records-page>
