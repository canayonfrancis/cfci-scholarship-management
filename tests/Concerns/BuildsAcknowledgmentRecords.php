<?php

namespace Tests\Concerns;

use App\Actions\Billing\VerifyPaymentAcknowledgment;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\User;
use App\Services\Billing\FinancialEvidence;
use Illuminate\Http\UploadedFile;

trait BuildsAcknowledgmentRecords
{
    /** Explicit synthetic human verification, never a real signature or school policy fixture. */
    private function acknowledgmentData(Payout $payout, int $documentId): array
    {
        return ['document_id' => $documentId, 'document_reference' => 'SYNTHETIC-ACK-'.$payout->id, 'page_line' => 'Page 1, line '.$payout->id,
            'institutional_student_id' => $payout->institutional_student_id, 'beneficiary_name' => $payout->payee,
            'paid_on' => $payout->paid_on->toDateString(), 'amount_received' => $payout->net_amount, 'payment_reference' => $payout->reference, 'signature_confirmed' => 1];
    }

    private function signedDocument(Payout $payout, User $uploader)
    {
        return app(FinancialEvidence::class)->upload($payout, $uploader, UploadedFile::fake()->createWithContent('synthetic-acknowledgment.pdf', "%PDF-1.4\nSynthetic beneficiary acknowledgment test fixture\n%%EOF"), 'signed_acknowledgment');
    }

    private function makeSignedAcknowledgment(Payout $payout, User $uploader, User $reviewer)
    {
        $payout = $payout->fresh();
        $document = $this->signedDocument($payout, $uploader);

        return app(VerifyPaymentAcknowledgment::class)->handle($payout, $reviewer, $this->acknowledgmentData($payout, $document->id));
    }

    private function makeLiquidationSupport(Liquidation $report, User $uploader, User $reviewer)
    {
        $files = app(FinancialEvidence::class);
        $document = $files->upload($report, $uploader, UploadedFile::fake()->createWithContent('synthetic-liquidation-support.pdf', "%PDF-1.4\nSynthetic liquidation support test fixture\n%%EOF"), 'liquidation_support');
        $files->review($document, $reviewer, 'accepted', 'Synthetic required supporting document reviewed');

        return $document->fresh();
    }
}
