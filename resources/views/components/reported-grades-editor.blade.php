@props(['record'])
@php
    $columns = \App\Models\AcademicRecordGrade::COLUMNS;
    $empty = array_fill_keys(array_merge(['subject_code', 'subject_name'], array_keys($columns), ['remarks']), '');
    $input = old('grades', $record->exists ? $record->grades->map(fn ($grade) => $grade->only(array_keys($empty)))->all() : []);
    $rows = collect(is_array($input) ? $input : [])->filter(fn ($row) => is_array($row))->map(fn ($row) => array_map(fn ($value) => is_scalar($value) ? (string) $value : '', array_replace($empty, array_intersect_key($row, $empty))))->values()->all();
@endphp
<section class="records-card" x-data="{ rows: @js($rows), emptyRow: @js($empty) }">
    <h2 class="records-section-title">Reported subject grades</h2>
    <p class="record-help">Copy the certificate values exactly. Prelim, Midterm, Semi-Final, Final, Average and Equivalent are separate reported values. No formula, conversion, passing threshold or automatic standing is applied. Leave unavailable values blank and copy any special marks into remarks.</p>
    <input type="hidden" name="grades_present" value="1">
    <template x-for="(row, index) in rows" :key="index">
        <fieldset class="records-card">
            <legend class="records-section-title" x-text="'Subject '+(index+1)"></legend>
            <div class="records-form-grid">
                <div class="record-field"><label :for="'grade_'+index+'_code'">Subject code (if recorded)</label><input class="record-input" :id="'grade_'+index+'_code'" :name="'grades['+index+'][subject_code]'" x-model="row.subject_code" maxlength="60"></div>
                <div class="record-field"><label :for="'grade_'+index+'_name'">Subject name</label><input class="record-input" :id="'grade_'+index+'_name'" :name="'grades['+index+'][subject_name]'" x-model="row.subject_name" required maxlength="255"></div>
                @foreach($columns as $key => $label)
                    <div class="record-field"><label :for="'grade_'+index+'_{{ $key }}'">{{ $label }}</label><input class="record-input" :id="'grade_'+index+'_{{ $key }}'" :name="'grades['+index+'][{{ $key }}]'" x-model="row.{{ $key }}" inputmode="decimal" maxlength="30" autocomplete="off"></div>
                @endforeach
                <div class="record-field"><label :for="'grade_'+index+'_remarks'">Remarks as recorded</label><textarea class="record-input" :id="'grade_'+index+'_remarks'" :name="'grades['+index+'][remarks]'" x-model="row.remarks" rows="2" maxlength="2000"></textarea></div>
            </div>
            <button type="button" class="records-button" @click="rows.splice(index, 1)">Remove subject row</button>
        </fieldset>
    </template>
    <button type="button" class="records-button" @click="rows.push({...emptyRow})" :disabled="rows.length >= 60">Add subject grade</button>
    <p class="record-help">Up to 60 subject rows per save to stay within the server's form-size limit. This is a technical limit, not an academic course-load rule.</p>
    <noscript><p class="records-alert">Enable JavaScript to enter subject rows. Original certificates can still be attached.</p></noscript>
</section>
