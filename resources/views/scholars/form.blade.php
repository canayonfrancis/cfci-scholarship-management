<x-records-page :title="$scholar->exists ? 'Edit scholar' : 'Add scholar'" description="Keep student identity and contact details together in one record.">
    <x-slot:actions><a class="records-button" href="{{ $scholar->exists ? route('scholars.show', $scholar) : route('scholars.index') }}">Back to {{ $scholar->exists ? 'scholar' : 'scholars' }}</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card">
        <form action="{{ $scholar->exists ? route('scholars.update', $scholar) : route('scholars.store') }}" method="POST">
            @csrf @if($scholar->exists) @method('PATCH') @endif
            <h2 class="records-section-title">Student information</h2>
            <div class="records-form-grid">
                <x-record-field name="student_number" label="Student number" :value="old('student_number', $scholar->student_number)" required maxlength="50" autocomplete="off" />
                <x-record-field name="record_status" label="Record status" type="select" required><option value="active" @selected(old('record_status', $scholar->record_status) === 'active')>Active</option><option value="archived" @selected(old('record_status', $scholar->record_status) === 'archived')>Archived</option></x-record-field>
                <x-record-field name="first_name" label="First name" :value="old('first_name', $scholar->first_name)" required maxlength="255" autocomplete="given-name" />
                <x-record-field name="middle_name" label="Middle name (optional)" :value="old('middle_name', $scholar->middle_name)" maxlength="255" autocomplete="additional-name" />
                <x-record-field name="last_name" label="Last name" :value="old('last_name', $scholar->last_name)" required maxlength="255" autocomplete="family-name" />
            </div>
            <h2 class="records-section-title">Contact information</h2>
            <div class="records-form-grid">
                <x-record-field name="email" label="Email address (optional)" type="email" :value="old('email', $scholar->email)" maxlength="255" autocomplete="email" />
                <x-record-field name="phone" label="Phone number (optional)" type="tel" :value="old('phone', $scholar->phone)" maxlength="40" autocomplete="tel" />
            </div>
            <div class="records-form-footer"><p class="record-help">Close any active scholarship before archiving a scholar. Scholarship history is retained.</p><x-primary-button class="records-submit">{{ $scholar->exists ? 'Save changes' : 'Add scholar' }}</x-primary-button></div>
        </form>
    </section>
</x-records-page>
