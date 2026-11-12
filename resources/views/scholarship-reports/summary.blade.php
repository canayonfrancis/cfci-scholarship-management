<section class="dashboard-card records-card">
    <h2>Report definition and whole-result totals</h2>
    <p>{{ $result['definition'] }}</p>
    <p class="record-help">Generated {{ $result['generated_at'] }} (Asia/Manila). All counts and totals below cover every matching row before pagination. This is current recorded state, not a historical as-of reconstruction. Unconfirmed amounts are never converted to zero.</p>
    @if($result['filters'])<p>Applied filters: @foreach($result['filters'] as $key => $value){{ Str::headline($key) }} = {{ $value }}{{ $loop->last ? '' : ' · ' }}@endforeach</p>@endif
    <dl class="records-details">
        @foreach(['rows' => 'Listed rows', 'scholars' => 'Distinct scholars', 'grants' => 'Distinct student / program / period combinations', 'billing_batches' => 'Distinct billing submissions'] as $field => $label)<div><dt>{{ $label }}</dt><dd>{{ $result['counts'][$field] }}</dd></div>@endforeach
        @foreach($result['totals'] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value ?? 'Unconfirmed — source amount requires review' }}</dd></div>@endforeach
    </dl>
</section>
