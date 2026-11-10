<?php

declare(strict_types=1);

namespace App\Web\Chart;

/**
 * Chord diagram: every node is an arc sized by the links touching it, every link is a ribbon between two arcs.
 */
final readonly class ChordLayout
{
    private const float PADDING = 0.04;
    private const float BAND    = 14.0;
    private const float LABEL   = 10.0;

    public function __construct(
        private float $radius = 150.0,
    ) {
    }

    /**
     * @param list<ChartNode> $nodes in circle order, clockwise from twelve o'clock
     * @param list<ChartLink> $links non-positive values are dropped
     */
    public function layout(array $nodes, array $links): ChordChart
    {
        $links  = array_values(array_filter($links, static fn (ChartLink $link): bool => $link->value > 0));
        $values = array_fill_keys(array_map(static fn (ChartNode $node): string => $node->key, $nodes), 0);

        foreach ($links as $link) {
            $values[$link->source] += $link->value;
            $values[$link->target] += $link->value;
        }

        $nodes = array_values(array_filter($nodes, static fn (ChartNode $node): bool => $values[$node->key] > 0));
        $sum   = array_sum($values);

        if ($sum === 0) {
            return new ChordChart($this->radius, [], []);
        }

        $padding   = count($nodes) > 1 ? self::PADDING : 0.0;
        $available = 2 * M_PI - $padding * count($nodes);
        $angle     = $padding / 2;
        $cursor    = [];
        $groups    = [];
        $colors    = [];

        foreach ($nodes as $node) {
            $span               = $available * $values[$node->key] / $sum;
            $cursor[$node->key] = $angle;
            $colors[$node->key] = $node->color;
            $groups[]           = $this->group($node, $values[$node->key], $angle, $angle + $span);
            $angle += $span + $padding;
        }

        $ribbons = [];

        foreach ($links as $link) {
            $sourceFrom = $cursor[$link->source];
            $sourceTo   = $sourceFrom + $available * $link->value / $sum;
            $targetFrom = $cursor[$link->target];
            $targetTo   = $targetFrom + $available * $link->value / $sum;

            $cursor[$link->source] = $sourceTo;
            $cursor[$link->target] = $targetTo;

            $ribbons[] = new ChordRibbon(
                $link->source,
                $link->target,
                $link->color ?? $colors[$link->source],
                $link->value,
                $this->ribbon($sourceFrom, $sourceTo, $targetFrom, $targetTo),
            );
        }

        return new ChordChart($this->radius, $groups, $ribbons);
    }

    private function group(ChartNode $node, int $value, float $from, float $to): ChordGroup
    {
        $middle = ($from + $to) / 2;
        $outer  = $this->radius + self::BAND;

        return new ChordGroup(
            $node->key,
            $node->label,
            $node->color,
            $value,
            Polar::band($from, $to, $this->radius + 2, $outer),
            Polar::x($middle, $outer + self::LABEL),
            Polar::y($middle, $outer + self::LABEL),
            match (true) {
                abs(sin($middle)) < 0.15 => 'middle',
                sin($middle) > 0         => 'start',
                default                  => 'end',
            },
        );
    }

    private function ribbon(float $sourceFrom, float $sourceTo, float $targetFrom, float $targetTo): string
    {
        $r = $this->radius;

        return sprintf(
            'M%s %s Q0,0 %s %s Q0,0 %s Z',
            Polar::point($sourceFrom, $r),
            Polar::arcTo($sourceFrom, $sourceTo, $r),
            Polar::point($targetFrom, $r),
            Polar::arcTo($targetFrom, $targetTo, $r),
            Polar::point($sourceFrom, $r),
        );
    }
}
