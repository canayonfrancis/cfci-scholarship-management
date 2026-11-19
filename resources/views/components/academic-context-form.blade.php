@props(['context', 'periods'])
<section class="dashboard-card records-card">
    <h2>Active validation period</h2>
    <p class="record-help">Choose the academic year and semester used for current scholarship evaluation. This does not open or close a semester; availability for new billing is managed separately.</p>
    <p>Current period: <strong>{{ $context->activePeriod ? $context->activePeriod->academic_year.' / Semester '.$context->activePeriod->semester_code : 'Not configured' }}</strong></p>
    @can('create', \App\Models\AcademicPeriod::class)
        <form method="POST" action="{{ route('academic-periods.context') }}">@csrf<input type="hidden" name="revision" value="{{ $context->revision }}">
            <x-record-field name="active_period_id" label="Active academic year / semester for validation" type="select" required><option value="">Select active period</option>@foreach($periods as $choice)<option value="{{ $choice->id }}" @selected(old('active_period_id', $context->active_period_id) == $choice->id)>{{ $choice->academic_year }} / {{ $choice->semester_code }}</option>@endforeach</x-record-field>
            <div class="records-form-footer"><x-primary-button class="records-submit">Save validation period</x-primary-button></div>
        </form>
    @endcan
</section>
