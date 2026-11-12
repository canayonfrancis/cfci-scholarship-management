<x-records-page :title="$program->exists ? 'Edit scholarship program' : 'Add scholarship program'" description="Reference amounts and planning durations remain separate from an individual scholarship decision.">
    <x-slot:actions><a class="records-button" href="{{ $program->exists ? route('scholarship-programs.show', $program) : route('scholarship-programs.index') }}">Back to programs</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card"><form method="POST" action="{{ $program->exists ? route('scholarship-programs.update', $program) : route('scholarship-programs.store') }}" x-data="billingBatchFilter(@js(route('billing-batches.options')), {{ $batchOptions->count() }}, { programField: 'batch_program_id', placeholder: 'Select Billing Batch' })" @change="if (['batch_program_id', 'academic_period_id'].includes($event.target.name)) refresh()">@csrf @if($program->exists) @method('PATCH') @endif
        <div class="records-form-grid">
            @if($program->exists)<x-record-field name="code" label="Program code" :value="$program->code" readonly help="Program codes retain their identity in historical records." />@else<x-record-field name="code" label="Program code" :value="old('code')" maxlength="30" required help="Use a unique code, such as TES, TDP or COSCHO. Codes remain stable in historical records." />@endif
            <x-record-field name="name" label="Program name" :value="old('name', $program->name)" maxlength="255" required />
            <x-record-field name="school_stated_semester_amount" label="School-stated semester amount (PHP)" type="number" :value="old('school_stated_semester_amount', $program->school_stated_semester_amount)" min="0" max="9999999999.99" step="0.01" help="Leave blank if unconfirmed. This reference amount does not authorize a payout." />
            <x-record-field name="planning_duration_years" label="Scholarship Duration" type="number" :value="old('planning_duration_years', $program->planning_duration_years)" min="1" max="65535" step="1" help="This is not an automatic eligibility cutoff." />
            <x-record-field name="is_active" label="Availability for new active assignments" type="select" required><option value="1" @selected((string) old('is_active', (int) $program->is_active) === '1')>Active</option><option value="0" @selected((string) old('is_active', (int) $program->is_active) === '0')>Inactive</option></x-record-field>
            <x-record-field name="batch_program_id" label="Billing batch reference program" type="select" x-bind:required="!!selected" help="The existing program that owns the reference batch. This does not change this form's program code.">
                @unless($program->exists)<option value="">All reference programs</option>@endunless
                @foreach($batchPrograms as $batchProgram)<option value="{{ $batchProgram->id }}" @selected((string) ($batchContext['batch_program_id'] ?? '') === (string) $batchProgram->id)>{{ $batchProgram->code }}</option>@endforeach
            </x-record-field>
            <x-record-field name="academic_period_id" label="Batch academic year / semester" type="select" x-bind:required="!!selected" help="Filter existing batches by their actual academic period. Required when selecting a reference batch.">
                <option value="">All academic years / semesters</option>
                @foreach($batchPeriods as $batchPeriod)<option value="{{ $batchPeriod->id }}" @selected((string) ($batchContext['academic_period_id'] ?? '') === (string) $batchPeriod->id)>{{ $batchPeriod->academic_year }} / Semester {{ $batchPeriod->semester_code }}</option>@endforeach
            </x-record-field>
            <x-batch-number-field :options="$batchOptions" :value="$batchContext['billing_batch_id'] ?? ''" placeholder="Select Billing Batch" help="Optional reference only. This selection is not saved as a program relationship and never attaches, creates or moves a batch." />
        </div>
        <p class="record-help" role="status" aria-live="polite" x-cloak x-show="loading">Loading matching billing batches…</p>
        <p class="record-help" role="status" aria-live="polite" x-show="!loading && optionsCount === 0 && !loadError" @if($batchOptions->isNotEmpty()) x-cloak @endif>No matching billing batches exist for this reference program and academic period.</p>
        <p class="record-help" role="alert" x-cloak x-show="loadError" x-text="loadError"></p>
        <button class="records-button" type="button" x-cloak x-show="loadError" @click="refresh()">Retry loading batches</button>
        <x-record-field name="source_notes" label="School source / reference notes" type="textarea" :value="old('source_notes', $program->source_notes)" maxlength="5000" help="Include the school source or reason when changing a reference amount." />
        <div class="records-form-footer"><p class="record-help">Existing assignments and semester records retain their history when reference information changes. The Batch Number selection provides context only; existing batches keep their original scholarship program and semester.</p><x-primary-button class="records-submit" x-bind:disabled="loading || !!loadError">Save program</x-primary-button></div>
    </form></section>
</x-records-page>
