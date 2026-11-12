<form method="GET" class="records-filters" action="{{ $filterAction }}">
    <x-record-field name="program_id" label="Scholarship program" type="select"><option value="">All programs</option>@foreach($scholarshipPrograms as $choice)<option value="{{ $choice->id }}" @selected(($filters['program_id'] ?? '') == $choice->id)>{{ $choice->code }}</option>@endforeach</x-record-field>
    <x-record-field name="academic_year" label="Academic year" type="select"><option value="">All academic years</option>@foreach($academicYears as $choice)<option value="{{ $choice }}" @selected(($filters['academic_year'] ?? '') === $choice)>{{ $choice }}</option>@endforeach</x-record-field>
    <x-record-field name="semester_code" label="Semester" type="select"><option value="">All semesters</option>@foreach($semesters as $choice)<option value="{{ $choice }}" @selected(($filters['semester_code'] ?? '') === $choice)>Semester {{ $choice }}</option>@endforeach</x-record-field>
    <button class="records-button is-primary" type="submit">Apply filters</button><a class="records-button" href="{{ $filterAction }}">Clear</a>
</form>
