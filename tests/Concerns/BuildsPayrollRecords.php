<?php

namespace Tests\Concerns;

use App\Actions\Billing\RecordFinancialActivity;
use App\Models\BillingBatch;
use App\Services\Billing\FinancialEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

trait BuildsPayrollRecords
{
    use BuildsBillableAllocations, BuildsScholarshipRecords;

    /** Existing synthetic resolved eligibility fixture, never a production school rule. */
    private function payrollBatch(int $students = 1): array
    {
        $g = $this->ready('TES', '7500.25');
        $g['recorder'] = $this->staff('registrar');
        $g['reviewer'] = $this->staff('coordinator');
        $this->actingAs($g['recorder'])->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $g['roster']->id])->assertSessionHasNoErrors();
        $g['batch'] = BillingBatch::latest('id')->firstOrFail();
        $this->add($g)->assertSessionHasNoErrors();
        for ($i = 1; $i < $students; $i++) {
            $other = $this->ready('TES', '7500.25');
            $this->actingAs($g['recorder']);
            $this->add($other, $g['batch'])->assertSessionHasNoErrors();
        }
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $this->actingAs($g['reviewer'])->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => 0, 'amount' => '100000.00', 'source_reference' => 'Synthetic payroll test budget'])->assertSessionHasNoErrors();
        $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'Synthetic payroll prerequisite'])->assertSessionHasNoErrors();
        $g['items'] = $g['batch']->items()->orderBy('id')->get();
        $g['item'] = $g['items']->first();
        $this->actingAs($g['recorder']);

        return $g;
    }

    private function payrollFunds(array $g, ?string $amount = null)
    {
        $amount ??= $g['batch']->totalAmount();
        $action = app(RecordFinancialActivity::class);
        $receipt = $action->receipt($g['recorder'], ['program_period_term_id' => $g['terms']->id, 'amount' => $amount, 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'Synthetic payroll funding', 'reference' => (string) Str::uuid()]);
        $this->payrollProof($g, $receipt, 'receipt_evidence');
        $action->postReceipt($receipt, $g['reviewer']);
        $action->allocate($g['batch'], $g['recorder'], ['funding_receipt_id' => $receipt->id, 'amount' => $amount, 'operation_key' => (string) Str::uuid()]);

        return $receipt->fresh();
    }

    private function payrollProof(array $g, $owner, string $type)
    {
        $service = app(FinancialEvidence::class);
        $document = $service->upload($owner, $g['recorder'], UploadedFile::fake()->createWithContent('synthetic-proof.pdf', "%PDF-1.4\nSynthetic payroll prerequisite only\n%%EOF"), $type);
        $service->review($document, $g['reviewer'], 'accepted', 'Synthetic independently reviewed source');

        return $document;
    }

    private function payrollPayment(array $g, string $amount, string $path = 'school', $item = null)
    {
        $payment = app(RecordFinancialActivity::class)->payout($g['recorder'], ['billing_item_id' => ($item ?? $g['item'])->id, 'payment_mode' => $path, 'gross_amount' => $amount, 'net_amount' => $amount, 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'cash', 'reference' => (string) Str::uuid()]);
        $this->payrollProof($g, $payment, 'payment_evidence');
        app(RecordFinancialActivity::class)->postPayout($payment, $g['reviewer']);

        return $payment->fresh();
    }
}
