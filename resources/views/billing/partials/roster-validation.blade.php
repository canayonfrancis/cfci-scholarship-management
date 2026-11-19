    <section class="dashboard-card records-card"><h2>Roster validation workspace</h2>
        <p>These results use existing semester records and current eligibility checks. Roster membership does not establish payability. Scholarship statuses are unchanged; authorized Registrar personnel can record enrollment findings below.</p>
        <p class="record-help">Counts cover the whole roster before search and table filters. Enrollment verification does not activate a scholarship or establish payability. Current school compliance, applicable retention requirements and external approvals still require review through authorized Evaluation details.</p>
        @if($canVerifyEnrollment)
            <div class="records-actions"><button id="verify-enrollment-trigger" class="records-button" type="button" aria-haspopup="dialog" aria-controls="verify-enrollment" @click="openEnrollment(null, $event.currentTarget)" @disabled(!$enrollmentReady)>Verify enrollment</button></div>
        @endif
        <form class="records-filters" method="GET" action="{{ route('billing-batches.show', $batch) }}"><input type="hidden" name="tab" value="roster"><x-record-field name="q" label="Search roster by Student ID or name" :value="$search['q'] ?? ''" maxlength="100" /><button class="records-button" type="submit">Search</button><a class="records-button" href="{{ route('billing-batches.show', ['batch' => $batch, 'tab' => 'roster']) }}">Clear</a></form>
        <form method="POST" action="{{ route('billing-batches.items.bulk', $batch) }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}" x-data="billingRosterSelection(@js($selectable), @js(old('application_ids', [])))" @submit="submitSelection($event)" :aria-busy="saving">
            @csrf<input type="hidden" name="billing_tab" value="roster"><input type="hidden" name="lock_version" value="{{ $batch->lock_version }}">
            @can('update', $batch)
                <div class="records-actions"><button class="records-button" type="button" @click="selectDisplayed()" :disabled="saving || rows.length === 0">Select all displayed eligible scholars</button><button class="records-button" type="button" @click="clearSelection()" :disabled="saving || selected.length === 0">Clear selection</button><span class="record-help" role="status" aria-live="polite"><span x-text="selected.length">0</span> selected / PHP <span x-text="total">0.00</span></span></div>
                <p class="record-help">Selection covers this displayed page only (up to 200 scholars). Save a reviewed group before changing search, sort, rows per page or pagination; those changes clear the selection. Switching these three tabs preserves the current selection. Amount previews are rechecked by the server.</p>
            @endcan
            <div class="records-table-wrap"><x-table-state :paginator="$members" /><table class="records-table billing-roster-table"><thead><tr><th data-table-no-sort>Select</th><th>Student ID</th><th>Full name</th><th>Course / program</th><th>Year level</th><th>Scholarship status</th><th>Enrollment</th><th>Eligibility</th><th>Availability</th><th>Reason</th><th>Amount (PHP)</th><th>Actions</th></tr></thead><tbody>
                @forelse($members as $row)@php($student = $row['member']->student)
                    <tr>
                        <td>@can('update', $batch)@if($row['eligible'])<input type="checkbox" name="application_ids[]" value="{{ $row['application']->id }}" x-model="selected" @change="review = false; confirmed = false" @click="if (saving) $event.preventDefault()" aria-label="Select {{ $student->full_name }}">@else<span aria-label="Unavailable">&mdash;</span>@endif @else &mdash; @endcan</td>
                        <td>{{ $student->student_number }}</td><td>{{ $student->full_name }}</td><td>{{ $student->academicProgram?->name ?? 'Not provided' }}</td><td>{{ $student->year_level ? (\App\Services\Records\ScholarProfile::yearLevels()[$student->year_level] ?? 'Year '.$student->year_level) : 'Not provided' }}</td>
                        <td><span class="billing-status">{{ $row['member']->assignment->statusLabel() }}</span></td><td><span class="billing-status">{{ $row['enrollment'] }}</span></td><td><span class="billing-status">{{ $row['eligibility'] }}</span></td><td><span class="billing-status">{{ $row['availability'] }}</span></td><td>@if(mb_strlen($row['reason']) > 65)<details class="billing-reason"><summary>{{ \Illuminate\Support\Str::limit($row['reason'], 65) }}</summary><p>{{ $row['reason'] }}</p></details>@else{{ $row['reason'] }}@endif</td><td>{{ $row['amount'] === null ? 'Unavailable' : number_format((float) $row['amount'], 2) }}</td>
                        <td>@if($canVerifyEnrollment && $enrollmentReady)<button class="records-button billing-enrollment-row-action" type="button" aria-haspopup="dialog" aria-controls="verify-enrollment" @click="openEnrollment('{{ $student->id }}', $event.currentTarget)">Verify enrollment</button>@endif @if($row['application'])@can('view', $row['application'])<a class="records-link" href="{{ route('evaluations.show', $row['application']) }}">Evaluation details</a>@endcan @else
                            @if(in_array($row['member']->assignment->status, ['pending', 'active'], true))
                                @can('create', \App\Models\ScholarshipApplication::class)<a class="records-link" href="{{ route('evaluations.create', ['program_id' => $batch->program_id, 'academic_period_id' => $batch->academic_period_id, 'assignment_id' => $row['member']->assignment_id]) }}">Start evaluation</a>
                                @else<span class="record-help">A Coordinator or Administrator must start the semester evaluation.</span>@endcan
                            @endif
                            @can('view', $student)<a class="records-link" href="{{ route('scholars.show', $student) }}">View scholar</a>@endcan
                        @endif</td>
                    </tr>
                @empty<tr><td colspan="12">No roster members match this view. Memberships are managed under Information Registry &rarr; Roster.</td></tr>@endforelse
            </tbody></table></div><div class="records-pagination">{{ $members->links() }}</div>
            @can('update', $batch)
                <div class="records-actions"><button class="records-button is-primary" type="button" @click="reviewSelection()" :disabled="saving || selected.length === 0">Review selected scholars</button></div>
                <section class="records-card" x-show="review" x-cloak x-ref="reviewPanel" tabindex="-1" aria-label="Review billing selection">
                    <h3>Review additions to {{ $batch->batch_number }}</h3>
                    <p>{{ $batch->scholarshipBatch?->batch_number ?? 'No linked roster' }} / {{ $batch->program->code }} / {{ $batch->academicPeriod->academic_year }} / Semester {{ $batch->academicPeriod->semester_code }}.</p>
                    <ul><template x-for="row in selectedRows" :key="row.id"><li><span x-text="row.label"></span> / PHP <span x-text="row.amount"></span></li></template></ul>
                    <p><strong>Selected additions: PHP <span x-text="total"></span>.</strong> The prepared draft total above includes saved items only.</p>
                    <p>Unavailable scholars and reasons are shown in the roster table. All selected scholars are revalidated together. If any fails, no selected billing item is saved. No payable Form 2/Form 3 classification or final submission is produced.</p>
                    <label><input type="checkbox" name="confirm_review" value="1" x-model="confirmed" :disabled="!review" :required="review" @click="if (saving) $event.preventDefault()"> I have reviewed the selected roster, scholars and amounts.</label>
                    <div class="records-actions"><button class="records-button" type="button" @click="review = false; confirmed = false" :disabled="saving">Back to selection</button><button class="records-button is-primary" type="submit" :disabled="saving || !confirmed"><span x-show="!saving">Save selected draft items</span><span x-show="saving" x-cloak>Saving...</span></button></div>
                </section>
            @endcan
        </form>
    </section>
