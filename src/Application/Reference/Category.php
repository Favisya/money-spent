<?php

declare(strict_types=1);

namespace App\Application\Reference;

final readonly class Category
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $title,
        public array $keywords,
        public bool $active = true,
    ) {
    }
}
