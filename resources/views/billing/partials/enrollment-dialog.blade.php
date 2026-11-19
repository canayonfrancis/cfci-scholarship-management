@php
    $enrollmentValue = fn ($field) => $enrollmentErrors->any() ? old($field) : null;
@endphp
<x-record-form-dialog id="verify-enrollment" title="Verify roster enrollment" :action="route('billing-batches.enrollment', $batch)" submit-label="Save enrollment findings" busy-label="Saving..."
    enctype="multipart/form-data" :show="$enrollmentErrors->any()" :has-errors="$enrollmentErrors->any()" trigger-id="verify-enrollment-trigger"
    :defaults="['enrollment_status' => '', 'source_reference' => '', 'verification_notes' => '', 'correction_reason' => '']">
    <x-slot:description>Check the selected scholars against actual Registrar records for this roster's semester. One finding and reference apply to the selected group. Use separate submissions for different findings or sources.</x-slot:description>
    @if($enrollmentErrors->any())<div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>Enrollment was not saved.</strong><ul>@foreach($enrollmentErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>Select any attachment again before resubmitting.</p></div>@endif
    <p class="record-help"><strong>{{ $batch->scholarshipBatch->batch_number }}</strong> / {{ $batch->program->code }} / {{ $batch->academicPeriod->academic_year }} / Semester {{ $batch->academicPeriod->semester_code }}.</p>
    <p class="record-help" role="status" aria-live="polite"><strong x-text="enrollmentSelected.length">0</strong> selected. This list follows the current roster search, filters and page (up to 200). Only scholars you choose are updated. No enrollment finding is selected automatically.</p>
    <fieldset class="billing-enrollment-members">
        <legend>Review and select checked scholars</legend>
        @forelse($members as $row)
            <div class="billing-enrollment-member">
                <label><input type="checkbox" name="enrollment_student_ids[]" value="{{ $row['member']->student_id }}" x-model="enrollmentSelected"> <strong>{{ $row['member']->student->student_number }} / {{ $row['member']->student->full_name }}</strong></label>
                <input type="hidden" name="enrollment_records[{{ $row['member']->student_id }}][id]" value="{{ $row['record']?->id ?? 0 }}">
                <input type="hidden" name="enrollment_records[{{ $row['member']->student_id }}][version]" value="{{ $row['record']?->lock_version ?? 0 }}">
                <p class="record-help">Current finding: {{ $row['enrollment'] }}. {{ $row['record'] ? 'Latest revision '.$row['record']->revision : 'No academic source recorded' }}. @if($row['record']?->verifiedBy)Recorded verification by {{ $row['record']->verifiedBy->name }}.@endif</p>
                @if($row['academic_history']->isNotEmpty())<details><summary>Enrollment source and revision history ({{ $row['academic_history']->count() }})</summary>
                    @foreach($row['academic_history'] as $revision)<article class="billing-enrollment-history"><x-retained-academic-source :record="$revision" /><p class="record-help">Enrollment: {{ \Illuminate\Support\Str::headline($revision->enrollment_status ?? 'unverified') }}.</p>@if($revision->verification_notes)<p class="record-help records-preserve-lines">{{ $revision->verification_notes }}</p>@endif @if($revision->correction_reason)<p class="record-help">Correction reason: {{ $revision->correction_reason }}</p>@endif</article>@endforeach
                </details>@endif
            </div>
        @empty<p class="record-help">No roster members are displayed. Close the modal and review the roster search or page.</p>@endforelse
    </fieldset>
    <x-record-field name="enrollment_status" id="roster-enrollment-status" label="Enrollment finding for this semester" type="select" error-bag="enrollment" x-model="enrollmentOutcome" required>
        <option value="">Select a finding after reviewing actual records</option>
        <option value="enrolled">Enrolled</option><option value="not_enrolled">Not enrolled</option><option value="unverified">Unverified</option>
    </x-record-field>
    <x-record-field name="source_reference" id="roster-enrollment-source" label="Registrar source / verification reference" :value="$enrollmentValue('source_reference')" maxlength="3000" required error-bag="enrollment" help="Identify the actual COR/COE, checked enrollment list or other Registrar source used for this group." />
    <x-record-field name="verification_notes" id="roster-enrollment-notes" label="Enrollment notes / reason (optional)" type="textarea" :value="$enrollmentValue('verification_notes')" maxlength="3000" error-bag="enrollment" help="Record the enrollment finding only. Not enrolled is a semester billing exclusion; it does not terminate the scholarship." />
    <div x-show="enrollmentNeedsCorrection" x-cloak><x-record-field name="correction_reason" id="roster-enrollment-correction" label="Reason for correcting the retained source" type="textarea" :value="$enrollmentValue('correction_reason')" maxlength="3000" error-bag="enrollment" x-bind:required="enrollmentNeedsCorrection" help="A new enrollment-only revision preserves the existing record and its evidence." /></div>
    <x-record-field name="certificate" id="roster-enrollment-certificate" label="Supporting enrollment record (optional)" type="file" accept=".pdf,.jpg,.jpeg,.png" error-bag="enrollment" help="PDF/JPG/PNG, maximum 2 MB. Attach only a source covering every selected scholar. It is retained privately with each selected record; existing certificates remain in their original revisions." />
    <label><input type="checkbox" name="confirm_enrollment_review" value="1" required> I reviewed each selected scholar and the source for this semester. The selected finding is accurate; this does not activate a scholarship or establish payability.</label>
    <x-slot:footer>
        <button class="records-button" type="button" @click="cancel()" :disabled="submitting">Cancel</button>
        <button class="records-button is-primary" type="submit" :disabled="submitting || !enrollmentSelected.length || !enrollmentOutcome"><span x-show="!submitting">Save enrollment findings</span><span x-show="submitting" x-cloak>Saving...</span></button>
    </x-slot:footer>
</x-record-form-dialog>
