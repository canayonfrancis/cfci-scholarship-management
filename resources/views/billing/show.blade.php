@php
    $tabs = ['roster' => 'Roster Validation', 'items' => 'Billing Items', 'submission' => 'Submission'];
    $editErrors = $errors->getBag('editBilling');
    $enrollmentErrors = $errors->getBag('enrollment');
    $requestedTab = $enrollmentErrors->any() ? 'roster' : (($errors->any() || $editErrors->any()) ? old('billing_tab', request()->query('tab')) : request()->query('tab'));
    $activeTab = is_string($requestedTab) && isset($tabs[$requestedTab]) ? $requestedTab : ($batch->status === 'draft' ? 'roster' : 'items');
    $preparedTotal = $batch->totalAmount();
    $approvedRate = $batch->appliedRate();
    $allocationStatus = $batch->allocationStatus();
    $editValue = fn ($field, $fallback) => $editErrors->any() ? old($field, $fallback) : $fallback;
@endphp
<x-records-page :title="$batch->batch_number" description="Review roster validation, prepared billing items and submission in one workspace.">
    <x-slot:actions>
        @can('update', $batch)<button id="edit-billing-trigger" class="records-button" type="button" aria-haspopup="dialog" aria-controls="edit-billing" x-data @click="$dispatch('open-record-form-dialog', { id: 'edit-billing', trigger: $el })">Edit details</button>@endcan
        <a class="records-button" href="{{ route('billing-batches.index') }}">Back to billing</a>
    </x-slot:actions>
    <div class="billing-workspace" x-data="billingWorkspace(@js($activeTab), { rows: @js($enrollmentRows), selected: @js($enrollmentErrors->any() ? old('enrollment_student_ids', []) : []), outcome: @js($enrollmentErrors->any() && is_string(old('enrollment_status')) ? old('enrollment_status') : '') })"
        @close.capture="if ($event.target.id === 'verify-enrollment') resetEnrollment()">
        <section class="dashboard-card records-card billing-summary" aria-label="Billing summary">
            <dl class="evaluation-summary-grid billing-summary-grid">
                <div><dt>Scholarship program</dt><dd>{{ $batch->program->name }} ({{ $batch->program->code }})</dd></div>
                <div><dt>Academic year / semester</dt><dd>{{ $batch->academicPeriod->academic_year }} / Semester {{ $batch->academicPeriod->semester_code }}</dd></div>
                <div><dt>Semester roster</dt><dd>@if($batch->scholarshipBatch)<a class="records-link" href="{{ route('rosters.show', $batch->scholarshipBatch) }}">{{ $batch->scholarshipBatch->batch_number }}</a>@else No recorded roster link @endif</dd></div>
                <div><dt>Billing status</dt><dd><span class="billing-status">{{ $allocationStatus === 'submitted' ? 'Awaiting Coordinator approval' : ucfirst($allocationStatus) }}</span></dd></div>
                <div><dt>Approved semester rate</dt><dd>{{ $approvedRate === null ? 'Unconfirmed' : 'PHP '.number_format((float) $approvedRate->amount, 2) }}</dd></div>
                <div><dt>Prepared billing total</dt><dd>{{ $preparedTotal === null ? 'Unconfirmed total' : 'PHP '.number_format((float) $preparedTotal, 2) }}</dd></div>
            </dl>
            @if(!$batch->scholarshipBatch)<p class="record-help">The original roster is unknown; none is inferred. Existing financial history is retained. An unlinked draft cannot add scholars or be submitted.</p>@endif
            @if($batch->status === 'draft')<p class="billing-submission-notice"><strong>Final submission unavailable:</strong> approved Form 2/Form 3 templates and current payable classification are still required. <a class="records-link" href="{{ route('billing-batches.show', ['batch' => $batch, 'tab' => 'submission']) }}" @click.prevent="selectTab('submission', true)">View submission requirements</a>.</p>@endif
            <details class="billing-additional-details"><summary>Additional billing details</summary>
                <dl class="evaluation-summary-grid billing-summary-grid">
                    <div><dt>Prepared by</dt><dd>{{ $batch->preparedBy?->name ?? 'Not recorded' }}</dd></div>
                    <div><dt>Submission reference</dt><dd>{{ $batch->reference ?? 'Not recorded' }}</dd></div>
                    <div><dt>Submitted by</dt><dd>{{ $batch->submittedBy?->name ?? 'Not submitted' }}</dd></div>
                    <div><dt>Submitted at (Manila)</dt><dd>{{ $batch->submitted_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Not submitted' }}</dd></div>
                    <div><dt>Amount / policy reference</dt><dd>{{ $approvedRate?->source_reference ?? $batch->terms?->billing_source_reference ?? 'Not recorded' }}</dd></div>
                </dl>
                <p class="record-help">Only roster members with the matching scholarship assignment can be added. Preparation, submission and Coordinator approval are separate from actual receipts and releases.</p>
                @if($batch->status !== 'draft')<p class="record-help">Submitted records are retained. A Coordinator must approve the allocation; later corrections use a separate authorized adjustment.</p>@endif
            </details>
        </section>
        <dl class="billing-readiness" aria-label="Whole-roster validation summary">
            @foreach(['total' => 'Total roster members', 'eligible' => 'Eligible and available', 'review' => 'Pending review', 'excluded' => 'Excluded from draft selection', 'reserved' => 'Already billed or reserved'] as $state => $label)
                <div><dt>{{ $label }}</dt><dd>{{ $readinessSummary[$state] }}</dd></div>
            @endforeach
        </dl>
        <nav class="evaluation-tabs" role="tablist" aria-label="Billing sections" @keydown="tabKey($event)">
            @foreach($tabs as $key => $label)
                <a id="billing-tab-{{ $key }}" role="tab" href="{{ route('billing-batches.show', [...request()->query(), 'batch' => $batch, 'tab' => $key]) }}" aria-controls="billing-panel-{{ $key }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" tabindex="{{ $activeTab === $key ? 0 : -1 }}" :aria-selected="activeTab === '{{ $key }}'" :tabindex="activeTab === '{{ $key }}' ? 0 : -1" :class="activeTab === '{{ $key }}' ? 'is-active' : ''" @click.prevent="selectTab('{{ $key }}')">{{ $label }}</a>
            @endforeach
        </nav>
        @foreach($tabs as $key => $label)
            <section id="billing-panel-{{ $key }}" class="evaluation-tab-panel" role="tabpanel" aria-labelledby="billing-tab-{{ $key }}" tabindex="0" x-show="activeTab === '{{ $key }}'" @if($activeTab !== $key) style="display:none" @endif>
                @include('billing.partials.'.($key === 'roster' ? 'roster-validation' : $key))
            </section>
        @endforeach
        @if($canVerifyEnrollment)
            @include('billing.partials.enrollment-dialog')
        @endif
        @can('update', $batch)
            <x-record-form-dialog id="edit-billing" title="Edit draft details" :action="route('billing-batches.update', $batch)" method="PATCH" submit-label="Save draft" busy-label="Saving..." :show="$editErrors->any()" :has-errors="$editErrors->any()" trigger-id="edit-billing-trigger"
                :defaults="['batch_number' => $batch->batch_number, 'reference' => $batch->reference]">
                <x-slot:description>Update the existing batch name and preparation reference. The roster, semester, approved rate and billing items are unchanged.</x-slot:description>
                @if($editErrors->any())<div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>The draft was not saved.</strong><ul>@foreach($editErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <input type="hidden" name="lock_version" value="{{ $batch->lock_version }}">
                <input type="hidden" name="billing_tab" value="{{ $activeTab }}" :value="activeTab">
                <div class="records-form-grid">
                    <x-record-field name="batch_number" id="edit-billing-name" label="Batch name" :value="$editValue('batch_number', $batch->batch_number)" maxlength="60" required error-bag="editBilling" />
                    <x-record-field name="reference" id="edit-billing-reference" label="Draft reference" :value="$editValue('reference', $batch->reference)" maxlength="255" error-bag="editBilling" />
                </div>
            </x-record-form-dialog>
        @endcan
    </div>
</x-records-page>
