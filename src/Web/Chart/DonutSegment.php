<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class DonutSegment
{
    public function __construct(
        public string $key,
        public string $label,
        public string $color,
        public int $value,
        public float $percent,
        public string $path,
    ) {
    }
}
