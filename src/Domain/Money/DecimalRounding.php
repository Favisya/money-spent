<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Rounds bcmath decimals to whole minor units: half away from zero, with an overflow check.
 */
final class DecimalRounding
{
    public const int WORK_SCALE = 20;

    /**
     * @param numeric-string $value
     */
    public static function halfAwayFromZero(string $value): int
    {
        $half    = str_starts_with($value, '-') ? '-0.5' : '0.5';
        $rounded = bcadd(bcadd($value, $half, self::WORK_SCALE), '0', 0);

        if (bccomp($rounded, (string) PHP_INT_MAX, 0) > 0 || bccomp($rounded, (string) PHP_INT_MIN, 0) < 0) {
            throw InvalidAmountException::overflow();
        }

        return (int) $rounded;
    }
}
