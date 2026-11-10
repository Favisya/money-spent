<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class SankeyFlow
{
    public function __construct(
        public string $source,
        public string $target,
        public string $color,
        public int $value,
        public string $path,
        public string $width,
    ) {
    }
}
