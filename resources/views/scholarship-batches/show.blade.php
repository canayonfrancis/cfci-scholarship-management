<x-records-page :title="$batch->batch_number.($rosterMode ? ' — semester roster' : ' — batch details')" :description="$batch->program->code.' · '.$batch->academicPeriod->academic_year.' / Semester '.$batch->academicPeriod->semester_code">
    <x-slot:actions><a class="records-button" href="{{ route($rosterMode ? 'rosters.index' : 'scholarship-batches.index') }}">{{ $rosterMode ? 'All rosters' : 'All batches' }}</a>@unless($rosterMode)<a class="records-button is-primary" href="{{ route('rosters.show', $batch) }}">Manage roster membership</a>@endunless<a class="records-button" href="{{ route('scholars.index') }}">Scholars</a></x-slot:actions>
    <section class="dashboard-card records-card">
        <dl class="records-details">
            <div><dt>Roster batch name</dt><dd>{{ $batch->batch_number }}</dd></div>
            <div><dt>Scholarship program</dt><dd>{{ $batch->program->name }} ({{ $batch->program->code }})</dd></div>
            <div><dt>Batch number</dt><dd>{{ $batchNumber ?? 'Not recorded separately in this historical name' }}</dd></div>
            <div><dt>Academic year</dt><dd>{{ $batch->academicPeriod->academic_year }}</dd></div>
            <div><dt>Semester</dt><dd>{{ $batch->academicPeriod->semester_code }}</dd></div>
            <div><dt>Total roster members</dt><dd>{{ $memberCount }}</dd></div>
        </dl>
    </section>
    <section class="dashboard-card records-card">
        <div class="roster-members-heading"><h2 class="records-section-title">Roster members</h2>@if($rosterMode)@can('create', \App\Models\ScholarshipBatch::class)<button type="button" class="records-button is-primary" x-data @click="$dispatch('open-record-form-dialog', { id: 'add-roster-scholars', trigger: $el })" aria-haspopup="dialog" aria-controls="add-roster-scholars">Add scholars</button>@endcan @endif</div>
        <p class="records-notice">Enrollment findings do not grant scholarship eligibility or external approval. Official Form 2/Form 3 classification awaits the approved school templates. Non-enrolled scholars remain in this roster.</p>
        <form class="records-filters" method="GET"><x-record-field name="view" label="Roster view" type="select">@foreach(['all' => 'All members', 'awaiting_verification' => 'Awaiting Registrar enrollment verification', 'unbilled' => 'Unbilled members (no submitted billing)'] as $value => $label)<option value="{{ $value }}" @selected(($filters['view'] ?? 'all') === $value)>{{ $label }}</option>@endforeach</x-record-field><button class="records-button" type="submit">Show members</button></form>
        <div class="records-table-wrap"><x-table-state :paginator="$members" /><table class="records-table"><thead><tr><th>Student ID</th><th>Full name</th><th>Course / program</th><th>Year level</th><th>Scholarship status</th><th>Latest enrollment source</th><th>Registrar finding</th><th>Billing</th><th>Actions</th></tr></thead><tbody>
            @forelse($members as $member)
                @php
                    $record = $member->enrollmentRecord;
                    $known = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at && in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true);
                @endphp
                <tr><td>{{ $member->student->student_number }}</td><td>{{ $member->student->full_name }}</td><td>{{ $member->student->academicProgram?->name ?? 'Not provided' }}</td><td>{{ $member->student->year_level ? (\App\Services\Records\ScholarProfile::yearLevels()[$member->student->year_level] ?? 'Year '.$member->student->year_level) : 'Not provided' }}</td><td>{{ $member->assignment->statusLabel() }}</td>
                    <td>@if($record)<span>Revision {{ $record->revision }}</span><small class="records-muted">{{ $record->verifiedBy?->name }} {{ $record->verified_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</small>@else No academic record @endif</td>
                    <td>{{ $known ? ($record->enrollment_status === 'enrolled' ? 'Verified enrolled' : 'Verified not enrolled') : 'Awaiting Registrar verification' }}<small class="records-muted">Form classification pending approved template</small></td>
                    <td>{{ $member->submitted_billing_id ? 'Submitted billing item #'.$member->submitted_billing_id : 'Unbilled — no submitted billing' }}@if(!$member->submitted_billing_id && $member->reserved_billing_id)<small class="records-muted">Existing semester reservation #{{ $member->reserved_billing_id }}; review its draft/history before preparing another bill.</small>@endif</td>
                    <td>@can('view', $member->student)<a class="records-link" href="{{ route('scholars.show', $member->student) }}">View</a>@endcan</td>
                </tr>
            @empty<tr><td colspan="9">No roster members match this view.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $members->links() }}</div>
    </section>
    @if($rosterMode)@can('create', \App\Models\ScholarshipBatch::class)
        @include('scholarship-batches.add-scholars')
    @endcan @endif
</x-records-page>
