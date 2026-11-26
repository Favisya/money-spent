<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Money\Currency;
use DateTimeImmutable;

/**
 * What the reader and the normalizer know besides the receipt itself.
 */
final readonly class ReadingContext
{
    /**
     * @param array<string, Currency> $currencies known currencies by code
     * @param string                  $language   language for item names: "ru" or "en"
     */
    public function __construct(
        public DateTimeImmutable $today,
        public Currency $defaultCurrency,
        public array $currencies,
        public string $language,
    ) {
    }
}
