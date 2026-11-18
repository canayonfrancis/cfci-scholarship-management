@php
    $editing = $record->exists;
    $title = $correction ? 'Correct academic record' : ($editing ? 'Edit academic draft' : 'Add academic record');
    $action = $correction ? route('academic-verifications.correct', $record) : ($editing ? route('academic-verifications.update', $record) : route('academic-verifications.store'));
    $lockVersion = old('lock_version', $record->lock_version);
    $gradeStorageReady = \App\Models\ProgramAcademicRequirement::storageReady();
@endphp
<x-records-page :title="$title" description="Enter information from Registrar records. Standing is recorded manually; scholarship eligibility is reviewed separately.">
    <x-slot:actions><a class="records-button" href="{{ $editing ? route('academic-verifications.show', $record) : route('academic-verifications.index') }}">Back to records</a></x-slot:actions>
    @unless($gradeStorageReady)<div class="records-alert">The academic computation database upgrade is pending. The developer must apply the documented migration before saving this form. Existing academic history is retained.</div>@endunless
    @if($correction)<div class="records-alert" role="note">This creates revision {{ $record->revision + 1 }}. The original record and its certificates remain in history. Decisions using earlier revisions will require reassessment, including while the correction is a draft. Upload certificates supporting this revision separately.</div>@endif
    <section class="dashboard-card records-card">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="records-form">
            @csrf @if($editing && !$correction) @method('PATCH') @endif
            @if($editing)<input type="hidden" name="lock_version" value="{{ is_scalar($lockVersion) ? $lockVersion : $record->lock_version }}"><dl class="records-details"><div><dt>Scholar</dt><dd>{{ $record->student->full_name }} · {{ $record->student->student_number }}</dd></div><div><dt>Period</dt><dd>{{ $record->academicPeriod->academic_year }} · Semester {{ $record->academicPeriod->semester_code }}</dd></div></dl>@endif
            <div class="records-form-grid">
                @unless($editing)
                    <x-record-field name="student_id" label="Scholar" type="select" required><option value="">Select a scholar</option>@foreach($students as $student)<option value="{{ $student->id }}" @selected(old('student_id', $record->student_id) == $student->id)>{{ $student->student_number }} · {{ $student->full_name }}</option>@endforeach</x-record-field>
                    <x-record-field name="academic_period_id" label="Academic period" type="select" required><option value="">Select a semester</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(old('academic_period_id', $record->academic_period_id) == $period->id)>{{ $period->academic_year }} · Semester {{ $period->semester_code }}</option>@endforeach</x-record-field>
                @endunless
                <x-record-field name="course" label="Course / program of study" :value="old('course', $record->course)" maxlength="255" />
                <x-record-field name="year_level" label="Year level" :value="old('year_level', $record->year_level)" maxlength="30" />
                <x-record-field name="enrollment_status" label="Enrollment status" type="select" required>@foreach(['unverified' => 'Unverified', 'enrolled' => 'Enrolled', 'not_enrolled' => 'Not enrolled'] as $value => $label)<option value="{{ $value }}" @selected(old('enrollment_status', $record->enrollment_status) === $value)>{{ $label }}</option>@endforeach</x-record-field>
                <x-record-field name="academic_standing" label="Academic standing" type="select" required>@foreach(['unverified', 'passing', 'failing'] as $value)<option value="{{ $value }}" @selected(old('academic_standing', $record->academic_standing) === $value)>{{ ucfirst($value) }}</option>@endforeach</x-record-field>
                <x-record-field name="source_reference" label="Registrar source / reference" type="textarea" :value="old('source_reference', $record->source_reference)" maxlength="4000" help="Required to verify. Identify the school record or certificate used." />
                <x-record-field name="verification_notes" label="Verification notes" type="textarea" :value="old('verification_notes', $record->verification_notes)" maxlength="4000" />
                <x-record-field name="certified_percentage_gwa" label="Registrar-certified percentage GWA (if officially reported)" :value="old('certified_percentage_gwa', $record->certified_percentage_gwa)" maxlength="30" inputmode="decimal" help="Optional. Copy a certified 0–100 percentage exactly; never enter an equivalent grade or copy the provisional calculation as official." />
                <x-record-field name="certified_gwa_reference" label="Certified GWA source / certificate reference" type="textarea" :value="old('certified_gwa_reference', $record->certified_gwa_reference)" maxlength="4000" help="Required when verifying a record that contains an officially reported percentage GWA." />
                @if($correction)<x-record-field name="correction_reason" label="Reason for correction" type="textarea" :value="old('correction_reason')" required maxlength="4000" />@endif
                <div class="record-field"><x-input-label for="certificates" value="Supporting grade certificates" /><input class="record-input" id="certificates" name="certificates[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png"><p class="record-help">Private PDF, JPG, or PNG files. Up to 3 files per save, 2 MB each. Existing certificates are retained.</p><x-input-error :messages="$errors->get('certificates')" /></div>
            </div>
            <x-reported-grades-editor :record="$record" />
            <label><input type="checkbox" name="certified_gwa_confirmed" value="1"> If a certified percentage GWA is entered, I verified that value and its percentage scale against the stated Registrar source. Required for Verify record; a provisional system GWA is not a certification.</label>
            <p class="record-help">Verification records your signed-in Registrar account and the current date. Any field marked Unverified remains unverified even when other information in the record has been checked.</p>
            <div class="records-actions"><x-primary-button class="records-submit" name="action" value="draft" :disabled="!$gradeStorageReady">Save draft</x-primary-button><x-primary-button class="records-submit" name="action" value="verify" :disabled="!$gradeStorageReady">Verify record</x-primary-button></div>
        </form>
    </section>
</x-records-page>
