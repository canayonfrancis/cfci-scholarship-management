<?php

namespace App\Console\Commands;

use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Console\Command;

class VerifyStudentCredentialDelivery extends Command
{
    protected $signature = 'sms:verify-student-delivery {email? : Controlled test inbox} {--status : Inspect readiness without sending mail}';

    protected $description = 'Verify real inbox receipt and approve the configured secure credential mailer (trusted console only)';

    public function handle(StudentCredentialDelivery $delivery): int
    {
        if ($this->option('status')) {
            $this->line($delivery->available() ? 'Credential delivery is verified for the current configuration.'
                : ($delivery->configured() ? 'Credential mail is configured; real inbox receipt has not been verified.' : 'Credential mail is not configured. Fill the STUDENT_MAIL_* settings first.'));

            return $delivery->available() ? self::SUCCESS : self::FAILURE;
        }
        try {
            $email = (string) $this->argument('email');
            $delivery->beginVerification($email);
            $this->line('A verification message was submitted to the controlled inbox. Receipt is not yet confirmed.');
            $code = $this->secret('Enter the verification code received in that inbox to approve this configured channel');
            if (! is_string($code) || ! $delivery->confirmVerification($email, trim($code))) {
                $this->error('Receipt verification failed or expired. Delivery remains blocked; run the command again.');

                return self::FAILURE;
            }
        } catch (\Throwable) {
            $this->error('Verification could not complete. Check the secure mail configuration and controlled inbox; no delivery approval was recorded.');

            return self::FAILURE;
        }
        $this->info('Inbox receipt verified. This exact credential-mail configuration is approved.');

        return self::SUCCESS;
    }
}
