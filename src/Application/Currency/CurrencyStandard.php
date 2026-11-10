<?php

declare(strict_types=1);

namespace App\Application\Currency;

/**
 * ISO 4217 reference: which codes exist, their minor units and names.
 */
interface CurrencyStandard
{
    public function exists(string $code): bool;

    public function fractionDigits(string $code): int;

    public function name(string $code, string $locale): string;

    /**
     * @return array<string, string> code => name, sorted by name
     */
    public function names(string $locale): array;
}
