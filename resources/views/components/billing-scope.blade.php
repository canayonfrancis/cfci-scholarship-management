@props(['programs', 'periods', 'prefix', 'selectedProgram' => '', 'selectedPeriod' => ''])
<div class="record-field">
    <label for="{{ $prefix }}-program">Scholarship program</label>
    <select class="record-input" id="{{ $prefix }}-program" name="program_id" required>
        <option value="">Select program</option>
        @foreach($programs as $program)<option value="{{ $program->id }}" @selected((string) $selectedProgram === (string) $program->id)>{{ $program->code }}</option>@endforeach
    </select>
</div>
<div class="record-field">
    <label for="{{ $prefix }}-period">Academic year / semester</label>
    <select class="record-input" id="{{ $prefix }}-period" name="academic_period_id" required>
        <option value="">Select year and semester</option>
        @foreach($periods as $period)<option value="{{ $period->id }}" @selected((string) $selectedPeriod === (string) $period->id)>{{ $period->academic_year }} / Semester {{ $period->semester_code }}</option>@endforeach
    </select>
</div>
