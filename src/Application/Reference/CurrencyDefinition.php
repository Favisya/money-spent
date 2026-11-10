<?php

declare(strict_types=1);

namespace App\Application\Reference;

use App\Domain\Money\Currency;

final readonly class CurrencyDefinition
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public Currency $currency,
        public array $aliases,
        public bool $active = true,
    ) {
    }
}
