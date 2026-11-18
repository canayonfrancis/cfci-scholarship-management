<?php

namespace App\Actions\Accounts;

use JsonSerializable;

final readonly class StudentAccountProvisioningResult implements JsonSerializable
{
    private function __construct(
        public bool $successful,
        public string $code,
        public string $message,
        public ?int $userId = null,
        public ?int $studentId = null,
        public bool $changed = false,
        public bool $blocked = false,
    ) {}

    public static function linked(int $userId, int $studentId, bool $changed): self
    {
        return new self(true, $changed ? 'linked' : 'unchanged',
            'Student access linked. Existing credentials and other role memberships were preserved.',
            $userId, $studentId, $changed);
    }

    public static function failure(string $code, string $message, bool $blocked = false): self
    {
        return new self(false, $code, $message, blocked: $blocked);
    }

    public static function created(int $userId, int $studentId, bool $changed): self
    {
        return new self(true, $changed ? 'created' : 'unchanged', $changed
            ? 'Student login account created. Password setup is pending.'
            : 'This student already has the reviewed login account. Its current access state was preserved.',
            $userId, $studentId, $changed);
    }

    public function jsonSerialize(): array
    {
        return [
            'successful' => $this->successful, 'code' => $this->code, 'message' => $this->message,
            'user_id' => $this->userId, 'student_id' => $this->studentId,
            'changed' => $this->changed, 'blocked' => $this->blocked,
        ];
    }
}
