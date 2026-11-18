@props(['value'])
@if(is_array($value))
    @if($value === [])<p class="record-help">No recorded values.</p>@else
        <table class="evaluation-snapshot-table"><caption class="sr-only">Recorded snapshot values</caption><tbody>
            @foreach($value as $key => $entry)
                <tr><th scope="row">{{ is_int($key) ? 'Item '.($key + 1) : Str::headline($key) }}</th><td>
                    @if(is_array($entry))<details><summary>{{ $entry['subject_name'] ?? (count($entry).' recorded fields') }}</summary><x-evaluation-snapshot :value="$entry" /></details>
                    @elseif(is_bool($entry)){{ $entry ? 'Yes / true' : 'No / false' }}
                    @elseif($entry === null)<span class="record-help">Null / not recorded</span>
                    @elseif($entry === '')<span class="record-help">Blank</span>
                    @else<span class="records-preserve-lines">{{ $entry }}</span>@endif
                </td></tr>
            @endforeach
        </tbody></table>
    @endif
@else<span>{{ is_bool($value) ? ($value ? 'Yes / true' : 'No / false') : ($value ?? 'Not recorded') }}</span>@endif
