<?php

declare(strict_types=1);

namespace App\Application\Rates;

/**
 * Where the rates shown next to the display currency come from. Expenses are always converted to AMD at the official
 * CBA rate of their date; this choice only affects how totals are shown in another currency.
 */
enum RateSourceChoice: string
{
    case Cba = 'cba';
    case Cbr = 'cbr';

    public function label(): string
    {
        return match ($this) {
            self::Cba => 'ЦБ Армении',
            self::Cbr => 'ЦБ России',
        };
    }
}
