<?php

declare(strict_types=1);

namespace App\Web\Chart;

final readonly class ChordChart
{
    /**
     * @param list<ChordGroup>  $groups
     * @param list<ChordRibbon> $ribbons
     */
    public function __construct(
        public float $radius,
        public array $groups,
        public array $ribbons,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->ribbons === [];
    }
}
