<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Money\Currency;
use App\Domain\Money\InvalidAmountException;
use App\Domain\Money\Money;

/**
 * Lenient reading of amounts written on receipts and in lists: "1 200", "1,200.50", "18,5", "-200".
 */
final class MoneyText
{
    private const string SPACES = "/[\s\x{00A0}\x{202F}']+/u";

    public static function parse(string $text, Currency $currency): ?Money
    {
        $text = (string) preg_replace(self::SPACES, '', $text);

        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(',', '', $text);
        }

        if ($text === '') {
            return null;
        }

        try {
            return Money::fromDecimal($text, $currency);
        } catch (InvalidAmountException) {
            return null;
        }
    }
}
