<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class DonutLayout
{
    private const float GAP = 0.025;

    public function __construct(
        private float $radius = 90.0,
        private float $thickness = 22.0,
    ) {
    }

    /**
     * @param list<array{ChartNode, int}> $parts node and value; non-positive values are dropped
     *
     * @return list<DonutSegment>
     */
    public function layout(array $parts): array
    {
        $parts = array_values(array_filter($parts, static fn (array $part): bool => $part[1] > 0));
        $sum   = array_sum(array_map(static fn (array $part): int => $part[1], $parts));

        if ($sum === 0) {
            return [];
        }

        $gap      = count($parts) > 1 ? self::GAP : 0.0;
        $angle    = 0.0;
        $segments = [];

        foreach ($parts as [$node, $value]) {
            $span       = 2 * M_PI * $value / $sum;
            $from       = $angle + $gap / 2;
            $to         = max($from + 0.001, min($angle + $span - $gap / 2, $angle + 2 * M_PI - 0.0001));
            $segments[] = new DonutSegment(
                $node->key,
                $node->label,
                $node->color,
                $value,
                round(100 * $value / $sum, 1),
                Polar::band($from, $to, $this->radius - $this->thickness, $this->radius),
            );
            $angle += $span;
        }

        return $segments;
    }
}
