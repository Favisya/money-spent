<?php

declare(strict_types=1);

namespace App\Infrastructure\Intl;

use App\Application\Currency\CurrencyStandard;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Exception\MissingResourceException;

/**
 * Currency data bundled with symfony/intl, so it works without the intl extension.
 */
final readonly class IntlCurrencyStandard implements CurrencyStandard
{
    public function exists(string $code): bool
    {
        return Currencies::exists($code);
    }

    public function fractionDigits(string $code): int
    {
        return Currencies::getFractionDigits($code);
    }

    public function name(string $code, string $locale): string
    {
        try {
            return Currencies::getName($code, $locale);
        } catch (MissingResourceException) {
            return $code;
        }
    }

    public function names(string $locale): array
    {
        return Currencies::getNames($locale);
    }
}
