<x-records-page title="Billing and Validation" description="Prepare billing from an existing semester roster. Draft preparation, final submission, budget approval and actual payment are separate stages.">
    <x-slot:actions>@can('create', \App\Models\BillingBatch::class)<button id="create-billing-trigger" type="button" class="records-button is-primary" aria-haspopup="dialog" aria-controls="create-billing" x-data @click="$dispatch('open-record-form-dialog', { id: 'create-billing', trigger: $el })">Create billing</button>@endcan<a class="records-button" href="{{ route('billing-batches.report', request()->only('program_id', 'academic_year', 'academic_period_id')) }}">Allocation report</a><a class="records-button" href="{{ route('financial.funding-status') }}">Billing stages / awaiting funds</a></x-slot:actions>
    <section class="dashboard-card records-card"><h2>Semester filters</h2>
        <form class="records-filters" method="GET" action="{{ route('billing-batches.index') }}" x-data="billingBatchFilter(@js(route('billing-batches.options')), {{ $batchOptions->count() }}, { searchField: 'q' })" @change="if (['program_id', 'academic_year', 'academic_period_id', 'status', 'q'].includes($event.target.name)) refresh()">
            <x-record-field name="q" label="Search billing batch, roster or reference" :value="$filters['q'] ?? ''" maxlength="100" />
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
    <section class="dashboard-card records-card"><h2>Allocation batches</h2>
        <div class="records-table-wrap"><x-table-state :paginator="$batches" /><table class="records-table"><thead><tr><th>Batch</th><th>Program</th><th>Year / semester</th><th>Status</th><th>Scholars</th><th>Prepared total</th></tr></thead><tbody>
            @forelse($batches as $batch)<tr><td><a class="records-link" href="{{ route('billing-batches.show', $batch) }}">{{ $batch->batch_number }}</a></td><td>{{ $batch->program->code }}</td><td>{{ $batch->academicPeriod->academic_year }} / {{ $batch->academicPeriod->semester_code }}</td><td>{{ $batch->allocationStatus() === 'submitted' ? 'Awaiting Coordinator approval' : ucfirst($batch->allocationStatus()) }}</td><td>{{ $batch->items_count }}</td><td>{{ $batch->totalAmount() === null ? 'Unconfirmed total' : '₱'.number_format((float) $batch->totalAmount(), 2) }}</td></tr>
            @empty<tr><td colspan="6">No allocation batches match these filters.</td></tr>@endforelse
        </tbody></table></div><div class="records-pagination">{{ $batches->links() }}</div>
    </section>
    <details class="dashboard-card records-card"><summary>Scholarship budget overview</summary><p>Available budget is an approved planning limit. Remaining budget deducts approved allocations and authorized adjustments; it is not a cash balance.</p><x-allocation-summary :summaries="$summaries" :paginator="$scopes" /><div class="records-pagination">{{ $scopes->links() }}</div></details>
    @can('create', \App\Models\BillingBatch::class)
        @php($createRosterId = is_scalar(old('scholarship_batch_id')) ? (string) old('scholarship_batch_id') : '')
        <div x-data="{ rosterId: @js($createRosterId), rosters: @js($rosterChoices), get roster() { return this.rosters[this.rosterId] ?? null; } }">
            <x-record-form-dialog id="create-billing" title="Create billing draft" :action="route('billing-batches.store')" submit-label="Create draft and review roster" busy-label="Creating draft..."
                :show="$errors->getBag('createBilling')->any() || session('reopen_create_billing', false)" :has-errors="$errors->getBag('createBilling')->any()" trigger-id="create-billing-trigger"
                :defaults="['scholarship_batch_id' => '', 'reference' => '', 'billing_source_reference' => '']">
                <x-slot:description>Select an existing roster once. Its program and semester are retrieved automatically. Scholars are validated on the draft page before you add billing items.</x-slot:description>
                @if($errors->getBag('createBilling')->any())<div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>Please review the billing details below.</strong><ul>@foreach($errors->getBag('createBilling')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @if(session('reopen_create_billing'))<p class="records-notice" role="status">The semester rate is now approved. Review the amount, then create the draft. No billing draft was created by the rate confirmation.</p>@endif
                <x-record-field name="scholarship_batch_id" id="create-billing-roster" label="Existing semester roster" type="select" error-bag="createBilling" x-model="rosterId" @change="$refs.rateConfirmation.checked = false; $refs.rateApprovalReference.value = ''" required>
                    <option value="">Select a roster in an open academic period</option>
                    @foreach($rosters as $roster)<option value="{{ $roster->id }}" @selected($createRosterId === (string) $roster->id)>{{ $roster->batch_number }} / {{ $roster->program->code }} / {{ $roster->academicPeriod->academic_year }} / Semester {{ $roster->academicPeriod->semester_code }}</option>@endforeach
                </x-record-field>
                @if($rosters->isEmpty())<p class="records-alert">No rosters in open academic periods are available. Use Information Registry to manage Semester and Roster records.</p>@endif
                <div x-show="roster" x-cloak>
                    <dl class="records-details">
                        <div><dt>Roster name</dt><dd x-text="roster?.name"></dd></div>
                        <div><dt>Roster batch number</dt><dd x-text="roster?.number"></dd></div>
                        <div><dt>Scholarship program</dt><dd x-text="roster?.program"></dd></div>
                        <div><dt>Academic year / semester</dt><dd x-text="roster ? roster.year + ' / Semester ' + roster.semester : ''"></dd></div>
                        <div><dt>Existing members</dt><dd x-text="roster?.members"></dd></div>
                        <div><dt>Approved semester rate (read-only)</dt><dd x-text="roster?.variable ? 'Not applicable to variable EDUKAR' : (roster?.rate ? 'PHP ' + Number(roster.rate).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Not yet confirmed for this semester')"></dd></div>
                        <div x-show="roster?.reference_amount != null"><dt>Configured program reference (read-only, not an approved semester rate)</dt><dd x-text="roster?.reference_amount != null ? 'PHP ' + Number(roster.reference_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''"></dd></div>
                    </dl>
                    <p class="record-help" role="status" x-text="roster?.reason"></p>
                </div>
                <div x-show="roster?.can_confirm" x-cloak>
                    <h4>Confirm the applicable semester rate</h4>
                    <p class="record-help">Use the configured program amount shown above for this roster's semester. Enter the actual approval reference; the amount is retrieved from the program record when saved. This confirmation does not approve any scholar or submit billing.</p>
                    <input type="hidden" name="use_program_reference" value="1" :disabled="!roster?.can_confirm">
                    <input type="hidden" name="expected_reference_amount" :value="roster?.reference_amount ?? ''" :disabled="!roster?.can_confirm">
                    <input type="hidden" name="previous_rate" :value="roster?.previous_rate ?? 0" :disabled="!roster?.can_confirm">
                    <x-record-field name="billing_source_reference" id="create-billing-rate-reference" label="School rate approval reference" :value="old('billing_source_reference')" maxlength="3000" error-bag="createBilling" x-ref="rateApprovalReference" x-bind:required="!!roster?.can_confirm" x-bind:disabled="!roster?.can_confirm" />
                    <label><input type="checkbox" name="confirm_billing_policy" value="1" x-ref="rateConfirmation" :required="!!roster?.can_confirm" :disabled="!roster?.can_confirm"> Confirm the configured reference amount as the applicable semester rate for this roster's program and period.</label>
                </div>
                <x-record-field name="reference" id="create-billing-reference" label="Preparation reference (optional)" :value="old('reference')" maxlength="255" error-bag="createBilling" />
                <p class="record-help">This creates only a billing draft. Existing roster memberships and scholarship assignments are preserved. Final submission awaits approved Form 2/Form 3 classification.</p>
                <x-slot:footer>
                    <button class="records-button" type="button" @click="cancel()" :disabled="submitting">Cancel</button>
                    <button class="records-button is-primary" type="submit" :formaction="roster?.can_confirm ? @js(route('billing-batches.terms')) : @js(route('billing-batches.store'))" :disabled="submitting || !(roster?.ready || roster?.can_confirm)"><span x-show="!submitting" x-text="roster?.can_confirm ? 'Confirm semester rate' : 'Create draft and review roster'">Create draft and review roster</span><span x-show="submitting" x-cloak>Saving...</span></button>
                </x-slot:footer>
            </x-record-form-dialog>
        </div>
    @endcan
    @can('configure', \App\Models\BillingBatch::class)
        <details class="dashboard-card records-card"><summary>Configure scholarship rates and budgets</summary>
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
        </details>
    @endcan
</x-records-page>
