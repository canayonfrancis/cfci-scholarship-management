<dl class="records-details">
    @foreach(['effective' => 'Approved effective allocation', 'funded' => 'School receipts allocated to batch', 'school_net' => 'Verified student payments from school cash', 'direct_net' => 'Verified direct-to-student payments', 'retained' => 'Authorized deductions retained / unsettled', 'settled' => 'Deductions actually settled', 'school_cash_remaining' => 'Allocated school cash not yet paid / settled'] as $field => $label)<div><dt>{{ $label }} (PHP)</dt><dd>{{ $summary[$field] }}</dd></div>@endforeach
</dl>
