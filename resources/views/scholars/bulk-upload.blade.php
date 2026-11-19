<x-records-page title="Bulk Upload Scholars" description="Register new scholar profiles from one spreadsheet. Review every row before saving the batch.">
    <x-slot:actions>
        <a class="records-button" href="{{ route('scholars.index') }}">Back to scholars</a>
        <a class="records-button is-primary" href="{{ route('scholars.imports.template') }}">Download Template <x-dashboard-icon name="arrow-right" :size="16" /></a>
    </x-slot:actions>

    <section class="dashboard-card records-card records-form-card" aria-labelledby="upload-title">
        <h2 class="records-section-title" id="upload-title">{{ $preview ? 'Upload a corrected file' : 'Prepare your spreadsheet' }}</h2>
        <p>Download the template, enter new scholars on its first worksheet, and keep its eleven column headers in order. Middle Name, EXT and LANDBANK Savings Account Number are optional. Previous ten-column templates ending at Contact are also accepted.</p>
        <p class="record-help">Keep Student ID, Contact and LANDBANK Savings Account Number formatted as <strong>Text</strong> to retain leading zeros. Use an existing active course name. Import creates profile records only; it does not create login accounts, assign scholarships or approve eligibility.</p>
        <p class="record-help">Complete valid numeric Contact cells are accepted when their digits can be read exactly. Student ID and LANDBANK still require Text cells. If a value has lost digits, re-enter it from the original record; changing the format alone cannot restore them. Download a fresh template and keep its Text formatting.</p>
        <details class="scholar-import-instructions">
            <summary>Accepted values and file instructions</summary>
            <ul>
                <li><strong>Sex:</strong> Male or Female (case-insensitive), or 0 for Male and 1 for Female.</li>
                <li><strong>EXT:</strong> optional. JR, Jr and Jr. are saved as Jr.; SR, Sr and Sr. as Sr.; II, III and IV are saved in uppercase. Other legitimate suffixes may contain up to 30 characters.</li>
                <li><strong>Year Level:</strong> @foreach(\App\Services\Records\ScholarProfile::yearLevels() as $value => $label){{ $value }} ({{ $label }}){{ $loop->last ? '.' : '; ' }}@endforeach</li>
                <li><strong>Contact:</strong> a Philippine mobile number such as 09171234567, 9171234567 or +639171234567, or an eight-digit landline. Text retains original leading zeros. Complete numeric values such as 9171234567 or 81234567 are also accepted; fractional, incomplete or unsafe numeric values are rejected.</li>
                <li><strong>LANDBANK Savings Account Number:</strong> optional, after Contact. Digits only, including leading zeros; no exact account length is required (technical maximum: 100 characters). Account numbers are masked in preview.</li>
                <li><strong>Course:</strong> @if($courses->isEmpty())No active courses are registered. Ask an authorized administrator to register courses before uploading.@else{{ $courses->implode('; ') }}.@endif</li>
                <li>Accepted files: .xlsx or comma-separated UTF-8 .csv. Maximum {{ number_format(config('scholar-imports.max_upload_kb')) }} KB and {{ number_format(config('scholar-imports.max_rows')) }} records per file.</li>
                <li>Only the first worksheet is imported. Do not include totals, extra data columns, formulas, macros, external data connections or merged data cells. Formatting-only and blank rows are ignored.</li>
                <li>Enter email addresses normally; Excel's automatic simple mailto links on Email cells can remain. Only the cell text is imported. Other external links are unsupported.</li>
                <li>Student IDs must be new and unique. Existing records are never overwritten or merged. Contact email is not a login account and follows the existing shared-contact rules.</li>
            </ul>
        </details>
        @if($courses->isEmpty())<div class="records-alert" role="alert">No active courses are available. Register the intended courses in the existing Academic programs module before importing.</div>@endif
        <form method="POST" enctype="multipart/form-data" action="{{ route('scholars.imports.preview') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
            @csrf
            <div class="record-field">
                <x-input-label for="file" value="Scholar spreadsheet (required)" />
                <input class="record-input scholar-import-file" id="file" name="file" type="file" accept=".xlsx,.csv" required aria-invalid="{{ $errors->has('file') ? 'true' : 'false' }}" aria-describedby="file-help @error('file') file-error @enderror">
                <p class="record-help" id="file-help">Maximum {{ number_format(config('scholar-imports.max_upload_kb')) }} KB. Preview validates the file without saving scholar records. A new preview does not confirm any previous upload.</p>
                <x-input-error id="file-error" :messages="$errors->get('file')" />
            </div>
            <div class="records-actions mt-4">
                <button class="records-button is-primary" type="submit" :disabled="submitting" @disabled($courses->isEmpty())><span x-show="!submitting">Preview</span><span x-cloak x-show="submitting">Validating file…</span></button>
                <a class="records-button" href="{{ route('scholars.index') }}">{{ $preview ? 'Back to scholars' : 'Cancel' }}</a>
            </div>
        </form>
    </section>

    @if($preview)
        <section class="dashboard-card records-card" aria-labelledby="preview-title">
            <h2 class="records-section-title" id="preview-title">Scholar records preview</h2>
            <dl class="scholar-import-counts" aria-label="Upload validation summary">
                <div><dt>Total records</dt><dd>{{ number_format($preview['total']) }}</dd></div>
                <div><dt>Valid records</dt><dd>{{ number_format($preview['valid']) }}</dd></div>
                <div><dt>Invalid records</dt><dd>{{ number_format($preview['invalid']) }}</dd></div>
                <div><dt>Duplicate records</dt><dd>{{ number_format($preview['duplicates']) }}</dd></div>
            </dl>
            <p class="record-help">Duplicate rows are included in the invalid count. Row Number is the original worksheet row or CSV starting line. This preview expires at {{ $expiresAt }}.</p>
            @if($preview['invalid'] > 0)
                <div class="records-alert" role="alert"><strong>No records have been saved.</strong> Correct all invalid and duplicate rows in your original file, then upload it again. One invalid row blocks the entire batch.</div>
            @else
                <div class="records-notice" role="status">All rows are valid in this preview. Review the details below before selecting Confirm Import. The server checks the original file again when you confirm.</div>
            @endif
            <div class="records-table-wrap">
                <table class="records-table scholar-import-table">
                    <caption class="sr-only">Scholar upload preview with original row numbers and validation errors</caption>
                    <thead><tr><th scope="col">Row Number</th>@foreach(\App\Services\Records\ScholarSpreadsheet::HEADERS as $header)<th scope="col">{{ $header }}</th>@endforeach<th scope="col">Validation Status</th></tr></thead>
                    <tbody>
                        @foreach($preview['rows'] as $row)
                            <tr>
                                <td>{{ $row['number'] }}</td>
                                @foreach(array_pad($row['values'], count(\App\Services\Records\ScholarSpreadsheet::HEADERS), '') as $index => $value)<td>{{ $value === '' ? '—' : ($index === 10 ? \App\Services\Records\ScholarProfile::maskBankAccount($value) : $value) }}</td>@endforeach
                                <td class="scholar-import-validation">
                                    <span class="records-badge {{ $row['issues'] === [] ? 'is-green' : 'is-amber' }}">{{ $row['issues'] === [] ? 'Valid' : ($row['duplicate'] ? 'Duplicate / invalid' : 'Invalid') }}</span>
                                    @if($row['issues'] !== [])<ul>@foreach($row['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="scholar-import-confirmation">
                <form method="POST" action="{{ route('scholars.imports.confirm', ['import' => $importId]) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                    @csrf
                    <label class="scholar-import-consent"><input type="checkbox" name="confirm" value="1" required @disabled($preview['invalid'] > 0)> <span>I reviewed this preview and confirm that these are new scholar records.</span></label>
                    <button type="submit" class="records-button is-primary" :disabled="submitting || {{ $preview['invalid'] > 0 ? 'true' : 'false' }}" @disabled($preview['invalid'] > 0)><span x-show="!submitting">Confirm Import</span><span x-cloak x-show="submitting">Importing…</span></button>
                </form>
                <form method="POST" action="{{ route('scholars.imports.cancel', ['import' => $importId]) }}">@csrf @method('DELETE')<button type="submit" class="records-button">Cancel import</button></form>
            </div>
        </section>
    @endif
</x-records-page>
