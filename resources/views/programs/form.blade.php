<x-records-page :title="$program->exists ? 'Edit scholarship program' : 'Add scholarship program'" description="Reference amounts and planning durations remain separate from an individual scholarship decision.">
    <x-slot:actions><a class="records-button" href="{{ $program->exists ? route('scholarship-programs.show', $program) : route('scholarship-programs.index') }}">Back to programs</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card"><form method="POST" action="{{ $program->exists ? route('scholarship-programs.update', $program) : route('scholarship-programs.store') }}">@csrf @if($program->exists) @method('PATCH') @endif
        <div class="records-form-grid">
            @if($program->exists)<x-record-field name="code" label="Program code" :value="$program->code" readonly help="Program codes retain their identity in historical records." />@else<x-record-field name="code" label="Program code" :value="old('code')" maxlength="30" required help="Use a unique code, such as TES, TDP or COSCHO. Codes remain stable in historical records." />@endif
            <x-record-field name="name" label="Program name" :value="old('name', $program->name)" maxlength="255" required />
            <x-record-field name="school_stated_semester_amount" label="School-stated semester amount (PHP)" type="number" :value="old('school_stated_semester_amount', $program->school_stated_semester_amount)" min="0" max="9999999999.99" step="0.01" help="Leave blank if unconfirmed. This reference amount does not authorize a payout." />
            <x-record-field name="planning_duration_years" label="Planning duration in years (optional)" type="number" :value="old('planning_duration_years', $program->planning_duration_years)" min="1" max="65535" step="1" help="Planning information only; this is not an automatic eligibility cutoff." />
            <x-record-field name="is_active" label="Availability for new active assignments" type="select" required><option value="1" @selected((string) old('is_active', (int) $program->is_active) === '1')>Available</option><option value="0" @selected((string) old('is_active', (int) $program->is_active) === '0')>Unavailable</option></x-record-field>
        </div>
        <x-record-field name="source_notes" label="School source / reference notes" type="textarea" :value="old('source_notes', $program->source_notes)" maxlength="5000" help="Include the school source or reason when changing a reference amount." />
        <div class="records-form-footer"><p class="record-help">Existing assignments and semester records retain their history when reference information changes.</p><x-primary-button class="records-submit">Save program</x-primary-button></div>
    </form></section>
</x-records-page>
