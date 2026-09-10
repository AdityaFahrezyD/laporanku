<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public const MAX = 999999999999999;

    public static function cents(
        mixed $value,
        string $field = "amount",
        bool $allowZero = false
    ): int {
        $value = (string) $value;
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([
                $field =>
                    "Nominal harus berupa desimal dengan maksimal 13 digit bulat dan 2 digit pecahan.",
            ]);
        }
        [$whole, $fraction] = array_pad(explode(".", $value, 2), 2, "");
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, "0");
        if ($cents > self::MAX || (!$allowZero && $cents === 0)) {
            throw ValidationException::withMessages([
                $field => "Nominal di luar rentang yang diizinkan.",
            ]);
        }

        return $cents;
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100) .
            "." .
            str_pad((string) ($cents % 100), 2, "0", STR_PAD_LEFT);
    }
}
