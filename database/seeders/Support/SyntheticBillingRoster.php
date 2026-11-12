<?php

namespace Database\Seeders\Support;

use App\Models\BillingBatch;
use App\Services\Billing\BillingRoster;

/** Fictional downstream demo prerequisite; never a school classification or an operational bypass. */
class SyntheticBillingRoster extends BillingRoster
{
    public function submissionClassification(BillingBatch $batch): void
    {
        SampleData::guardSynthetic();
    }
}
