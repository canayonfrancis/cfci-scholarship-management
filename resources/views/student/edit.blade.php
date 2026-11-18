@php
    use App\Services\Records\ScholarProfile;
    $selectedSex = old('sex', $student->sex);
    $selectedSex = is_scalar($selectedSex) ? (string) $selectedSex : null;
@endphp
<x-records-page title="Edit my information" eyebrow="Student portal" :description="$student->full_name.' - '.$student->student_number">
    <x-slot:actions><a class="records-button" href="{{ route('student.profile') }}">Back to my profile</a></x-slot:actions>

    <section class="dashboard-card records-card records-form-card" aria-labelledby="profile-edit-help-title">
        <h2 class="records-section-title" id="profile-edit-help-title">Your Student information</h2>
        <p>Keep your personal, contact, address and family details up to date. Changes are saved to your own Student record and recorded in activity history.</p>
        <dl class="records-details mt-4">
            <div><dt>Student ID</dt><dd>{{ $student->student_number }}</dd></div>
            <div><dt>Login email</dt><dd>{{ $loginEmail }}</dd></div>
        </dl>
        <p class="record-help">Student and government IDs, academic details, record status and scholarship indicators are managed by authorized staff. Updating your contact email here does not change your login email or password.</p>
    </section>

    <section class="dashboard-card records-card records-form-card" aria-label="Edit Student information">
        <form method="POST" action="{{ route('student.profile.update') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
            @csrf @method('PATCH')
            <input type="hidden" name="profile_version" value="{{ $profileVersion }}">

            <h2 class="records-section-title">Personal information</h2>
            <div class="records-form-grid">
                <x-record-field name="first_name" label="Given name / first name" :value="old('first_name', $student->first_name)" required maxlength="255" autocomplete="given-name" />
                <x-record-field name="last_name" label="Last name" :value="old('last_name', $student->last_name)" required maxlength="255" autocomplete="family-name" />
                <x-record-field name="middle_name" label="Middle name (optional)" :value="old('middle_name', $student->middle_name)" maxlength="255" autocomplete="additional-name" />
                <x-record-field name="suffix" label="Extension / suffix (optional)" :value="old('suffix', $student->suffix)" maxlength="30" />
                <x-record-field name="sex" label="Sex" type="select"><option value="">Not provided</option>@foreach(ScholarProfile::SEX as $value => $label)<option value="{{ $value }}" @selected($selectedSex !== null && (string) $selectedSex === (string) $value)>{{ $label }}</option>@endforeach</x-record-field>
                <x-record-field name="birthdate" label="Birthdate" type="date" :value="old('birthdate', $student->birthdate?->toDateString())" :max="now('Asia/Manila')->toDateString()" />
            </div>

            <h2 class="records-section-title scholar-section-title">Contact information</h2>
            <div class="records-form-grid">
                <x-record-field name="email" label="Contact email" type="email" :value="old('email', $student->email)" maxlength="255" autocomplete="email" help="For Student contact information. Your login email stays unchanged." />
                <x-record-field name="phone" label="Contact number" type="tel" :value="old('phone', $student->phone)" maxlength="40" autocomplete="tel" help="Mobile: 9171234567, 09171234567 or +639171234567. Landline: 8 digits." />
            </div>

            <h2 class="records-section-title scholar-section-title">Permanent address</h2>
            <div class="records-form-grid">
                <x-record-field name="street_barangay" label="Street and barangay" :value="old('street_barangay', $student->street_barangay)" maxlength="255" autocomplete="address-line1" />
                <x-record-field name="city_municipality" label="City / municipality" :value="old('city_municipality', $student->city_municipality)" maxlength="255" autocomplete="address-level2" />
                <x-record-field name="province" label="Province" :value="old('province', $student->province)" maxlength="255" autocomplete="address-level1" />
                <x-record-field name="region" label="Region" :value="old('region', $student->region)" maxlength="255" />
                <x-record-field name="zip_code" label="ZIP code" :value="old('zip_code', $student->zip_code)" maxlength="20" autocomplete="postal-code" />
            </div>

            <h2 class="records-section-title scholar-section-title">Family background</h2>
            <div class="records-form-grid">
                @foreach(['father_first_name' => "Father's given name", 'father_middle_name' => "Father's middle name", 'father_last_name' => "Father's last name", 'mother_maiden_first_name' => "Mother's maiden given name", 'mother_maiden_middle_name' => "Mother's maiden middle name", 'mother_maiden_last_name' => "Mother's maiden last name"] as $name => $label)
                    <x-record-field :name="$name" :label="$label" :value="old($name, $student->getAttribute($name))" maxlength="255" />
                @endforeach
            </div>

            <div class="records-form-footer">
                <p class="record-help">Saving these details does not change scholarship eligibility, enrollment, official academic records or login access.</p>
                <div class="records-actions"><a class="records-button" href="{{ route('student.profile') }}">Cancel</a><x-primary-button class="records-submit" x-bind:disabled="submitting" x-bind:aria-busy="submitting">Save my information</x-primary-button></div>
            </div>
        </form>
    </section>
</x-records-page>
