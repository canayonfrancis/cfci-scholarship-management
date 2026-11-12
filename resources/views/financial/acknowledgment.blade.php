<div class="financial-evidence">
    <h3 class="records-section-title">Signed payment acknowledgment</h3>
    <p class="record-help">Ordinary accepted payment proof does not confirm a beneficiary signature. Upload a Signed acknowledgment for this actual payout using Private evidence, then have a different Coordinator verify its signature and exact coverage. This applies to school and direct payments. Record the amount actually received by the student, not gross benefit.</p>
    <ul class="records-timeline">@forelse($payout->acknowledgments as $ack)<li>
        <strong>{{ app(\App\Services\Billing\SignedAcknowledgment::class)->usable($ack, $payout) ? 'Signed acknowledgment verified' : 'Signature verification retained — evidence requires attention' }}</strong>
        <span>{{ $ack->document_reference }} · {{ $ack->page_line }} · {{ $ack->verifiedBy->name }} · {{ $ack->verified_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</span>
        <span>{{ $ack->institutional_student_id }} · {{ $ack->beneficiary_name }} · PHP {{ $ack->amount_received }} · {{ $ack->paid_on->toDateString() }} · {{ $ack->payment_reference }}</span>
        @can('financial-evidence')<a class="records-link" href="{{ route('financial.evidence.download', ['payout', $payout->id, $ack->document_id]) }}">Open private signed evidence</a>@endcan
    </li>@empty<li>No explicit signature verification recorded. Legacy generic proof is not automatically signed.</li>@endforelse</ul>
    @can('financial-verify')@if($payout->status === 'posted' && $payout->entry_type === 'original' && $payout->recorded_by !== auth()->id())
        <form method="POST" action="{{ route('financial.acknowledgments.store', $payout) }}">@csrf
            <p class="record-help">Inspect the private document and transcribe its beneficiary/payment coverage below. SMS checks these values against the retained actual payout; the Coordinator verifies the signature.</p>
            <div class="records-form-grid">
                <x-record-field name="document_id" label="Signed acknowledgment owned by this payout" type="select" required><option value="">Select signed evidence</option>@foreach($payout->documents->where('document_type', 'signed_acknowledgment')->where('uploaded_by', '!=', auth()->id()) as $document)<option value="{{ $document->id }}">#{{ $document->id }} · {{ $document->original_name }}</option>@endforeach</x-record-field>
                @foreach(['document_reference' => 'Acknowledgment document reference', 'page_line' => 'Page and line covering this payment', 'institutional_student_id' => 'Student ID shown on acknowledgment', 'beneficiary_name' => 'Beneficiary name shown on acknowledgment', 'payment_reference' => 'Actual payment reference shown on acknowledgment'] as $field => $label)<x-record-field :name="$field" :label="$label" :maxlength="$field === 'beneficiary_name' ? 1100 : ($field === 'institutional_student_id' ? 50 : 255)" required />@endforeach
                <x-record-field name="paid_on" label="Actual payment date shown on acknowledgment" type="date" :max="now('Asia/Manila')->toDateString()" required />
                <x-record-field name="amount_received" label="Student amount received shown on acknowledgment (PHP)" inputmode="decimal" required />
                <label><input type="checkbox" name="signature_confirmed" value="1" required> I verified the beneficiary's signature and that this document/page/line acknowledges this student's exact actual payment.</label>
            </div>
            <button class="records-button" type="submit">Verify signed payment acknowledgment</button>
        </form>
    @endif
    @endcan
</div>
