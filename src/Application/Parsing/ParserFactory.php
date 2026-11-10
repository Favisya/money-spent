<?php

declare(strict_types=1);

namespace App\Application\Parsing;

use App\Application\Reference\Category;
use App\Application\Reference\CurrencyCatalog;
use App\Domain\Parsing\CategoryKeywords;
use App\Domain\Parsing\ExpenseParser;
use App\Domain\Parsing\ParserDictionary;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\CategoryRepository;

final readonly class ParserFactory
{
    /**
     * @param array<string, string> $splitAliases word => split type value
     */
    public function __construct(
        private CurrencyCatalog $currencies,
        private CategoryRepository $categories,
        private array $splitAliases,
    ) {
    }

    public function create(): ExpenseParser
    {
        return new ExpenseParser(new ParserDictionary(
            $this->currencies->base(),
            $this->currencies->aliases(),
            array_map(
                static fn (Category $category): CategoryKeywords => new CategoryKeywords(
                    $category->code,
                    $category->keywords,
                ),
                $this->categories->findActive(),
            ),
            array_map(
                static fn (string $split): SplitType => SplitType::from($split),
                $this->splitAliases,
            ),
        ));
    }
}
