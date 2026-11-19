<x-records-page :title="$rosterMode ? 'Scholarship rosters' : 'Scholarship batches'" :description="$rosterMode ? 'Open an existing batch to review and manage its scholar membership. Membership is separate from enrollment, eligibility and billing.' : 'Create scholarship batches using the existing generated name, program and academic period. Manage scholar membership under Roster.'">
    <x-slot:actions>
        <a class="records-button" href="{{ route('scholars.index') }}">Scholars</a>
        <a class="records-button" href="{{ route($rosterMode ? 'scholarship-batches.index' : 'rosters.index') }}">{{ $rosterMode ? 'Manage batches' : 'Manage roster membership' }}</a>
        @unless($rosterMode)
        @can('create', \App\Models\ScholarshipBatch::class)
            <button id="create-scholarship-batch-trigger" type="button" class="records-button is-primary" x-data x-on:click="$dispatch('open-record-form-dialog', { id: 'create-scholarship-batch', trigger: $el })" aria-haspopup="dialog" aria-controls="create-scholarship-batch"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>Create batch</button>
        @endcan
        @endunless
    </x-slot:actions>
    <section class="dashboard-card records-card">
        <form class="records-filters" method="GET">
            <x-record-field name="program_id" label="Scholarship program" type="select"><option value="">All programs</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($filters['program_id'] ?? '') == $program->id)>{{ $program->code }}</option>@endforeach</x-record-field>
            <x-record-field name="academic_period_id" label="Academic year / semester" type="select"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(($filters['academic_period_id'] ?? '') == $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</x-record-field>
            <button class="records-button" type="submit">Filter</button>
        </form>
        <div class="records-table-wrap"><x-table-state :paginator="$batches" /><table class="records-table"><thead><tr><th>Roster batch</th><th>Program</th><th>Academic year / semester</th><th>Members</th></tr></thead><tbody>
            @forelse($batches as $batch)<tr><td><a class="records-link" href="{{ route($rosterMode ? 'rosters.show' : 'scholarship-batches.show', $batch) }}">{{ $batch->batch_number }}</a></td><td>{{ $batch->program->code }}</td><td>{{ $batch->academicPeriod->academic_year }} / {{ $batch->academicPeriod->semester_code }}</td><td>{{ $batch->memberships_count }}</td></tr>@empty<tr><td colspan="4">No scholarship batches match this scope.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $batches->links() }}</div>
    </section>
    @unless($rosterMode)
    @can('create', \App\Models\ScholarshipBatch::class)
        @php
            $creationErrors = $errors->getBag('createBatch');
            $selectedProgram = $creationErrors->any() ? old('program_id') : ($filters['program_id'] ?? '');
            $selectedPeriod = $creationErrors->any() ? old('academic_period_id') : ($filters['academic_period_id'] ?? '');
            $enteredNumber = $creationErrors->any() ? old('batch_number') : '';
        @endphp
        <x-record-form-dialog id="create-scholarship-batch" title="Create scholarship batch" :action="route('scholarship-batches.store')" submit-label="Create batch" :show="$creationErrors->any()" :defaults="['program_id' => (string) ($filters['program_id'] ?? ''), 'academic_period_id' => (string) ($filters['academic_period_id'] ?? ''), 'batch_number' => '']">
            <x-slot:description>No approved rate, enrollment finding or billing record is needed. Add students through their scholar records after recording a pending program assignment.</x-slot:description>
            @if($creationErrors->any())
                <div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>The batch was not created. Please check the fields below.</strong><ul>@foreach($creationErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <p class="record-help">Fields marked * are required.</p>
            <div class="records-form-grid" x-data="{
                program: @js(is_scalar($selectedProgram) ? (string) $selectedProgram : ''),
                period: @js(is_scalar($selectedPeriod) ? (string) $selectedPeriod : ''),
                number: @js(is_scalar($enteredNumber) ? (string) $enteredNumber : ''),
                programs: @js($programs->pluck('code', 'id')),
                periods: @js($periods->mapWithKeys(fn ($period) => [$period->id => $period->semester_code.'-'.$period->academic_year])),
                get name() { return this.programs[this.program] && this.periods[this.period] && this.number ? this.programs[this.program] + '-' + this.number + '-' + this.periods[this.period] : ''; }
            }">
                <x-record-field id="create-batch-program" name="program_id" label="Scholarship program *" error-bag="createBatch" type="select" x-model="program" required><option value="">Select program</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($creationErrors->any() ? old('program_id') : ($filters['program_id'] ?? '')) == $program->id)>{{ $program->code }}</option>@endforeach</x-record-field>
                <x-record-field id="create-batch-period" name="academic_period_id" label="Academic year / semester *" error-bag="createBatch" type="select" x-model="period" required><option value="">Select period</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(($creationErrors->any() ? old('academic_period_id') : ($filters['academic_period_id'] ?? '')) == $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</x-record-field>
                <x-record-field id="create-batch-number" name="batch_number" label="Batch number *" error-bag="createBatch" :value="$creationErrors->any() ? old('batch_number') : ''" x-model="number" inputmode="decimal" pattern="[0-9]+([.][0-9]+)?" maxlength="30" required help="Enter the institution's batch number, for example 1.0. Decimal notation is retained; numbers are not assigned automatically." />
                <div class="record-field"><label for="create-batch-name">Roster Batch Name (generated)</label><input id="create-batch-name" class="record-input" type="text" readonly x-bind:value="name" placeholder="Select program, period and batch number"><p class="record-help">Program code–Batch number–Semester–Academic year. The final name is generated by the server.</p></div>
            </div>
        </x-record-form-dialog>
    @endcan
    @endunless
</x-records-page>
