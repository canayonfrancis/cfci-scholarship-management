@php
    use App\Services\Records\ScholarProfile;
    $missing = $scholar->missingProfileFields();
    $yesNo = fn ($value) => $value === null ? 'Not provided' : ($value ? 'Yes' : 'No');
    $protected = auth()->user()->can('viewProtectedInformation', $scholar);
    $sections = [
        'Student Identification' => ['Student ID' => $scholar->student_number, 'Record status' => ucfirst($scholar->record_status)],
        'Personal Information' => ['Last name' => $scholar->last_name, 'Given name' => $scholar->first_name, 'Extension / suffix' => $scholar->suffix, 'Middle name' => $scholar->middle_name],
        'Academic Information' => ['Complete program name' => $scholar->academicProgram?->name, 'Year level' => ScholarProfile::yearLevels()[$scholar->year_level] ?? $scholar->year_level, 'Academic year' => $scholar->academicPeriod?->academic_year, 'Semester' => $scholar->academicPeriod?->semester_code],
        'Contact Information' => ['Contact number' => $scholar->phone, 'Email address' => $scholar->email],
    ];
    if ($protected) {
        $sections['Student Identification'] += ['Learner Reference Number (LRN)' => $scholar->lrn, 'PhilSys ID / National ID' => $scholar->philsys_id, '4Ps ID' => $scholar->four_ps_id];
        $sections['Personal Information'] += ['Sex' => ScholarProfile::SEX[$scholar->sex] ?? null, 'Birthdate' => $scholar->birthdate?->format('M j, Y')];
        $sections['Family Background'] = [];
        foreach (['father_last_name' => 'Father’s last name', 'father_first_name' => 'Father’s given name', 'father_middle_name' => 'Father’s middle name', 'mother_maiden_last_name' => 'Mother’s maiden last name', 'mother_maiden_first_name' => 'Mother’s maiden given name', 'mother_maiden_middle_name' => 'Mother’s maiden middle name'] as $key => $label) {
            $sections['Family Background'][$label] = $scholar->getAttribute($key);
        }
        $sections['Permanent Address'] = [];
        foreach (['street_barangay' => 'Street & barangay', 'city_municipality' => 'City / municipality', 'province' => 'Province', 'region' => 'Region', 'zip_code' => 'ZIP code'] as $key => $label) {
            $sections['Permanent Address'][$label] = $scholar->getAttribute($key);
        }
        $sections['Additional Student Information'] = ['Disability type' => ScholarProfile::DISABILITIES[$scholar->disability_type] ?? null, 'Dependent / child of solo parent' => $yesNo($scholar->solo_parent_dependent), 'First-generation college student' => $yesNo($scholar->first_generation_student), 'Indigenous People group' => $scholar->indigenous_people_group];
    }
@endphp
<section class="dashboard-card records-card">
    <h2 class="records-section-title">Profile completeness <span class="records-badge {{ $missing ? 'is-amber' : 'is-green' }}">{{ $missing ? 'Incomplete' : 'Complete' }}</span></h2>
    <p class="record-help">{{ count($missing) }} required {{ Str::plural('field', count($missing)) }} missing or needing review. Completeness is independent of scholarship eligibility and approval.</p>
    @if($missing && $protected)<ul class="scholar-missing-fields">@foreach($missing as $label)<li>{{ $label }}</li>@endforeach</ul>@endif
    @unless($protected)<p class="record-help">Protected identification, personal and family details are available to authorized profile staff.</p>@endunless
</section>
@foreach(['Student Identification', 'Personal Information', 'Academic Information', 'Family Background', 'Permanent Address', 'Contact Information', 'Additional Student Information'] as $section)
    @if(isset($sections[$section]))
        <section class="dashboard-card records-card">
            <h2 class="records-section-title">{{ $section }}</h2>
            <dl class="records-details">@foreach($sections[$section] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value === null || $value === '' ? 'Not provided' : $value }}</dd></div>@endforeach</dl>
            @if($section === 'Academic Information' && ($showAcademicHistoryHelp ?? true))<p class="record-help">Current administrative profile. Registrar-certified semester records are maintained in academic history below.</p>@endif
        </section>
    @endif
@endforeach
@if(auth()->user()->can('viewBankAccount', $scholar) || auth()->user()->can('viewOwnProfile', $scholar))
    <section id="landbank-account-details" class="dashboard-card records-card">
        <h2 class="records-section-title">Bank Account Information</h2>
        <dl class="records-details"><div><dt>LANDBANK Savings Account Number</dt><dd>{{ $scholar->maskedLandbankAccountNumber() ?? 'Not provided' }}</dd></div></dl>
        @can('viewBankAccount', $scholar)
            @if($scholar->maskedLandbankAccountNumber() !== null)
                @if($showBankAccount ?? false)
                    <p>{{ $scholar->landbank_account_number }}</p>
                    <a class="records-link" href="{{ route('scholars.show', $scholar) }}#landbank-account-details">Hide full account number</a>
                @else
                    <a class="records-link" href="{{ route('scholars.show', ['scholar' => $scholar, 'show_bank_account' => 1]) }}#landbank-account-details">View full account number</a>
                @endif
            @endif
        @endcan
        <p class="record-help">Optional profile information. A missing account number does not determine scholarship eligibility or initiate a bank transfer.</p>
    </section>
@endif
@if($protected && $profileHistory->isNotEmpty())
    <section class="dashboard-card records-card">
        <h2 class="records-section-title">Academic profile activity</h2>
        <p class="record-help">Saved profile selections are retained here; they are separate from Registrar verification.</p>
        <ol class="records-timeline">@foreach($profileHistory as $event)<li><strong>{{ $event->after_state['academic_year'] ?? 'Academic year not recorded' }} · Semester {{ $event->after_state['semester_code'] ?? 'not recorded' }}</strong><p>{{ $event->after_state['academic_program_name'] ?? 'Program not provided' }} · Year {{ $event->after_state['year_level'] ?? 'not provided' }}</p><span>{{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · {{ $event->actor_snapshot['name'] ?? 'Recorded staff member' }}</span></li>@endforeach</ol>
        <div class="records-pagination">{{ $profileHistory->links() }}</div>
    </section>
@endif
