<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class ChordGroup
{
    public function __construct(
        public string $key,
        public string $label,
        public string $color,
        public int $value,
        public string $path,
        public string $labelX,
        public string $labelY,
        public string $anchor,
    ) {
    }
}
