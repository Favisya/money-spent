<?php

declare(strict_types=1);

namespace App\Web\Analytics;

use App\Application\Analytics\SpendingAnalysis;
use App\Web\Chart\ChordChart;
use App\Web\Chart\DonutSegment;
use App\Web\Chart\SankeyChart;

final readonly class AnalyticsView
{
    /**
     * @param list<DonutSegment>    $donut
     * @param array<string, string> $ownerColors    owner key => CSS color
     * @param array<int, string>    $categoryColors category id => CSS color
     * @param list<list<HeatCell>>  $calendar       weeks, Monday first; empty for long periods
     * @param array<string, int>    $presets        label => month count
     */
    public function __construct(
        public SpendingAnalysis $analysis,
        public SankeyChart $sankey,
        public ChordChart $chord,
        public array $donut,
        public array $ownerColors,
        public array $categoryColors,
        public array $calendar,
        public int $monthMax,
        public int $weekdayMax,
        public int $cellMax,
        public array $presets,
    ) {
    }

    public function ownerColor(string $key): string
    {
        return $this->ownerColors[$key] ?? 'var(--muted)';
    }

    public function categoryColor(int $id): string
    {
        return $this->categoryColors[$id] ?? 'var(--muted)';
    }

    public function percentOfMonthMax(int $minor): float
    {
        return $this->monthMax > 0 ? round(100 * max(0, $minor) / $this->monthMax, 2) : 0.0;
    }

    public function percentOfWeekdayMax(int $minor): float
    {
        return $this->weekdayMax > 0 ? round(100 * max(0, $minor) / $this->weekdayMax, 2) : 0.0;
    }
}
