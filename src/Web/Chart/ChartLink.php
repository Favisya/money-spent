<?php

declare(strict_types=1);

namespace App\Web\Chart;

/**
 * A weighted link between two node keys; without a color it takes the color of its source node.
 */
final readonly class ChartLink
{
    public function __construct(
        public string $source,
        public string $target,
        public int $value,
        public ?string $color = null,
    ) {
    }
}
