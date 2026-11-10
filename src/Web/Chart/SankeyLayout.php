<?php

declare(strict_types=1);

namespace App\Web\Chart;

/**
 * Left-to-right flow diagram over fixed columns. Node height is proportional to max(in, out); every link joins two
 * nodes of neighbouring columns and is drawn as a stroke as thick as its value.
 */
final readonly class SankeyLayout
{
    private const float NODE_WIDTH = 10.0;
    private const float GAP        = 14.0;
    private const float LABEL_GAP  = 6.0;

    public function __construct(
        private int $width = 640,
        private int $height = 320,
    ) {
    }

    /**
     * @param list<list<ChartNode>> $columns nodes top to bottom
     * @param list<ChartLink>       $links   non-positive values are dropped
     */
    public function layout(array $columns, array $links): SankeyChart
    {
        $links      = array_values(array_filter($links, static fn (ChartLink $link): bool => $link->value > 0));
        [$in, $out] = $this->throughput($links);

        $columns = array_map(
            static fn (array $nodes): array => array_values(array_filter(
                $nodes,
                static fn (ChartNode $node): bool => max($in[$node->key] ?? 0, $out[$node->key] ?? 0) > 0,
            )),
            $columns,
        );

        $value = static fn (ChartNode $node): int => max($in[$node->key] ?? 0, $out[$node->key] ?? 0);
        $scale = $this->scale($columns, $value);

        if ($scale === null) {
            return new SankeyChart($this->width, $this->height, [], []);
        }

        $step  = count($columns) > 1 ? ($this->width - self::NODE_WIDTH) / (count($columns) - 1) : 0.0;
        $boxes = [];
        $nodes = [];

        foreach ($columns as $index => $column) {
            $used = array_sum(array_map(static fn (ChartNode $node): float => $value($node) * $scale, $column))
                + self::GAP * (count($column) - 1);
            $y    = ($this->height - $used) / 2;
            $x    = $index * $step;
            $last = $index === count($columns) - 1;

            foreach ($column as $node) {
                $height            = $value($node) * $scale;
                $boxes[$node->key] = new SankeyBox($x, $y, $node->color);
                $nodes[]           = new SankeyNode(
                    $node->key,
                    $node->label,
                    $node->color,
                    $value($node),
                    Polar::number($x),
                    Polar::number($y),
                    Polar::number(max(1.0, $height)),
                    Polar::number($last ? $x - self::LABEL_GAP : $x + self::NODE_WIDTH + self::LABEL_GAP),
                    Polar::number($y + $height / 2),
                    $last ? 'end' : 'start',
                );
                $y += $height + self::GAP;
            }
        }

        return new SankeyChart($this->width, $this->height, $nodes, $this->flows($links, $boxes, $scale));
    }

    /**
     * @param list<ChartLink> $links
     *
     * @return array{array<string, int>, array<string, int>}
     */
    private function throughput(array $links): array
    {
        $in  = [];
        $out = [];

        foreach ($links as $link) {
            $out[$link->source] = ($out[$link->source] ?? 0) + $link->value;
            $in[$link->target]  = ($in[$link->target] ?? 0) + $link->value;
        }

        return [$in, $out];
    }

    /**
     * @param list<list<ChartNode>>   $columns
     * @param callable(ChartNode): int $value
     */
    private function scale(array $columns, callable $value): ?float
    {
        $scale = null;

        foreach ($columns as $column) {
            $sum = array_sum(array_map($value, $column));

            if ($sum === 0) {
                continue;
            }

            $fit   = ($this->height - self::GAP * (count($column) - 1)) / $sum;
            $scale = $scale === null ? $fit : min($scale, $fit);
        }

        return $scale;
    }

    /**
     * @param list<ChartLink>          $links
     * @param array<string, SankeyBox> $boxes
     *
     * @return list<SankeyFlow>
     */
    private function flows(array $links, array $boxes, float $scale): array
    {
        $links = array_values(array_filter(
            $links,
            static fn (ChartLink $link): bool => isset($boxes[$link->source], $boxes[$link->target]),
        ));
        usort(
            $links,
            static fn (ChartLink $a, ChartLink $b): int => [$boxes[$a->source]->y, $boxes[$a->target]->y]
                <=> [$boxes[$b->source]->y, $boxes[$b->target]->y],
        );

        $flows = [];

        foreach ($links as $link) {
            $source    = $boxes[$link->source];
            $target    = $boxes[$link->target];
            $thickness = $link->value * $scale;
            $x0        = $source->x + self::NODE_WIDTH;
            $x1        = $target->x;
            $y0        = $source->takeOut($thickness) + $thickness / 2;
            $y1        = $target->takeIn($thickness) + $thickness / 2;
            $middle    = ($x0 + $x1) / 2;
            $flows[]   = new SankeyFlow(
                $link->source,
                $link->target,
                $link->color ?? $source->color,
                $link->value,
                sprintf(
                    'M%s,%s C%s,%s %s,%s %s,%s',
                    Polar::number($x0),
                    Polar::number($y0),
                    Polar::number($middle),
                    Polar::number($y0),
                    Polar::number($middle),
                    Polar::number($y1),
                    Polar::number($x1),
                    Polar::number($y1),
                ),
                Polar::number(max(1.0, $thickness)),
            );
        }

        return $flows;
    }
}
