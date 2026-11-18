<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CredentialDeliveryVerification extends Mailable
{
    public function __construct(#[\SensitiveParameter] public readonly string $verificationCode) {}

    public function build(): self
    {
        return $this->subject('Scholarship Management delivery verification')->text('emails.credential-delivery-verification');
    }
}
