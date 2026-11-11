@can('financial-evidence')
    <div class="financial-evidence">
        <h3 class="records-section-title">Private evidence</h3>
        <ul class="records-timeline">@forelse($owner->documents as $document)<li>
            <a class="records-link" href="{{ route('financial.evidence.download', [$kind, $owner->id, $document]) }}">{{ $document->original_name }}</a><span>{{ Str::headline($document->document_type) }} · {{ ucfirst($document->review_status) }}</span>
            @can('financial-verify')@if($document->review_status !== 'accepted' && $document->uploaded_by !== auth()->id())<form method="POST" action="{{ route('financial.evidence.review', [$kind, $owner->id, $document]) }}" class="records-actions">@csrf<label>Review notes <input class="record-input" name="review_notes" maxlength="4000" required></label><button class="records-button" name="decision" value="accepted">Accept evidence</button><button class="records-button" name="decision" value="rejected">Reject</button></form>@endif
@endcan
        </li>@empty<li>No evidence uploaded. A record is not verified by an empty upload field.</li>@endforelse</ul>
        @unless($kind === 'liquidation' && $owner->status !== 'draft')
            <form method="POST" enctype="multipart/form-data" action="{{ route('financial.evidence.store', [$kind, $owner->id]) }}" class="records-actions">@csrf
                <label>Evidence type <select class="record-input" name="document_type">@foreach(\App\Services\Billing\FinancialEvidence::TYPES[$kind] as $documentType)@if($documentType !== 'deduction_authority' || auth()->user()->can('financial-verify'))<option value="{{ $documentType }}">{{ Str::headline($documentType) }}</option>@endif
@endforeach</select></label>
                <label>PDF, JPG or PNG (maximum 2 MB) <input class="record-input" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png" required></label><button class="records-button" type="submit">Upload private evidence</button>
            </form>
        @endunless
    </div>
@endcan
