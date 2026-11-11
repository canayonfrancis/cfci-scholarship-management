<x-records-page title="Liquidation record" :description="$report->reference">
    <x-slot:actions><a class="records-button" href="{{ route('financial.batch', $report->billing_batch_id) }}">Back to batch</a><a class="records-button" href="{{ route('financial.liquidations.print', $report) }}" target="_blank" rel="noopener">Printable summary</a></x-slot:actions>
    <section class="dashboard-card records-card">@include('financial.liquidation-summary')</section>
    <section class="dashboard-card records-card">
        @include('financial.evidence', ['owner' => $report, 'kind' => 'liquidation'])
        @can('financial-prepare')@if($report->status === 'draft')<div class="records-actions"><form method="POST" action="{{ route('financial.liquidations.refresh', $report) }}">@csrf<button class="records-button">Refresh from verified records</button></form><form method="POST" action="{{ route('financial.liquidations.submit', $report) }}">@csrf<button class="records-button is-primary">Validate and submit</button></form></div>@endif
@endcan
        @can('financial-verify')@if($report->status === 'submitted' && $report->prepared_by !== auth()->id() && $report->submitted_by !== auth()->id())<form method="POST" action="{{ route('financial.liquidations.review', $report) }}">@csrf<x-record-field name="review_notes" label="Review / return reasons" type="textarea" maxlength="4000" required /><div class="records-actions"><button class="records-button is-primary" name="decision" value="accepted">Accept complete liquidation</button><button class="records-button" name="decision" value="returned">Return for a new corrected report</button></div></form>@endif
@endcan
    </section>
</x-records-page>
