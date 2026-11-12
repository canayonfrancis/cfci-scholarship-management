<h2>Prepared internal payroll register</h2>
<dl class="records-details">
    <div><dt>Reference</dt><dd>{{ $payroll->reference }}</dd></div>
    <div><dt>Program / academic year / semester</dt><dd>{{ $payroll->batch->program->code }} · {{ $payroll->batch->academicPeriod->academic_year }} / {{ $payroll->batch->academicPeriod->semester_code }}</dd></div>
    <div><dt>Billing submission</dt><dd>{{ $payroll->batch->batch_number }}</dd></div>
    <div><dt>Prepared by / preparation date</dt><dd>{{ $payroll->preparedBy->name }} · {{ $payroll->prepared_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd></div>
    <div><dt>Verified allocated school cash at preparation (PHP)</dt><dd>{{ $payroll->funding_snapshot['allocated_school_cash'] }}</dd></div>
    <div><dt>Previously recorded direct benefit (PHP)</dt><dd>{{ $payroll->funding_snapshot['previous_direct_gross'] }}</dd></div>
</dl>
<p class="records-notice">PREPARED ONLY — this register does not confirm payment, signed payroll evidence or liquidation. Planned amounts are gross benefit; actual student net and specifically authorized deductions are recorded and independently verified through Payments.</p>
<div class="records-table-wrap"><table class="records-table"><caption>Retained preparation amounts in Philippine pesos</caption><thead><tr><th>Student ID / beneficiary</th><th>Billing item</th><th>Effective benefit at preparation</th><th>Gross already applied at preparation</th><th>Planned gross benefit</th></tr></thead><tbody>
    @foreach($payroll->items as $line)<tr><td>{{ $line->institutional_student_id }}<small class="records-muted">{{ $line->beneficiary_name }}</small></td><td>#{{ $line->billing_item_id }}</td><td>{{ $line->effective_amount_snapshot }}</td><td>{{ $line->posted_gross_snapshot }}</td><td>{{ $line->planned_gross_amount }}</td></tr>@endforeach
</tbody><tfoot><tr><th colspan="4">Total planned gross benefit</th><th>{{ $total }}</th></tr></tfoot></table></div>
