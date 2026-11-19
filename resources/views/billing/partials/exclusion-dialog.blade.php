<div x-data="{
    selected: @js($exclusionErrors->any() && is_scalar(old('membership_id')) ? (string) old('membership_id') : ''),
    operation: @js($exclusionErrors->any() && old('operation') === 'release' ? 'release' : 'exclude'),
    choices: @js($exclusionChoices),
    get member() { return this.choices[this.selected] ?? null; }
}">
    <x-record-form-dialog id="billing-exclusion" title="Record semester billing decision" :action="route('billing-batches.exclusion', ['batch' => $batch] + request()->query())" submit-label="Save semester decision" busy-label="Saving..." :show="$exclusionErrors->any()" :has-errors="$exclusionErrors->any()" trigger-id="billing-exclusion-trigger"
        :defaults="['membership_id' => '', 'operation' => 'exclude', 'reason_id' => '', 'remarks' => '']">
        <x-slot:description>{{ $batch->scholarshipBatch->batch_number }} / {{ $batch->academicPeriod->academic_year }} / Semester {{ $batch->academicPeriod->semester_code }}. This records or releases a temporary billing exclusion. It does not change enrollment or end a scholarship. Permanent closure requires the separate Review scholarship status action and its existing authorization.</x-slot:description>
        @if($exclusionErrors->any())<div class="records-alert" role="alert" tabindex="-1" data-dialog-errors><strong>The decision was not saved.</strong><ul>@foreach($exclusionErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(empty($exclusionChoices))<p class="record-help">No roster members are shown. Close this dialog and review the roster search, filters or page.</p>@endif
        <x-record-field name="membership_id" id="exclusion-member" label="Roster member (current displayed page)" type="select" error-bag="billingExclusion" x-model="selected" @change="operation = 'exclude'" required>
            <option value="">Select the scholar whose semester decision was reviewed</option>
            @foreach($exclusionChoices as $id => $choice)<option value="{{ $id }}" @selected($exclusionErrors->any() && old('membership_id') == $id)>{{ $choice['label'] }}</option>@endforeach
        </x-record-field>
        <input type="hidden" name="exclusion_revision" :value="member?.revision ?? 0">
        <p class="record-help" x-show="member" x-text="'Current enrollment: ' + (member?.enrollment ?? '')"></p>
        <div x-show="member?.decision" x-cloak><p><strong x-text="member?.decision?.excluded ? 'Recorded semester exclusion' : 'Released semester exclusion (history retained)'"></strong></p><p x-text="member?.decision?.reason_name"></p><p class="record-help" x-text="member?.decision?.remarks"></p><p class="record-help" x-text="member?.decision?.release_remarks"></p></div>
        <p class="records-alert" role="status" x-show="member?.blocked" x-cloak x-text="member?.block_reason"></p>
        <x-record-field name="operation" id="exclusion-operation" label="Semester decision" type="select" error-bag="billingExclusion" x-model="operation" required>
            <option value="exclude">Record / replace temporary semester exclusion</option>
            <option value="release" :disabled="!member?.decision?.excluded">Release recorded semester exclusion</option>
        </x-record-field>
        <div x-show="operation === 'exclude'">
            <x-record-field name="reason_id" id="exclusion-reason" label="Reason for Delisting registry — applicable semester reason" type="select" error-bag="billingExclusion" x-bind:disabled="operation !== 'exclude'" x-bind:required="operation === 'exclude'">
                <option value="">Select an applicable Active registered reason</option>
                @foreach($delistingReasons as $reason)<option value="{{ $reason->id }}" @selected($exclusionErrors->any() && old('reason_id') == $reason->id)>{{ $reason->name }}</option>@endforeach
            </x-record-field>
            @if($delistingReasons->isEmpty())<p class="record-help">No Active registry reasons are available. A Coordinator or Test Administrator must register missing reasons through Information Registry → Reason for Delisting. Existing historical wording is retained.</p>@endif
        </div>
        <x-record-field name="remarks" id="exclusion-remarks" label="Remarks / school reference (required for release)" type="textarea" error-bag="billingExclusion" :value="$exclusionErrors->any() ? old('remarks') : ''" maxlength="3000" x-bind:required="operation === 'release'" />
        <label><input type="checkbox" name="confirm_exclusion_review" value="1" required> I reviewed this scholar's decision and applicable source. This changes semester billing exclusion only; enrollment and scholarship status remain unchanged.</label>
        <x-slot:footer>
            <button type="button" class="records-button" @click="cancel()" :disabled="submitting">Cancel</button>
            <button type="submit" class="records-button is-primary" :disabled="submitting || !member || member.blocked"><span x-show="!submitting">Save semester decision</span><span x-show="submitting" x-cloak>Saving...</span></button>
        </x-slot:footer>
    </x-record-form-dialog>
</div>
