@props(['record'])
@if($record)
    <span>Revision {{ $record->revision }} · {{ ucfirst($record->verification_status) }}</span>
    @if($record->verified_at)<span class="records-muted">Recorded verification: {{ $record->verified_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</span>@endif
    @if($record->source_reference)<details><summary>Recorded source reference</summary><p class="record-help records-preserve-lines break-words">{{ $record->source_reference }}</p></details>@endif
    @php($certificates = $record->documents->where('disk', 'academic')->where('document_type', 'grade_certificate'))
    @if($certificates->isNotEmpty())<details><summary>Retained certificates ({{ $certificates->count() }})</summary><ul class="records-timeline">@foreach($certificates as $certificate)<li><a class="records-link break-words" href="{{ route('academic-certificates.download', ['record' => $record, 'document' => $certificate]) }}">{{ $certificate->original_name }}</a></li>@endforeach</ul></details>@endif
@else<span class="record-help">No retained academic source</span>@endif
