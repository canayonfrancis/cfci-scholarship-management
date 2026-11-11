@props(['context', 'periods'])
@php($sourceSelection = old('source_period_id', $context->source_period_id))
<section class="dashboard-card records-card">
    <h2 class="records-section-title">Active validation period and grading source</h2>
    <p>Active period: {{ $context->activePeriod ? $context->activePeriod->academic_year.' / '.$context->activePeriod->semester_code : 'Not configured' }}</p>
    <p>Source grading period: {{ $context->sourcePeriod ? $context->sourcePeriod->academic_year.' / '.$context->sourcePeriod->semester_code : 'Pending school policy confirmation' }}</p>
    @if($context->source_policy_reference)<p class="record-help">School source policy: {{ $context->source_policy_reference }}</p>@endif
    <p class="record-help">The active validation period is separate from a semester being open for record maintenance. No first-semester source or default academic year is inferred.</p>
    @can('create', \App\Models\AcademicPeriod::class)<form method="POST" action="{{ route('academic-periods.context') }}" x-data="{ sourcePeriod: @js(is_scalar($sourceSelection) ? (string) $sourceSelection : '') }">@csrf<input type="hidden" name="revision" value="{{ $context->revision }}"><div class="records-form-grid">
        <x-record-field name="active_period_id" label="Active academic year / semester for validation" type="select" required><option value="">Select active period</option>@foreach($periods as $choice)<option value="{{ $choice->id }}" @selected(old('active_period_id', $context->active_period_id) == $choice->id)>{{ $choice->academic_year }} / {{ $choice->semester_code }}</option>@endforeach</x-record-field>
        <x-record-field name="source_period_id" label="Source grading semester under school policy" type="select" x-model="sourcePeriod"><option value="">Not yet confirmed</option>@foreach($periods as $choice)<option value="{{ $choice->id }}" @selected(old('source_period_id', $context->source_period_id) == $choice->id)>{{ $choice->academic_year }} / {{ $choice->semester_code }}</option>@endforeach</x-record-field>
        <x-record-field name="source_policy_reference" label="Approved school policy reference for the grading source" type="textarea" :value="old('source_policy_reference', $context->source_policy_reference)" maxlength="4000" x-bind:disabled="!sourcePeriod" x-bind:required="!!sourcePeriod" />
    </div><label><input name="confirm_source" type="checkbox" value="1" x-bind:disabled="!sourcePeriod" x-bind:required="!!sourcePeriod"> I confirmed this grading-source period against the cited school policy.</label><div class="records-form-footer"><x-primary-button class="records-submit">Save validation context</x-primary-button></div></form>@endcan
</section>
