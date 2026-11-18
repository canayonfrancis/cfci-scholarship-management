<x-records-page :title="$batch->batch_number" description="Semester scholarship allocation. Preparation, submission and Coordinator approval are separate from actual receipts and releases.">
    <x-slot:actions><a class="records-button" href="{{ route('billing-batches.index') }}">Scholarship funds</a></x-slot:actions>
    <section class="dashboard-card records-card">
        <h2>{{ $batch->program->code }} · {{ $batch->academicPeriod->academic_year }} · Semester {{ $batch->academicPeriod->semester_code }}</h2>
        @if($batch->scholarshipBatch)
            <p>Semester roster: <a class="records-link" href="{{ route('scholarship-batches.show', $batch->scholarshipBatch) }}">{{ $batch->scholarshipBatch->batch_number }}</a>. Only members with the matching scholarship assignment can be added.</p>
        @else
            <p class="records-notice">No recorded roster link. Existing financial history is retained; the original roster is unknown. No roster is inferred.</p>
            @if($batch->status === 'draft')<p class="records-notice">This unlinked draft cannot add scholars or be submitted. Create a new linked draft to prepare new billing.</p>@endif
        @endif
        <dl class="records-details"><dt>Status</dt><dd>{{ ucfirst($batch->allocationStatus()) }}</dd><dt>Prepared total</dt><dd>{{ $batch->totalAmount() === null ? 'Unconfirmed total' : '₱'.number_format((float) $batch->totalAmount(), 2) }}</dd><dt>Prepared by</dt><dd>{{ $batch->preparedBy?->name ?? 'Not recorded' }}</dd><dt>Submission reference</dt><dd>{{ $batch->reference ?? 'Not recorded' }}</dd><dt>Submitted by</dt><dd>{{ $batch->submittedBy?->name ?? 'Not submitted' }}</dd><dt>Submitted at (Manila)</dt><dd>{{ $batch->submitted_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Not submitted' }}</dd><dt>Approved semester amount</dt><dd>{{ $batch->appliedRate() === null ? 'Unconfirmed' : '₱'.number_format((float) $batch->appliedRate()->amount, 2) }}</dd><dt>Amount / policy reference</dt><dd>{{ $batch->appliedRate()?->source_reference ?? $batch->terms->billing_source_reference }}</dd></dl>
        @if($batch->status !== 'draft')<p class="records-notice">Submitted records are retained. A Coordinator must approve the allocation; later corrections use a separate authorized adjustment.</p>@endif
    </section>
    <section class="dashboard-card records-card"><h2>Coordinator allocation approval</h2>
        @if($batch->approval)
            <p class="records-notice">Approved by {{ $batch->approval->approvedBy->name }} on {{ $batch->approval->approved_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}. Reference: {{ $batch->approval->reference }}. Original approved total: ₱{{ number_format((float) $batch->approval->amount_snapshot, 2) }}.</p><p>This approval reserves scholarship budget. It does not confirm a receipt or payment.</p>
        @elseif($batch->status === 'submitted')
            <p>Awaiting independent Coordinator approval. Preparers and submitters cannot approve their own allocations.</p>
        @else<p>A Registrar or Coordinator must submit a validated allocation record before a different Coordinator can approve it.</p>@endif
        @can('approve', $batch)
            <form class="records-form" method="POST" action="{{ route('billing-batches.approve', $batch) }}">@csrf<div class="record-field"><label for="coordinator-approval-reference">Coordinator approval reference</label><input class="record-input" id="coordinator-approval-reference" name="reference" maxlength="255" required></div><button class="records-button is-primary" type="submit">Review eligibility, check budget and approve</button></form>
        @endcan
    </section>
    @can('update', $batch)
        <section class="dashboard-card records-card"><h2>Edit draft details</h2><form class="records-form" method="POST" action="{{ route('billing-batches.update', $batch) }}">@csrf @method('PATCH')<input type="hidden" name="lock_version" value="{{ $batch->lock_version }}">
            <div class="records-form-grid"><x-record-field name="batch_number" label="Batch name" :value="old('batch_number', $batch->batch_number)" maxlength="60" required /><x-record-field name="reference" label="Draft reference" :value="old('reference', $batch->reference)" maxlength="255" /></div><button class="records-button is-primary" type="submit">Save draft</button>
        </form></section>
    @endcan
    <section class="dashboard-card records-card"><h2>Scholar allocation items</h2>
        <div class="records-table-wrap"><x-table-state :paginator="$items" /><table class="records-table"><thead><tr><th>Student number</th><th>Scholar</th><th>Semester amount</th><th>Validation / approval</th><th>Item status</th><th>Actions</th></tr></thead><tbody>
            @forelse($items as $item)<tr><td>{{ $item->student->student_number }}</td><td>{{ $item->student->full_name }}</td><td>{{ $item->gross_amount === null ? 'Unconfirmed' : '₱'.number_format((float) $item->gross_amount, 2) }}</td><td>Validation #{{ $item->evaluation_run_id }} / Approval #{{ $item->external_approval_id }}</td><td>{{ ucfirst($item->status) }}</td><td>@can('update', $batch)<form method="POST" action="{{ route('billing-batches.items.destroy', [$batch, $item]) }}">@csrf @method('DELETE')<input type="hidden" name="lock_version" value="{{ $batch->lock_version }}"><button class="records-button" type="submit">Remove draft item</button></form>@endcan</td></tr>
            @empty<tr><td colspan="6">No scholars added to this allocation batch.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $items->links() }}</div>
    </section>
    @can('update', $batch)
        <section class="dashboard-card records-card"><h2>Add an individual scholar</h2><p>Only active, validated and verified scholars with a semester rate can be added. A reservation in any program prevents another allocation for this scholar and semester.</p>
            <form class="records-filters" method="GET" action="{{ route('billing-batches.show', $batch) }}"><x-record-field name="q" label="Find scholar by name or student number" :value="$search['q'] ?? ''" maxlength="100" /><button class="records-button" type="submit">Search</button></form>
            <div class="records-table-wrap"><x-table-state :paginator="$candidates" /><table class="records-table"><thead><tr><th>Student number</th><th>Scholar</th><th>Allocation eligibility</th><th>Action</th></tr></thead><tbody>
                @forelse($candidates as $candidate)@php($application = $candidate['application'])<tr><td>{{ $application->student->student_number }}</td><td>{{ $application->student->full_name }}</td><td>{{ $candidate['reason'] ?? 'Permitted under the confirmed billing policy' }}</td><td>@if(!$candidate['reason'])<form method="POST" action="{{ route('billing-batches.items.store', $batch) }}">@csrf<input type="hidden" name="lock_version" value="{{ $batch->lock_version }}"><input type="hidden" name="application_id" value="{{ $application->id }}"><button class="records-button is-primary" type="submit">Add scholar</button></form>@endif</td></tr>
                @empty<tr><td colspan="4">No unbilled semester validations match this search.</td></tr>@endforelse
            </tbody></table></div>@if($candidates instanceof \Illuminate\Contracts\Pagination\Paginator)<div class="records-pagination">{{ $candidates->links() }}</div>@endif
        </section>
        <section class="dashboard-card records-card"><h2>Submit for Coordinator approval</h2><p>Roster membership, every scholar, amount, validation and verified semester approval are checked again. Submission requests Coordinator approval and does not release funds. Submitted records cannot be edited.</p>
            <p class="records-notice">Final submission remains blocked until approved Form 2/Form 3 templates and current payable classification are implemented. Membership alone does not establish payability.</p>
            <form class="records-form" method="POST" action="{{ route('billing-batches.submit', $batch) }}">@csrf<input type="hidden" name="lock_version" value="{{ $batch->lock_version }}"><div class="record-field"><label for="submission-reference">Submission reference</label><input class="record-input" id="submission-reference" name="reference" value="{{ old('reference', $batch->reference) }}" maxlength="255" required></div><button class="records-button is-primary" type="submit">Validate and submit batch</button></form>
        </section>
    @endcan
    @if($batch->allocationStatus() === 'approved')
        <section class="dashboard-card records-card"><h2>Approved allocation and adjustment history</h2><p>Original amounts remain unchanged. Authorized adjustments revise the effective allocation and remaining budget; they never create a second semester allocation.</p>
            @foreach($items as $item)<article class="records-card"><h3>{{ $item->student->full_name }} · {{ $item->student->student_number }}</h3><p>Original: ₱{{ number_format((float) $item->gross_amount, 2) }} · Effective: ₱{{ number_format((float) $item->effectiveAmount(), 2) }}</p>
                <ul>@forelse($item->adjustments->sortBy('sequence') as $adjustment)<li>Adjustment {{ $adjustment->sequence }} · {{ ucfirst($adjustment->direction) }} ₱{{ number_format((float) $adjustment->amount, 2) }} · {{ $adjustment->authorizedBy->name }} · {{ $adjustment->authorized_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $adjustment->reference }}<p>{{ $adjustment->reason }}</p></li>@empty<li>No adjustments.</li>@endforelse</ul>
                @can('adjust', $batch)<details><summary>Authorize a financial adjustment</summary><form class="records-form" method="POST" action="{{ route('billing-batches.items.adjust', [$batch, $item]) }}">@csrf<input type="hidden" name="previous_adjustment" value="{{ $item->adjustments->max('sequence') ?? 0 }}">
                    <div class="records-form-grid"><div class="record-field"><label for="adjust-amount-{{ $item->id }}">Correct effective allocation amount (PHP)</label><input class="record-input" id="adjust-amount-{{ $item->id }}" name="new_amount" inputmode="decimal" value="{{ $item->effectiveAmount() }}" required></div><div class="record-field"><label for="adjust-reference-{{ $item->id }}">Authorization reference</label><input class="record-input" id="adjust-reference-{{ $item->id }}" name="reference" maxlength="255" required></div><div class="record-field"><label for="adjust-reason-{{ $item->id }}">Correction reason</label><textarea class="record-input" id="adjust-reason-{{ $item->id }}" name="reason" maxlength="3000" required></textarea></div></div><button class="records-button" type="submit">Authorize adjustment</button>
                </form></details>@endcan
            </article>@endforeach
        </section>
    @endif
</x-records-page>
