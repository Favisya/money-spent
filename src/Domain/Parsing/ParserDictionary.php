<?php

declare(strict_types=1);

namespace App\Domain\Parsing;

use App\Domain\Money\Currency;
use App\Domain\Split\SplitType;

final readonly class ParserDictionary
{
    /**
     * @param array<string, Currency>  $currencyAliases lower-case alias or symbol => currency
     * @param list<CategoryKeywords>   $categories      in priority order
     * @param array<string, SplitType> $splitAliases    lower-case word => split
     */
    public function __construct(
        public Currency $defaultCurrency,
        public array $currencyAliases,
        public array $categories,
        public array $splitAliases,
    ) {
    }
}
