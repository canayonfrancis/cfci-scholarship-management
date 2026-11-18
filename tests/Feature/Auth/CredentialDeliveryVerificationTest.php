<?php

namespace Tests\Feature\Auth;

use App\Mail\CredentialDeliveryVerification;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CredentialDeliveryVerificationTest extends TestCase
{
    private function configured(): void
    {
        config(['app.url' => 'https://sms.example.test', 'student_accounts.mailer' => 'student_accounts',
            'mail.mailers.student_accounts' => ['transport' => 'smtp', 'scheme' => 'smtp', 'require_tls' => true, 'host' => 'smtp.example.test', 'port' => 587,
                'username' => 'test-user', 'password' => 'test-secret', 'from' => ['address' => 'qa@example.test']]]);
    }

    public function test_blank_credentials_and_configuration_alone_never_approve_delivery(): void
    {
        Mail::fake();
        $delivery = app(StudentCredentialDelivery::class);
        $this->assertFalse($delivery->available());
        $this->configured();
        $this->assertTrue($delivery->configured());
        $this->assertFalse($delivery->available());
        Mail::assertNothingSent();
    }

    public function test_receipt_proof_is_hashed_single_use_and_configuration_bound(): void
    {
        Mail::fake();
        $this->configured();
        $delivery = app(StudentCredentialDelivery::class);
        $email = 'controlled@example.test';
        $delivery->beginVerification($email);
        $code = '';
        Mail::assertSent(CredentialDeliveryVerification::class, function ($mail) use (&$code) {
            $code = $mail->verificationCode;

            return true;
        });
        $this->assertFalse($delivery->available());
        $this->assertStringNotContainsString($code, json_encode(Cache::get('student_credentials.challenge.'.hash('sha256', $email))));
        $this->assertTrue($delivery->confirmVerification($email, $code));
        $this->assertTrue($delivery->available());
        $this->assertFalse($delivery->confirmVerification($email, $code));
        config(['mail.mailers.student_accounts.password' => 'different-test-secret']);
        $this->assertFalse($delivery->available());
    }

    public function test_invalid_and_expired_receipt_codes_do_not_enable_creation(): void
    {
        Mail::fake();
        $this->configured();
        $delivery = app(StudentCredentialDelivery::class);
        $email = 'controlled@example.test';
        $delivery->beginVerification($email);
        $this->assertFalse($delivery->confirmVerification($email, 'wrong-code'));
        $this->assertFalse($delivery->available());
        $delivery->beginVerification($email);
        $code = '';
        Mail::assertSent(CredentialDeliveryVerification::class, function ($mail) use (&$code) {
            $code = $mail->verificationCode;

            return true;
        });
        $this->travel(901)->seconds();
        $this->assertFalse($delivery->confirmVerification($email, $code));
        $this->assertFalse($delivery->available());
    }

    public function test_log_transport_and_unencrypted_smtp_cannot_be_verified(): void
    {
        Mail::fake();
        $this->configured();
        $delivery = app(StudentCredentialDelivery::class);
        config(['student_accounts.mailer' => 'log']);
        $this->assertFalse($delivery->configured());
        config(['student_accounts.mailer' => 'student_accounts', 'mail.mailers.student_accounts.require_tls' => false]);
        $this->assertFalse($delivery->configured());
        Mail::assertNothingSent();
    }

    public function test_status_command_is_read_only_and_reports_unverified_configuration(): void
    {
        Mail::fake();
        $this->artisan('sms:verify-student-delivery', ['--status' => true])->assertFailed();
        Mail::assertNothingSent();
    }
}
