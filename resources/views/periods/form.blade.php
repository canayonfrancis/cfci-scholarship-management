<x-records-page :title="$period->exists ? 'Edit academic semester' : 'Add academic semester'" description="Use the school’s academic-year label and semester code.">
    <x-slot:actions><a class="records-button" href="{{ $period->exists ? route('academic-periods.show', $period) : route('academic-periods.index') }}">Back to academic periods</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card"><form method="POST" action="{{ $period->exists ? route('academic-periods.update', $period) : route('academic-periods.store') }}">@csrf @if($period->exists) @method('PATCH') @endif
        @if($identityLocked)<p class="records-notice">This semester is linked to existing records. Its academic-year label and semester code are preserved.</p>@endif
        <div class="records-form-grid">
            <x-record-field name="academic_year" label="Academic year" :value="old('academic_year', $period->academic_year)" placeholder="2026-2027" maxlength="30" required :readonly="$identityLocked" />
            <x-record-field name="semester_code" label="Semester code" :value="old('semester_code', $period->semester_code)" placeholder="1, 2, or the school’s semester code" maxlength="30" required :readonly="$identityLocked" />
            <x-record-field name="starts_on" label="Start date (optional)" type="date" :value="old('starts_on', $period->starts_on?->format('Y-m-d'))" />
            <x-record-field name="ends_on" label="End date (optional)" type="date" :value="old('ends_on', $period->ends_on?->format('Y-m-d'))" />
            <x-record-field name="is_open" label="Semester status" type="select" required><option value="1" @selected((string) old('is_open', (int) $period->is_open) === '1')>Open</option><option value="0" @selected((string) old('is_open', (int) $period->is_open) === '0')>Closed</option></x-record-field>
        </div><div class="records-form-footer"><p class="record-help">Each academic year and semester code identifies one period. Earlier periods remain available in scholarship history.</p><x-primary-button class="records-submit">Save semester</x-primary-button></div>
    </form></section>
</x-records-page>
