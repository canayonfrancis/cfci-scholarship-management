<div class="roster-selection" x-data="rosterScholarSelection({ selected: @js($selectedIds), startsOn: @js($selectionDate), details: @js($selectionDetails) })"
    @open-record-form-dialog.window="opened($event)" @close.capture="closed($event)"
    @submit.capture="guardSubmit($event)" @click.capture="tableNavigation($event)" @change.capture="tableNavigation($event)" @click="pageLink($event)">
    {{-- Form-associated controls avoid nesting a GET form inside the modal's POST form. --}}
    <form id="roster-candidate-search" method="GET" action="{{ route('rosters.show', $batch) }}" hidden>
        <input type="hidden" name="add" value="1">
        <input type="hidden" name="view" value="{{ $filters['view'] ?? 'all' }}">
        <input type="hidden" name="tables[roster_candidates][size]" value="{{ $candidates->perPage() }}">
        <input type="hidden" name="starts_on" :value="startsOn">
        <template x-for="id in selected" :key="id"><input type="hidden" name="selected[]" :value="id"></template>
    </form>
    <x-record-form-dialog id="add-roster-scholars" title="Add scholars to roster" :action="route('rosters.scholars.store', ['batch' => $batch] + request()->query())" submit-label="Confirm selected scholars" :show="$selectionOpen" :has-errors="$errors->getBag('rosterSelection')->any()" :dismiss-query="['add']" busy-label="Saving…">
        <x-slot:description>{{ $batch->batch_number }} · {{ $batch->program->code }} · {{ $batch->academicPeriod->academic_year }} / {{ $batch->academicPeriod->semester_code }}. Review existing registered scholars. This does not verify enrollment, activate an award or approve billing.</x-slot:description>
        @if($errors->getBag('rosterSelection')->any())<div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>No selected assignment or membership was saved.</strong><ul>@foreach($errors->getBag('rosterSelection')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <p class="records-alert" role="alert" x-show="selectionError" x-text="selectionError" x-cloak></p>
        <template x-for="id in selected" :key="id"><input type="hidden" name="student_ids[]" :value="id"></template>
        <div x-show="!reviewing">
            <div class="records-filters">
                <x-record-field id="roster-scholar-search" name="q" label="Student ID or name" :value="$candidateFilters['q'] ?? ''" maxlength="100" form="roster-candidate-search" />
                <x-record-field id="roster-course-filter" name="course_id" label="Course / program" type="select" form="roster-candidate-search"><option value="">All courses / programs</option>@foreach($courses as $course)<option value="{{ $course->id }}" @selected(($candidateFilters['course_id'] ?? '') == $course->id)>{{ $course->name }}</option>@endforeach</x-record-field>
                <button type="submit" form="roster-candidate-search" class="records-button">Search / filter</button>
            </div>
            <div class="records-actions"><button type="button" class="records-button" @click="selectDisplayed()">Select all displayed eligible scholars</button><button type="button" class="records-button" @click="selected = []">Clear selection</button><span role="status"><strong x-text="selected.length"></strong> selected · maximum 200 per submission</span></div>
            <div class="records-table-wrap"><x-table-state :paginator="$candidates" /><table class="records-table"><caption class="sr-only">Registered scholar selection and assignment conditions</caption><thead><tr><th data-table-no-sort>Select</th><th>Student ID</th><th>Full name</th><th>Course / program</th><th>Year level</th><th>Assignment / membership conditions</th><th>Actions</th></tr></thead><tbody>
                @forelse($candidates as $candidate)@php($student = $candidate['student'])<tr>
                    <td><input type="checkbox" value="{{ $student->id }}" x-model="selected" data-roster-selectable @disabled(!$candidate['selection']['eligible']) :disabled="{{ $candidate['selection']['eligible'] ? 'false' : 'true' }} || (selected.length >= 200 && !selected.includes('{{ $student->id }}'))" aria-label="Select {{ $student->student_number }} {{ $student->full_name }}"></td>
                    <td>{{ $student->student_number }}</td><td>{{ $student->full_name }}</td><td>{{ $student->academicProgram?->name ?? 'Not provided' }}</td><td>{{ $student->year_level ? (\App\Services\Records\ScholarProfile::yearLevels()[$student->year_level] ?? 'Year '.$student->year_level) : 'Not provided' }}</td><td>{{ $candidate['selection']['reason'] }}</td><td>@can('view', $student)<a class="records-link" href="{{ route('scholars.show', $student) }}" target="_blank" rel="noopener">View</a>@endcan</td>
                </tr>@empty<tr><td colspan="7">No registered scholars match these filters.</td></tr>@endforelse
            </tbody></table></div><div class="records-pagination">{{ $candidates->links() }}</div>
            <div class="record-field"><label for="roster-new-start">Scholarship starting date for new pending assignments <span x-show="needsNew">*</span></label><input id="roster-new-start" class="record-input" type="date" name="starts_on" x-model="startsOn" :required="needsNew"><p class="record-help">Used only for new assignments, whose starting academic period is this roster's period. Existing assignments retain their original period and date. If actual starting dates differ, submit those groups separately.</p></div>
            <details :open="selected.length > 0"><summary>Selected scholars (<span x-text="selected.length"></span>), including selections on other pages</summary><ul class="records-timeline"><template x-for="row in selectedRows" :key="row.id"><li><strong x-text="row.label"></strong><p x-text="row.reason"></p><button type="button" class="records-button" @click="selected = selected.filter(id => id !== row.id)">Remove from selection</button></li></template></ul></details>
        </div>
        <div x-show="reviewing" x-cloak data-roster-review tabindex="-1">
            <h3>Review selected scholars</h3><p><strong x-text="selected.length"></strong> memberships will be added. New awards remain pending. If any selected record fails validation, the entire submission is rolled back.</p>
            <p x-show="needsNew">Starting date for new pending assignments: <strong x-text="startsOn"></strong>.</p>
            <ul class="records-timeline"><template x-for="row in selectedRows" :key="row.id"><li><strong x-text="row.label"></strong><p x-text="row.reason"></p></li></template></ul>
        </div>
        <x-slot:footer>
            <button type="button" class="records-button" @click="cancel()" :disabled="submitting">Cancel</button>
            <button type="button" class="records-button" x-show="reviewing" x-cloak @click="reviewing = false" :disabled="submitting">Back to selection</button>
            <button type="button" class="records-button is-primary" x-show="!reviewing" @click="reviewSelection()" :disabled="submitting || selected.length === 0">Review selected scholars</button>
            <button type="submit" class="records-button is-primary" x-show="reviewing" x-cloak :disabled="submitting"><span x-show="!submitting">Confirm selected scholars</span><span x-show="submitting" x-cloak>Saving…</span></button>
        </x-slot:footer>
    </x-record-form-dialog>
</div>
