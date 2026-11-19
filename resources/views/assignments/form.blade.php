<x-records-page :title="$assignment->exists ? 'Update scholarship assignment' : 'Assign scholarship'" :description="$scholar->full_name.' · '.$scholar->student_number">
    <x-slot:actions><a class="records-button" href="{{ route('scholars.show', $scholar) }}">Back to scholar</a></x-slot:actions>
    <section class="dashboard-card records-card records-form-card">
        @if($programs->isEmpty() || $periods->isEmpty())<p class="records-alert">A Coordinator must add a scholarship program and academic semester before an assignment can be recorded.</p>@endif
        <form method="POST" action="{{ $assignment->exists ? route('scholars.assignments.update', [$scholar, $assignment]) : route('scholars.assignments.store', $scholar) }}" x-data="{ assignmentStatus: @js(old('status', $assignment->status)) }" x-init="assignmentStatus = $el.querySelector('[name=status]').value">
            @csrf @if($assignment->exists) @method('PATCH') @endif
            <p class="record-help">Create pending assignments here. New awards must be activated through an eligible school evaluation and verified external approval. Only one scholarship can be active at a time.</p>
            <div class="records-form-grid">
                <x-record-field name="program_id" label="Scholarship program" type="select" required :disabled="$assignment->exists"><option value="">Select a program</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(old('program_id', $assignment->program_id) == $program->id)>{{ $program->code }}{{ $program->is_active ? '' : ' (unavailable for new active assignments)' }}</option>@endforeach</x-record-field>
                <x-record-field name="start_period_id" label="Starting academic semester" type="select" required :disabled="$assignment->exists && $assignment->start_period_id !== null"><option value="">Select a semester</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(old('start_period_id', $assignment->start_period_id) == $period->id)>{{ $period->academic_year }} · Semester {{ $period->semester_code }}</option>@endforeach</x-record-field>
                @if($assignment->exists)<input type="hidden" name="program_id" value="{{ $assignment->program_id }}">@if($assignment->start_period_id !== null)<input type="hidden" name="start_period_id" value="{{ $assignment->start_period_id }}">@endif @endif
                <x-record-field name="status" label="Assignment status" type="select" x-model="assignmentStatus" required>@foreach(['pending' => 'Pending', 'active' => 'Active', 'ended' => 'Ended / historical', 'relinquished' => 'Relinquished'] as $value => $label)@unless(($assignment->status === 'active' && $value === 'pending') || ($assignment->status !== 'active' && $value === 'active'))<option value="{{ $value }}" @selected(old('status', $assignment->status) === $value)>{{ $label }}</option>@endunless @endforeach</x-record-field>
                <x-record-field name="starts_on" label="Exact start date (optional)" type="date" :value="old('starts_on', $assignment->starts_on?->format('Y-m-d'))" />
            </div>
            <div class="records-form-grid" x-show="['ended', 'relinquished'].includes(assignmentStatus)" x-cloak>
                <x-record-field name="end_period_id" label="Closing academic semester" type="select" x-bind:disabled="!['ended', 'relinquished'].includes(assignmentStatus)" x-bind:required="['ended', 'relinquished'].includes(assignmentStatus)"><option value="">Select a closing semester</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(old('end_period_id', $assignment->end_period_id) == $period->id)>{{ $period->academic_year }} · Semester {{ $period->semester_code }}</option>@endforeach</x-record-field>
                <x-record-field name="ends_on" label="Exact closing date (optional)" type="date" :value="old('ends_on', $assignment->ends_on?->format('Y-m-d'))" x-bind:disabled="!['ended', 'relinquished'].includes(assignmentStatus)" />
            </div>
            @if($assignment->exists && $delistingReasons->isNotEmpty())
                <div class="record-field" x-show="['ended', 'relinquished'].includes(assignmentStatus)" x-cloak>
                    <label for="assignment-reason-suggestion">Active reason from the Reason for Delisting registry (optional)</label>
                    <select id="assignment-reason-suggestion" name="delisting_reason_id" class="record-input" x-bind:disabled="!['ended', 'relinquished'].includes(assignmentStatus)">
                        <option value="">Select an applicable registered reason</option>
                        @foreach($delistingReasons as $reason)<option value="{{ $reason->id }}" @selected(old('delisting_reason_id') == $reason->id)>{{ $reason->name }}</option>@endforeach
                    </select>
                    <x-input-error :messages="$errors->get('delisting_reason_id')" />
                    <p class="record-help">The server retains the registered wording with the existing status-change reason and audit history. Selecting it does not change status automatically. Ending this assignment is separate from a temporary semester billing exclusion. Add the supporting reference below.</p>
                </div>
            @endif
            <x-record-field name="status_reason" label="Reason / school reference" type="textarea" :value="old('status_reason')" maxlength="5000" help="Required when changing status or recording an ended or relinquished scholarship." />
            <div class="records-form-footer"><p class="record-help">Closed and relinquished assignments remain in the scholar's history and cannot be overwritten.</p><x-primary-button class="records-submit" :disabled="$programs->isEmpty() || $periods->isEmpty()">Save assignment</x-primary-button></div>
        </form>
    </section>
</x-records-page>
