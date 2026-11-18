<?php

namespace App\Services\Accounts;

use App\Mail\CredentialDeliveryVerification;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class StudentCredentialDelivery
{
    private const VERIFIED = 'student_credentials.delivery_verified';

    public function available(): bool
    {
        $proof = Cache::get(self::VERIFIED);

        return $this->configured() && is_array($proof)
            && hash_equals($this->fingerprint(), (string) ($proof['fingerprint'] ?? ''));
    }

    public function configured(): bool
    {
        $mail = $this->settings();
        $base = parse_url((string) config('app.url'));
        $local = in_array($base['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);

        return ($mail['transport'] ?? null) === 'smtp'
            && in_array($mail['scheme'] ?? '', ['smtp', 'smtps'], true)
            && (($mail['scheme'] ?? '') === 'smtps' || ($mail['require_tls'] ?? false) === true)
            && filled($mail['host'] ?? null) && filled($mail['username'] ?? null) && filled($mail['password'] ?? null)
            && (int) ($mail['port'] ?? 0) > 0 && (int) ($mail['port'] ?? 0) <= 65535
            && filter_var($mail['from']['address'] ?? '', FILTER_VALIDATE_EMAIL) !== false
            && filled($base['host'] ?? null) && ! isset($base['user']) && ! isset($base['pass'])
            && ! isset($base['query']) && ! isset($base['fragment'])
            && (($base['scheme'] ?? '') === 'https' || ($local && app()->environment(['local', 'testing'])));
    }

    public function mailer(): string
    {
        return (string) config('student_accounts.mailer', 'student_accounts');
    }

    private function settings(): array
    {
        return (array) config('mail.mailers.'.$this->mailer(), []);
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', json_encode([$this->mailer(), $this->settings(), config('app.url')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Sends only a non-account verification code; never a password or reset token. */
    public function beginVerification(string $email): void
    {
        if (! $this->configured() || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Configure the secure credential mailer and a controlled test inbox first.');
        }
        Cache::forget(self::VERIFIED);
        $key = 'student_credentials.challenge.'.hash('sha256', Str::lower(trim($email)));
        $code = Str::random(32);
        Cache::put($key, ['fingerprint' => $this->fingerprint(), 'code_hash' => Hash::make($code)], 900);
        try {
            Mail::mailer($this->mailer())->to($email)->send(new CredentialDeliveryVerification($code));
        } catch (\Throwable) {
            Cache::forget($key);
            throw new \RuntimeException('Delivery verification could not be sent. Check the configured transport.');
        }
    }

    /** Trusted console confirmation of actual receipt also approves this exact configuration. */
    public function confirmVerification(string $email, #[\SensitiveParameter] string $code): bool
    {
        $key = 'student_credentials.challenge.'.hash('sha256', Str::lower(trim($email)));
        $challenge = Cache::pull($key);
        if (! $this->configured() || ! is_array($challenge)
            || ! hash_equals($this->fingerprint(), (string) ($challenge['fingerprint'] ?? ''))
            || ! Hash::check($code, $challenge['code_hash'])) {
            return false;
        }
        Cache::forever(self::VERIFIED, ['fingerprint' => $this->fingerprint(), 'verified_at' => now()->toIso8601String(),
            'recipient_hash' => hash('sha256', Str::lower(trim($email))), 'approved_by' => 'trusted console receipt verification']);

        return true;
    }

    public function send(User $user, #[\SensitiveParameter] string $token): void
    {
        if (! $this->available() || ! $this->configured()
            || $user->student_access_disabled_at !== null || ! app(StudentAccess::class)->validAssociation($user, $user->student)) {
            throw new \RuntimeException('Student credential delivery is not approved and verified.');
        }
        $user->notify(new ResetPassword($token));
    }
}
