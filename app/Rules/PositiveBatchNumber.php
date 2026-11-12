<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PositiveBatchNumber implements ValidationRule
{
    public const MAX = 2147483647;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || strlen((string) $value) > 10 || (int) $value > self::MAX) {
            $fail('Batch Number must be a positive whole number from 1 to 2,147,483,647, without decimals or leading zeros.');
        }
    }
}
