<x-records-page title="Scholarship funds and allocations" description="Manage semester scholarship budgets and allocations. Budgets and approved allocations are separate from money actually received or released.">
    <x-slot:actions><a class="records-button" href="{{ route('billing-batches.report', request()->only('program_id', 'academic_year', 'academic_period_id')) }}">Allocation report</a><a class="records-button" href="{{ route('financial.funding-status') }}">Billing stages / awaiting funds</a></x-slot:actions>
    <section class="dashboard-card records-card"><h2>Semester filters</h2>
        <form class="records-filters" method="GET" action="{{ route('billing-batches.index') }}" x-data="billingBatchFilter(@js(route('billing-batches.options')), {{ $batchOptions->count() }})" @change="if (['program_id', 'academic_year', 'academic_period_id', 'status'].includes($event.target.name)) refresh()">
            <x-record-field name="program_id" label="Program" type="select"><option value="">All programs</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($filters['program_id'] ?? '') == $program->id)>{{ $program->name }} ({{ $program->code }})</option>@endforeach</x-record-field>
            <x-record-field name="academic_year" label="Academic year" type="select"><option value="">All years</option>@foreach($years as $year)<option value="{{ $year }}" @selected(($filters['academic_year'] ?? '') === $year)>{{ $year }}</option>@endforeach</x-record-field>
            <x-record-field name="academic_period_id" label="Semester" type="select"><option value="">All semesters</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(($filters['academic_period_id'] ?? '') == $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</x-record-field>
            <x-record-field name="status" label="Allocation status" type="select"><option value="">All statuses</option>@foreach(['draft' => 'Draft', 'submitted' => 'Awaiting Coordinator approval', 'approved' => 'Approved', 'void' => 'Void history'] as $status => $label)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>@endforeach</x-record-field>
            <x-batch-number-field :options="$batchOptions" :value="old('billing_batch_id', $filters['billing_batch_id'] ?? '')" />
            <p class="record-help" role="status" aria-live="polite" x-cloak x-show="loading">Loading matching billing batches…</p>
            <p class="record-help" role="status" aria-live="polite" x-show="!loading && optionsCount === 0 && !loadError" @if($batchOptions->isNotEmpty()) x-cloak @endif>No matching billing batches exist for these filters.</p>
            <p class="record-help" role="alert" x-cloak x-show="loadError" x-text="loadError"></p>
            <button class="records-button" type="button" x-cloak x-show="loadError" @click="refresh()">Retry loading batches</button>
            <button class="records-button is-primary" type="submit" x-bind:disabled="loading || !!loadError">Filter</button><a class="records-button" href="{{ route('billing-batches.index') }}">Clear</a>
        </form>
    </section>
    <section class="dashboard-card records-card"><h2>Scholarship budget overview</h2><p>Available budget is an approved planning limit. Remaining budget deducts approved allocations and authorized adjustments; it is not a cash balance.</p><x-allocation-summary :summaries="$summaries" /><div class="records-pagination">{{ $scopes->links() }}</div></section>
    <section class="dashboard-card records-card"><h2>Allocation batches</h2>
        <div class="records-table-wrap"><table class="records-table"><thead><tr><th>Batch</th><th>Program</th><th>Year / semester</th><th>Status</th><th>Scholars</th><th>Prepared total</th></tr></thead><tbody>
            @forelse($batches as $batch)<tr><td><a class="records-link" href="{{ route('billing-batches.show', $batch) }}">{{ $batch->batch_number }}</a></td><td>{{ $batch->program->code }}</td><td>{{ $batch->academicPeriod->academic_year }} / {{ $batch->academicPeriod->semester_code }}</td><td>{{ $batch->allocationStatus() === 'submitted' ? 'Awaiting Coordinator approval' : ucfirst($batch->allocationStatus()) }}</td><td>{{ $batch->items_count }}</td><td>{{ $batch->totalAmount() === null ? 'Unconfirmed total' : '₱'.number_format((float) $batch->totalAmount(), 2) }}</td></tr>
            @empty<tr><td colspan="6">No allocation batches match these filters.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $batches->links() }}</div>
    </section>
    @can('create', \App\Models\BillingBatch::class)
        <section class="dashboard-card records-card"><h2>Prepare a semester allocation batch</h2><p>Choose a program and semester with an approved rate. A Registrar or Coordinator records and submits; a different Coordinator reviews and approves.</p>
            <p class="record-help">New batches receive the next available batch number for the chosen program and semester. To view an existing batch, use Batch Number in the filters above.</p>
            <form class="records-form" method="POST" action="{{ route('billing-batches.store') }}"
                x-data="{ program: @js((string) old('program_id', $filters['program_id'] ?? '')), period: @js((string) old('academic_period_id', $filters['academic_period_id'] ?? '')) }"
                @change="if ($event.target.name === 'program_id') { program = $event.target.value; $refs.roster.value = ''; } if ($event.target.name === 'academic_period_id') { period = $event.target.value; $refs.roster.value = ''; }">
                @csrf
                <div class="records-form-grid">
                    <x-billing-scope :programs="$programs" :periods="$periods" prefix="create" :selected-program="old('program_id', $filters['program_id'] ?? '')" :selected-period="old('academic_period_id', $filters['academic_period_id'] ?? '')" />
                    <div class="record-field"><label for="create-roster">Scholarship semester roster</label>
                        <select class="record-input" id="create-roster" name="scholarship_batch_id" x-ref="roster" required>
                            <option value="">Select a matching semester roster</option>
                            @foreach($rosters as $roster)
                                <option value="{{ $roster->id }}" @selected((string) old('scholarship_batch_id') === (string) $roster->id)
                                    x-show="program === '{{ $roster->program_id }}' && period === '{{ $roster->academic_period_id }}'"
                                    x-bind:disabled="program !== '{{ $roster->program_id }}' || period !== '{{ $roster->academic_period_id }}'">{{ $roster->program->code }} · {{ $roster->batch_number }} · {{ $roster->academicPeriod->academic_year }} / {{ $roster->academicPeriod->semester_code }}</option>
                            @endforeach
                        </select>
                        <p class="record-help">Select the program and semester first. One roster can support several submissions. Create a missing roster under <a class="records-link" href="{{ route('scholarship-batches.index') }}">Semester rosters</a>.</p>
                    </div>
                    <x-record-field name="reference" label="Preparation reference (optional)" :value="old('reference')" maxlength="255" />
                </div><button class="records-button is-primary" type="submit">Create draft allocation batch</button>
            </form>
        </section>
    @endcan
    @can('configure', \App\Models\BillingBatch::class)
        <section class="dashboard-card records-card"><h2>Configure a scholarship rate for a semester</h2><p>Select the actual academic year and semester. Program reference amounts below are configurable starting values; they do not create historical rates or approve scholars automatically.</p>
            <ul>@foreach($programs as $program)<li>{{ $program->name }}: {{ $program->school_stated_semester_amount === null ? 'Amount unconfirmed' : '₱'.number_format((float) $program->school_stated_semester_amount, 2).' per semester' }}</li>@endforeach</ul>
            <form class="records-form" method="POST" action="{{ route('billing-batches.terms') }}" x-data="{ amount: @js(old('semester_gross_amount', $programs->firstWhere('id', $filters['program_id'] ?? null)?->school_stated_semester_amount ?? '')), references: @js($programs->pluck('school_stated_semester_amount', 'id')) }">@csrf<input type="hidden" name="previous_rate" value="0">
                <div class="records-form-grid"><div class="record-field"><label for="new-rate-program">Scholarship program</label><select class="record-input" id="new-rate-program" name="program_id" required @change="amount = references[$event.target.value] ?? ''"><option value="">Select program</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected((string) old('program_id', $filters['program_id'] ?? '') === (string) $program->id)>{{ $program->name }}</option>@endforeach</select></div>
                <div class="record-field"><label for="new-rate-period">Academic year / semester</label><select class="record-input" id="new-rate-period" name="academic_period_id" required><option value="">Select year and semester</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string) old('academic_period_id', $filters['academic_period_id'] ?? '') === (string) $period->id)>{{ $period->academic_year }} / {{ $period->semester_code }}</option>@endforeach</select></div>
                <x-record-field name="semester_gross_amount" label="Approved semester allocation rate (PHP)" x-model="amount" inputmode="decimal" required /><x-record-field name="billing_source_reference" label="School rate approval reference" :value="old('billing_source_reference')" maxlength="3000" required /></div>
                <label><input type="checkbox" name="confirm_billing_policy" value="1" required> Confirm this semester rate for active scholars with completed eligibility validation and verified semester approval.</label>
                <div class="records-actions"><button class="records-button is-primary" type="submit">Approve initial semester rate</button></div>
            </form>
        </section>
        @foreach($summaries as $summary)@php($term = $summary['terms'])
            <section class="dashboard-card records-card"><h2>{{ $term->program->name }} · {{ $term->academicPeriod->academic_year }} / {{ $term->academicPeriod->semester_code }}</h2>
                <details><summary>Manage semester rate and budget history</summary>
                    <h3>Approve a new rate revision</h3><p>Existing batches retain the rate used when prepared. This revision applies to new batches; corrections to approved allocations use a separate adjustment.</p>
                    <form class="records-form" method="POST" action="{{ route('billing-batches.terms') }}">@csrf<input type="hidden" name="program_id" value="{{ $term->program_id }}"><input type="hidden" name="academic_period_id" value="{{ $term->academic_period_id }}"><input type="hidden" name="previous_rate" value="{{ $summary['rate']?->id ?? 0 }}">
                        <div class="records-form-grid"><div class="record-field"><label for="rate-{{ $term->id }}">Semester rate (PHP)</label><input class="record-input" id="rate-{{ $term->id }}" name="semester_gross_amount" inputmode="decimal" value="{{ $summary['rate']?->amount ?? $term->program->school_stated_semester_amount }}" required></div><div class="record-field"><label for="rate-reference-{{ $term->id }}">Approval reference / reason</label><input class="record-input" id="rate-reference-{{ $term->id }}" name="billing_source_reference" maxlength="3000" required></div></div>
                        <label><input type="checkbox" name="confirm_billing_policy" value="1" required> Approve this rate revision for the selected semester.</label><div class="records-actions"><button class="records-button" type="submit">Record rate revision</button></div>
                    </form>
                    <h3>Configure available scholarship budget</h3><form class="records-form" method="POST" action="{{ route('billing-batches.budget') }}">@csrf<input type="hidden" name="program_id" value="{{ $term->program_id }}"><input type="hidden" name="academic_period_id" value="{{ $term->academic_period_id }}"><input type="hidden" name="previous_budget" value="{{ $summary['budget']?->id ?? 0 }}">
                        <div class="records-form-grid"><div class="record-field"><label for="budget-{{ $term->id }}">Approved budget (PHP)</label><input class="record-input" id="budget-{{ $term->id }}" name="amount" inputmode="decimal" value="{{ $summary['budget']?->amount }}" required></div><div class="record-field"><label for="budget-reference-{{ $term->id }}">Budget approval reference / reason</label><input class="record-input" id="budget-reference-{{ $term->id }}" name="source_reference" maxlength="3000" required></div></div><button class="records-button" type="submit">Record budget revision</button>
                    </form>
                    <h3>Retained rate revisions</h3><ul>@forelse($term->rates->sortByDesc('revision') as $rate)<li>Revision {{ $rate->revision }} · ₱{{ number_format((float) $rate->amount, 2) }} · {{ $rate->approvedBy->name }} · {{ $rate->approved_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $rate->source_reference }}</li>@empty<li>No approved rate.</li>@endforelse</ul>
                    <h3>Retained budget revisions</h3><ul>@forelse($term->budgets->sortByDesc('revision') as $budget)<li>Revision {{ $budget->revision }} · ₱{{ number_format((float) $budget->amount, 2) }} · {{ $budget->approvedBy->name }} · {{ $budget->source_reference }}</li>@empty<li>No approved budget.</li>@endforelse</ul>
                </details>
            </section>
        @endforeach
    @endcan
</x-records-page>
