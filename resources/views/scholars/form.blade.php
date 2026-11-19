@php
    use App\Services\Records\ScholarProfile;
    $choice = function ($name) use ($scholar) {
        $value = old($name, $scholar->getAttribute($name));
        return is_bool($value) ? (string) (int) $value : (is_scalar($value) ? (string) $value : '');
    };
@endphp
<x-records-page title="Edit scholar" description="Maintain the student profile while retaining academic and scholarship history.">
    <x-slot:actions><a class="records-button" href="{{ route('scholars.show', $scholar) }}">Back to scholar</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card">
        <p class="record-help scholar-form-intro">Student ID, given name, last name and record status are required when saving changes. Fields marked * are needed for a complete official profile; you may save while they are missing. Unmarked fields are optional. Profile completeness does not determine scholarship eligibility.</p>
        @if($scholar->exists)<p class="record-help"><span class="records-badge {{ $scholar->hasCompleteProfile() ? 'is-green' : 'is-amber' }}">{{ $scholar->hasCompleteProfile() ? 'Complete' : 'Incomplete' }}</span> Current saved profile</p>@endif
        <form action="{{ route('scholars.update', $scholar) }}" method="POST">
            @csrf @method('PATCH')
            <h2 class="records-section-title scholar-section-title">Student Identification</h2>
            <div class="records-form-grid">
                <x-record-field name="student_number" label="Student ID (required)" :value="old('student_number', $scholar->student_number)" required maxlength="50" autocomplete="off" />
                <x-record-field name="lrn" label="Learner Reference Number (LRN) *" :value="$scholar->lrn" maxlength="12" inputmode="numeric" autocomplete="off" help="Exactly 12 digits, or 0 if unavailable. Re-enter protected IDs after a validation error; existing saved values are retained." />
                <x-record-field name="philsys_id" label="PhilSys ID / National ID (optional)" :value="$scholar->philsys_id" maxlength="19" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off" />
                <x-record-field name="four_ps_id" label="4Ps ID (optional)" :value="$scholar->four_ps_id" maxlength="100" autocomplete="off" help="Enter as printed, including leading zeros." />
            </div>
            <h2 class="records-section-title">Personal Information</h2>
            <div class="records-form-grid">
                <x-record-field name="last_name" label="Last name (required)" :value="old('last_name', $scholar->last_name)" required maxlength="255" autocomplete="family-name" />
                <x-record-field name="first_name" label="Given name / first name (required)" :value="old('first_name', $scholar->first_name)" required maxlength="255" autocomplete="given-name" />
                <x-record-field name="suffix" label="Extension name / suffix (optional)" :value="old('suffix', $scholar->suffix)" maxlength="30" autocomplete="honorific-suffix" />
                <x-record-field name="middle_name" label="Middle name (optional)" :value="old('middle_name', $scholar->middle_name)" maxlength="255" autocomplete="additional-name" />
                <x-record-field name="sex" label="Sex *" type="select"><option value="">Not provided</option>@foreach(ScholarProfile::SEX as $value => $label)<option value="{{ $value }}" @selected($choice('sex') === (string) $value)>{{ $label }}</option>@endforeach</x-record-field>
                <x-record-field name="birthdate" label="Birthdate *" type="date" :value="old('birthdate', $scholar->birthdate?->toDateString())" :max="now('Asia/Manila')->toDateString()" />
                <x-record-field name="record_status" label="Record status (required)" type="select" required><option value="active" @selected(old('record_status', $scholar->record_status) === 'active')>Active</option><option value="archived" @selected(old('record_status', $scholar->record_status) === 'archived')>Archived</option></x-record-field>
            </div>
            <h2 class="records-section-title">Academic Information</h2>
            <p class="record-help">Current profile information only. Saving does not create or verify a Registrar academic record. Previous profile selections remain in activity history; certified semester records are maintained separately.</p>
            <div class="records-form-grid">
                <x-record-field name="academic_program_id" label="Complete academic program name *" type="select"><option value="">Not provided</option>@foreach($academicPrograms as $program)<option value="{{ $program->id }}" @selected($choice('academic_program_id') === (string) $program->id)>{{ $program->name }}{{ $program->is_active ? '' : ' (inactive; retained)' }}</option>@endforeach</x-record-field>
                <x-record-field name="year_level" label="Year level *" type="select"><option value="">Not provided</option>@foreach(ScholarProfile::yearLevels() as $value => $label)<option value="{{ $value }}" @selected($choice('year_level') === (string) $value)>{{ $label }}</option>@endforeach</x-record-field>
                <x-record-field name="academic_period_id" label="Academic year and semester *" type="select"><option value="">Not provided</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected($choice('academic_period_id') === (string) $period->id)>{{ $period->academic_year }} · Semester {{ $period->semester_code }}</option>@endforeach</x-record-field>
            </div>
            @can('create', \App\Models\AcademicProgram::class)<p class="record-help"><a class="records-link" href="{{ route('academic-programs.index') }}" target="_blank" rel="noopener">Manage registered academic programs</a> in a separate tab; refresh this form before entering details to load new programs.</p>@endcan
            <h2 class="records-section-title scholar-section-title">Family Background</h2>
            <div class="records-form-grid">
                @foreach(['father_last_name' => "Father’s last name (optional)", 'father_first_name' => "Father’s given name (optional)", 'father_middle_name' => "Father’s middle name (optional)", 'mother_maiden_last_name' => "Mother’s maiden last name *", 'mother_maiden_first_name' => "Mother’s maiden given name *", 'mother_maiden_middle_name' => "Mother’s maiden middle name (optional)"] as $name => $label)
                    <x-record-field :name="$name" :label="$label" :value="old($name, $scholar->getAttribute($name))" maxlength="255" />
                @endforeach
            </div>
            <h2 class="records-section-title">Permanent Address</h2>
            <div class="records-form-grid">
                @foreach(['street_barangay' => 'Street & barangay *', 'city_municipality' => 'City / municipality *', 'province' => 'Province *', 'region' => 'Region *', 'zip_code' => 'ZIP code *'] as $name => $label)
                    <x-record-field :name="$name" :label="$label" :value="old($name, $scholar->getAttribute($name))" :maxlength="$name === 'zip_code' ? 20 : 255" />
                @endforeach
            </div>
            <h2 class="records-section-title">Contact Information</h2>
            @can('manageBankAccount', $scholar)
                <div class="record-field" x-data="{ showAccount: false }">
                    <p class="record-help">Saved LANDBANK account: {{ $scholar->maskedLandbankAccountNumber() ?? 'Not provided' }}</p>
                    <x-record-field name="landbank_account_number" label="LANDBANK Savings Account Number (optional)" type="password" x-bind:type="showAccount ? 'text' : 'password'" maxlength="100" inputmode="numeric" autocomplete="new-password" help="Enter digits only, including leading zeros. Leave blank to keep the saved number. Re-enter after a validation error; bank numbers are not restored from session input." />
                    <button type="button" class="records-button" @click="showAccount = !showAccount" :aria-pressed="showAccount" aria-controls="landbank_account_number" x-text="showAccount ? 'Hide new account number' : 'Show new account number'">Show new account number</button>
                    <label for="clear_landbank_account_number"><input id="clear_landbank_account_number" name="clear_landbank_account_number" type="checkbox" value="1"> Remove the saved LANDBANK account number</label>
                    <x-input-error :messages="$errors->get('clear_landbank_account_number')" />
                </div>
            @endcan
            <div class="records-form-grid">
                <x-record-field name="phone" label="Contact number *" type="tel" :value="old('phone', $scholar->phone)" maxlength="40" autocomplete="tel" help="Mobile: 9171234567, 09171234567 or +639171234567; landline: 8 digits. Recognized formats are normalized. Unchanged legacy numbers are retained for review." />
                <x-record-field name="email" label="Email address *" type="email" :value="old('email', $scholar->email)" maxlength="255" autocomplete="email" help="Contact email only. Editing this field does not change the linked login account." />
            </div>
            <h2 class="records-section-title">Additional Student Information</h2>
            <div class="records-form-grid">
                <x-record-field name="disability_type" label="Disability type (optional)" type="select"><option value="">Not provided</option>@foreach(ScholarProfile::DISABILITIES as $value => $label)<option value="{{ $value }}" @selected($choice('disability_type') === $value)>{{ $label }}</option>@endforeach</x-record-field>
                @foreach(['solo_parent_dependent' => 'Dependent / child of solo parent *', 'first_generation_student' => 'First-generation college student (optional)'] as $name => $label)
                    <x-record-field :name="$name" :label="$label" type="select" :help="$name === 'first_generation_student' ? 'Parents or legal guardians have not attended college or completed a college degree.' : null"><option value="">Not provided</option><option value="0" @selected($choice($name) === '0')>No</option><option value="1" @selected($choice($name) === '1')>Yes</option></x-record-field>
                @endforeach
                <x-record-field name="indigenous_people_group" label="Indigenous People group (optional)" :value="old('indigenous_people_group', $scholar->indigenous_people_group)" maxlength="255" help="Record the specific group as provided by the student. Leave blank when unknown." />
            </div>
            <h2 class="records-section-title">Scholarship Information</h2>
            <p class="record-help">Use the existing scholarship workflow for assignments, semester validations and verified approvals. Saving this profile grants no scholarship eligibility or financial approval.</p>
            @if($scholar->exists)<p class="record-help"><a class="records-link" href="{{ route('scholars.show', $scholar) }}">Review scholarship and academic history</a></p>@endif
            <div class="records-form-footer"><p class="record-help">Close any active scholarship before archiving a scholar. Existing history is retained.</p><x-primary-button class="records-submit">Save changes</x-primary-button></div>
        </form>
    </section>
</x-records-page>
