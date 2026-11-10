<?php

declare(strict_types=1);

namespace App\Application\Import;

final readonly class ImportResult
{
    public function __construct(
        public int $imported,
        public int $duplicates,
        public int $converted = 0,
        public int $notesFilled = 0,
    ) {
    }
}
