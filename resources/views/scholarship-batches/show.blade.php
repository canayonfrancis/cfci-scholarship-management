<x-records-page :title="$batch->batch_number.' — semester roster'" :description="$batch->program->code.' · '.$batch->academicPeriod->academic_year.' / Semester '.$batch->academicPeriod->semester_code">
    <x-slot:actions><a class="records-button" href="{{ route('scholarship-batches.index') }}">All rosters</a><a class="records-button" href="{{ route('scholars.index') }}">Select scholar to assign</a></x-slot:actions>
    <section class="dashboard-card records-card">
        <p class="records-notice">Enrollment findings do not grant scholarship eligibility or external approval. Official Form 2/Form 3 classification awaits the approved school templates. Non-enrolled scholars remain in this roster.</p>
        <form class="records-filters" method="GET"><x-record-field name="view" label="Roster view" type="select">@foreach(['all' => 'All members', 'awaiting_verification' => 'Awaiting Registrar enrollment verification', 'unbilled' => 'Unbilled members (no submitted billing)'] as $value => $label)<option value="{{ $value }}" @selected(($filters['view'] ?? 'all') === $value)>{{ $label }}</option>@endforeach</x-record-field><button class="records-button" type="submit">Show members</button></form>
        <div class="records-table-wrap"><x-table-state :paginator="$members" /><table class="records-table"><thead><tr><th>Scholar</th><th>Assignment</th><th>Latest enrollment source</th><th>Registrar finding</th><th>Billing</th></tr></thead><tbody>
            @forelse($members as $member)
                @php
                    $record = $member->enrollmentRecord;
                    $known = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at && in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true);
                @endphp
                <tr><td><a class="records-link" href="{{ route('scholars.show', $member->student) }}">{{ $member->student->full_name }}</a><small class="records-muted">{{ $member->student->student_number }}</small></td><td>{{ $member->assignment->statusLabel() }}</td>
                    <td>@if($record)<span>Revision {{ $record->revision }}</span><small class="records-muted">{{ $record->verifiedBy?->name }} {{ $record->verified_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</small>@else No academic record @endif</td>
                    <td>{{ $known ? ($record->enrollment_status === 'enrolled' ? 'Verified enrolled' : 'Verified not enrolled') : 'Awaiting Registrar verification' }}<small class="records-muted">Form classification pending approved template</small></td>
                    <td>{{ $member->submitted_billing_id ? 'Submitted billing item #'.$member->submitted_billing_id : 'Unbilled — no submitted billing' }}@if(!$member->submitted_billing_id && $member->reserved_billing_id)<small class="records-muted">Existing semester reservation #{{ $member->reserved_billing_id }}; review its draft/history before preparing another bill.</small>@endif</td>
                </tr>
            @empty<tr><td colspan="5">No roster members match this view.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $members->links() }}</div>
    </section>
</x-records-page>
