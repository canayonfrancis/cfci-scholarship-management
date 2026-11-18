<x-records-page title="My student profile" eyebrow="Student portal" :description="$student ? $student->full_name.' · '.$student->student_number : ''">
    <x-slot:actions>
        @if($student) @can('updateOwnProfile', $student)<a class="records-button is-primary" href="{{ route('student.profile.edit') }}">Edit my information</a>@endcan @endif
        @can('account-settings')<a class="records-button" href="{{ route('profile.edit') }}">Account settings</a>@endcan
    </x-slot:actions>
    @if($student)
        <section class="dashboard-card records-card">
            @can('updateOwnProfile', $student)
                <p>You can update your personal, contact, address and family information. Student identifiers, academic details and scholarship information are maintained by the Registrar.</p>
                <p class="record-help">Changing your contact email here does not change your login email. Contact the Registrar for help with your login account.</p>
            @else
                <p>Contact the Registrar to request corrections to your official information.</p>
            @endcan
        </section>
        @include('scholars.profile-details', ['scholar' => $student, 'profileHistory' => collect(), 'showAcademicHistoryHelp' => false])
    @else
        <section class="dashboard-card records-card">
            <h2 class="records-section-title">No student profile linked</h2>
            <p>Your account is not linked to a student record. Contact the Registrar or Scholarship Coordinator to arrange access.</p>
        </section>
    @endif
</x-records-page>
