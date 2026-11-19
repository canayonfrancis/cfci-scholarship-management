<x-records-page title="Semester scholarship rosters" description="Assign scholars before enrollment verification or billing. One roster can support multiple separate billing submissions.">
    <x-slot:actions>
        <a class="records-button" href="{{ route('scholars.index') }}">Scholars</a>
        @can('create', \App\Models\ScholarshipBatch::class)
            <button id="create-scholarship-batch-trigger" type="button" class="records-button is-primary" x-data x-on:click="$dispatch('open-record-form-dialog', { id: 'create-scholarship-batch', trigger: $el })" aria-haspopup="dialog" aria-controls="create-scholarship-batch"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>Create batch</button>
        @endcan
    </x-slot:actions>
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
        @php $creationErrors = $errors->getBag('createBatch'); @endphp
        <x-record-form-dialog id="create-scholarship-batch" title="Create scholarship batch" :action="route('scholarship-batches.store')" submit-label="Create batch" :show="$creationErrors->any()" :defaults="['program_id' => (string) ($filters['program_id'] ?? ''), 'academic_period_id' => (string) ($filters['academic_period_id'] ?? ''), 'batch_number' => '']">
            <x-slot:description>No approved rate, enrollment finding or billing record is needed. Add students through their scholar records after recording a pending program assignment.</x-slot:description>
            @if($creationErrors->any())
                <div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>The batch was not created. Please check the fields below.</strong><ul>@foreach($creationErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <p class="record-help">Fields marked * are required.</p>
            <div class="records-form-grid">
                <x-record-field id="create-batch-program" name="program_id" label="Scholarship program *" error-bag="createBatch" type="select" required><option value="">Select program</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($creationErrors->any() ? old('program_id') : ($filters['program_id'] ?? '')) == $program->id)>{{ $program->code }}</option>@endforeach</x-record-field>
                <x-record-field id="create-batch-period" name="academic_period_id" label="Academic year / semester *" error-bag="createBatch" type="select" required><option value="">Select period</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(($creationErrors->any() ? old('academic_period_id') : ($filters['academic_period_id'] ?? '')) == $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</x-record-field>
                <x-record-field id="create-batch-name" name="batch_number" label="Roster batch name *" error-bag="createBatch" :value="$creationErrors->any() ? old('batch_number') : ''" maxlength="60" required />
            </div>
        </x-record-form-dialog>
    @endcan
</x-records-page>
