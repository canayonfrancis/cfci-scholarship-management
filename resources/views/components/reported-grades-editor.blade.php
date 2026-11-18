@props(['record'])
@php
    $columns = array_diff_key(\App\Models\AcademicRecordGrade::COLUMNS, ['equivalent' => true]);
    $columns['average'] = 'Reported average';
    $empty = array_fill_keys(array_merge(['grade_id', 'subject_code', 'subject_name'], array_keys($columns), ['remarks']), '');
    $empty['reported_status'] = 'regular';
    $saved = $record->exists ? $record->grades->map(fn ($grade) => [...$grade->only(array_keys($empty)), 'grade_id' => $grade->id, 'reported_status' => $grade->reported_status ?? 'regular'])->all() : [];
    // A submitted empty list must not reload saved subjects after another field fails.
    $input = old('grades_present') ? old('grades', []) : old('grades', $saved);
    $rows = [];
    $rowErrors = [];
    foreach (is_array($input) ? $input : [] as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', array_replace($empty, array_intersect_key($row, $empty)));
        $normalized['reported_status'] = $normalized['reported_status'] === '' ? 'regular' : $normalized['reported_status'];
        $rows[] = $normalized;
        $messages = [];
        foreach ($errors->getMessages() as $key => $values) {
            if ($key === 'grades.'.$index) {
                $messages['_row'] = $values;
            } elseif (str_starts_with($key, 'grades.'.$index.'.')) {
                $messages[substr($key, strlen('grades.'.$index.'.'))] = $values;
            }
        }
        $rowErrors[] = $messages;
    }
@endphp
<section class="records-card academic-subject-editor" x-data="academicSubjectEditor(@js($rows), @js($empty), @js($rowErrors))" aria-labelledby="academic-subject-title">
    <div class="academic-subject-heading">
        <div>
            <h2 class="records-section-title" id="academic-subject-title">Subject percentage grades</h2>
            <p class="record-help">Add subjects from the Registrar record, then review their grades below. Changes are kept in this form until you save or verify the academic record.</p>
        </div>
        <button type="button" class="records-button is-primary" x-ref="addSubject" @click="openSubject(null, $event.currentTarget)" :disabled="rows.length >= 60" aria-haspopup="dialog" aria-controls="academic-subject-dialog">Add subject</button>
    </div>
    <p class="record-help" id="academic-subject-rules">Average = (Prelim + Midterm + Semi-Final + Final) / 4. Passing requires an unrounded average of at least 75%. Blank grades are not zero. Equivalent grades are not entered or used.</p>
    <p class="academic-subject-notice record-help" role="status" x-text="notice"></p>
    <x-input-error :messages="$errors->get('grades')" />
    <input type="hidden" name="grades_present" value="1">
    {{-- Only committed rows have input names. The modal draft is never submitted. --}}
    <div hidden>
        <template x-for="(row, index) in rows" :key="rowKeys[index]">
            <div>
                <template x-for="field in Object.keys(emptyRow)" :key="field">
                    <input type="hidden" :name="'grades['+index+']['+field+']'" :value="row[field]">
                </template>
            </div>
        </template>
    </div>
    <div class="records-table-wrap academic-subject-table-wrap" role="region" aria-label="Pending subject grades; scroll horizontally for all columns" tabindex="0">
        {{-- This reactive editor owns its rows/order; static table enhancement must not detach them. --}}
        <table class="records-table academic-subject-table" data-table-ready="true" aria-describedby="academic-subject-rules">
            <caption class="sr-only">Pending subject percentage grades. Use Edit to change a subject or Remove to request removal.</caption>
            <thead><tr><th scope="col">Subject</th>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Computed average</th><th scope="col">Subject result</th><th scope="col">Actions</th></tr></thead>
            <tbody>
                <tr x-show="rows.length === 0"><td colspan="10"><div class="records-empty"><strong>No subjects added yet</strong><p>Choose Add subject to enter the first subject from the Registrar record.</p></div></td></tr>
                <template x-for="(row, index) in rows" :key="rowKeys[index]">
                    <tr>
                        <th scope="row" class="academic-subject-identity">
                            <strong x-text="row.subject_name || 'Subject name missing'"></strong>
                            <span class="records-muted" x-text="row.subject_code || 'No subject code recorded'"></span>
                            <span class="records-muted" x-text="'Subject '+(index+1)"></span>
                            <template x-for="(message, messageIndex) in errorsFor(index)" :key="messageIndex"><p class="academic-field-error" x-text="message"></p></template>
                        </th>
                        @foreach($columns as $key => $label)<td class="academic-grade-value" x-text="row.{{ $key }} === '' ? '—' : row.{{ $key }}"></td>@endforeach
                        <td class="academic-grade-value"><strong x-text="subject(row).averageDisplay"></strong><span class="records-muted" x-show="subject(row).average !== null" x-text="'Unrounded: '+unroundedAverage(row)"></span></td>
                        <td class="academic-subject-result">
                            <span class="records-badge" :class="resultClass(row)" x-text="subject(row).result"></span>
                            <span class="records-muted" x-show="subject(row).numericResult && subject(row).result !== subject(row).numericResult" x-text="'Numeric result: '+subject(row).numericResult"></span>
                            <template x-for="reason in subject(row).reasons" :key="reason"><span class="records-muted" x-text="reason"></span></template>
                        </td>
                        <td><div class="academic-subject-actions">
                            <button type="button" class="records-button" @click="openSubject(index, $event.currentTarget)" :aria-label="'Edit subject '+(index+1)+': '+row.subject_name" aria-haspopup="dialog" aria-controls="academic-subject-dialog">Edit</button>
                            <button type="button" class="records-button academic-remove-button" @click="openConfirmation('remove', index, $event.currentTarget)" :aria-label="'Remove subject '+(index+1)+': '+row.subject_name" aria-haspopup="dialog" aria-controls="academic-subject-confirmation">Remove</button>
                        </div></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <p class="record-help" x-show="rows.length >= 60" x-cloak>The 60-subject form limit has been reached. You can still edit or remove subjects.</p>
    <div class="academic-subject-summary">
        <h3 class="records-section-title">Academic summary</h3>
        <div class="academic-summary-layout">
            <dl class="academic-summary-grid" aria-live="polite" aria-atomic="true">
                @foreach(['subjects' => 'Total subjects', 'passed' => 'Passed subjects', 'failed' => 'Failed subjects', 'incomplete' => 'Incomplete subjects', 'dropped' => 'Dropped subjects', 'missing' => 'Missing grades', 'reconciliation' => 'Reconciliation needed', 'units' => 'Total recorded units', 'included' => 'Included subjects', 'includedUnits' => 'Included units'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd x-text="summary.{{ $key }}"></dd></div>
                @endforeach
            </dl>
            <div class="academic-gwa-preview" role="status" aria-atomic="true"><span class="records-badge is-amber">Provisional</span><h4>Provisional percentage GWA</h4><p x-text="summary.gwa"></p><span>This is not an officially certified Registrar GWA.</span></div>
        </div>
        <p class="record-help">The provisional preview uses sum of complete subject averages × units / included units. Failed numeric grades are included. INC, dropped, explicitly unverified, discrepant/missing grades and nonpositive/missing units are excluded; each affected subject shows why.</p>
        <p class="record-help">Passed/failed counts describe numeric results and can overlap review flags. Display rounding does not turn a failure into a pass. These are unverified form previews, not scholarship decisions. The official GWA formula and rounding policy still require school approval.</p>
    </div>
    <p class="record-help">Up to 60 subject rows per save is a technical form limit, not an academic course-load rule.</p>
    <x-academic-subject-modal />
    <noscript><p class="records-alert">Enable JavaScript to manage subject grades. The backend remains authoritative; certificates can still be attached.</p></noscript>
</section>
