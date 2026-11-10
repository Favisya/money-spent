<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class SankeyChart
{
    /**
     * @param list<SankeyNode> $nodes
     * @param list<SankeyFlow> $flows
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $nodes,
        public array $flows,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->flows === [];
    }
}
