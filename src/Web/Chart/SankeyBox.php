<?php

declare(strict_types=1);

namespace App\Web\Chart;

/**
 * A placed sankey node with cursors for the next outgoing and incoming link edge.
 */
final class SankeyBox
{
    private float $outCursor;
    private float $inCursor;

    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly string $color,
    ) {
        $this->outCursor = $y;
        $this->inCursor  = $y;
    }

    public function takeOut(float $thickness): float
    {
        $top = $this->outCursor;
        $this->outCursor += $thickness;

        return $top;
    }

    public function takeIn(float $thickness): float
    {
        $top = $this->inCursor;
        $this->inCursor += $thickness;

        return $top;
    }
}
