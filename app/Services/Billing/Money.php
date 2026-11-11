<?php

namespace App\Services\Billing;

final class Money
{
    public static function cents(string $amount): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)) {
            throw new \InvalidArgumentException('Expected a nonnegative decimal currency amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function signedTotal(iterable $records, string $field = 'amount'): int
    {
        $sum = 0;
        foreach ($records as $record) {
            $sign = ($record->entry_type ?? null) === 'reversal' || ($record->direction ?? null) === 'decrease' ? -1 : 1;
            $sum += $sign * self::cents((string) $record->{$field});
        }

        return $sum;
    }
}
