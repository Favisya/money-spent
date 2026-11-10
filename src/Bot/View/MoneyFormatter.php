<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Domain\Money\Money;

final class MoneyFormatter
{
    private const string THOUSANDS = "\u{00A0}";

    public function format(Money $money): string
    {
        return $this->number($money) . ' ' . $money->currency->code;
    }

    public function number(Money $money): string
    {
        $decimal  = $money->toDecimal();
        $sign     = str_starts_with($decimal, '-') ? '-' : '';
        $unsigned = ltrim($decimal, '-');
        $parts    = explode('.', $unsigned, 2);
        $integral = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', self::THOUSANDS, $parts[0]);

        return $sign . $integral . (isset($parts[1]) ? '.' . $parts[1] : '');
    }

    public function rate(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
