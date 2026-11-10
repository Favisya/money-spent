<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class SankeyNode
{
    public function __construct(
        public string $key,
        public string $label,
        public string $color,
        public int $value,
        public string $x,
        public string $y,
        public string $height,
        public string $labelX,
        public string $labelY,
        public string $anchor,
    ) {
    }
}
