<?php

declare(strict_types=1);

namespace App\Web\Chart;

/**
 * Input node for a chord or sankey layout; color is a CSS value such as "var(--owner-1)".
 */
final readonly class ChartNode
{
    public function __construct(
        public string $key,
        public string $label,
        public string $color,
    ) {
    }
}
