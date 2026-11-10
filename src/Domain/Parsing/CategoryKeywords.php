<?php

declare(strict_types=1);

namespace App\Domain\Parsing;

final readonly class CategoryKeywords
{
    /**
     * @param list<string> $keywords stems; a keyword of 4+ letters also matches words starting with it
     */
    public function __construct(
        public string $code,
        public array $keywords,
    ) {
    }
}
