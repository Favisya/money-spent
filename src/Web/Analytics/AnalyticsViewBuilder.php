<?php

declare(strict_types=1);

namespace App\Web\Analytics;

use App\Application\Analytics\Owner;
use App\Application\Analytics\SpendingAnalysis;
use App\Web\Chart\ChartLink;
use App\Web\Chart\ChartNode;
use App\Web\Chart\ChordChart;
use App\Web\Chart\ChordLayout;
use App\Web\Chart\DonutLayout;
use App\Web\Chart\DonutSegment;
use App\Web\Chart\SankeyChart;
use App\Web\Chart\SankeyLayout;
use App\Web\People\PeoplePalette;
use DateTimeImmutable;

final readonly class AnalyticsViewBuilder
{
    private const int CATEGORY_COLORS = 8;
    private const int CALENDAR_MONTHS = 12;
    private const int HEAT_LEVELS     = 4;

    /** @var array<string, int> */
    private const array PRESETS = [
        'Месяц'   => 1,
        '3 мес.'  => 3,
        'Полгода' => 6,
        'Год'     => 12,
    ];

    public function __construct(
        private SankeyLayout $sankey,
        private ChordLayout $chord,
        private DonutLayout $donut,
        private PeoplePalette $people,
    ) {
    }

    public function build(SpendingAnalysis $analysis): AnalyticsView
    {
        $ownerColors    = [];
        $categoryColors = [];

        foreach ($analysis->owners as $owner) {
            $ownerColors[$owner->key] = $this->people->ownerCss($owner);
        }

        foreach (array_values($analysis->categories) as $index => $category) {
            $categoryColors[$category->id] = sprintf('var(--cat-%d)', $index % self::CATEGORY_COLORS);
        }

        $monthTotals = array_map(static fn (array $owners): int => array_sum($owners), $analysis->monthOwner);

        return new AnalyticsView(
            $analysis,
            $this->sankeyChart($analysis, $ownerColors, $categoryColors),
            $this->chordChart($analysis, $ownerColors, $categoryColors),
            $this->donutChart($analysis, $categoryColors),
            $ownerColors,
            $categoryColors,
            $this->calendar($analysis),
            max([0, ...array_values($monthTotals)]),
            max([0, ...array_values($analysis->weekdays)]),
            max([1, ...array_merge(...array_values(array_map(array_values(...), $analysis->monthCategory)))]),
            self::PRESETS,
        );
    }

    /**
     * @param array<string, string> $ownerColors
     * @param array<int, string>    $categoryColors
     */
    private function sankeyChart(
        SpendingAnalysis $analysis,
        array $ownerColors,
        array $categoryColors,
    ): SankeyChart {
        $payers     = [];
        $owners     = [];
        $categories = [];
        $links      = [];

        foreach ($analysis->owners as $owner) {
            $owners[] = new ChartNode('o:' . $owner->key, $owner->label, $ownerColors[$owner->key]);

            if ($owner->participantId !== null) {
                $payers[] = new ChartNode('p:' . $owner->participantId, $owner->label, $ownerColors[$owner->key]);
            }
        }

        foreach ($analysis->payerOwner as $payerId => $byOwner) {
            foreach ($byOwner as $ownerKey => $value) {
                $links[] = new ChartLink('p:' . $payerId, 'o:' . $ownerKey, $value, $ownerColors[$ownerKey] ?? null);
            }
        }

        foreach ($analysis->categoryTotals as $categoryId => $total) {
            $category = $analysis->categories[$categoryId] ?? null;

            if ($category !== null && $total > 0) {
                $categories[] = new ChartNode('c:' . $categoryId, $category->title, $categoryColors[$categoryId]);
            }
        }

        foreach ($analysis->ownerCategory as $ownerKey => $byCategory) {
            foreach ($byCategory as $categoryId => $value) {
                $links[] = new ChartLink('o:' . $ownerKey, 'c:' . $categoryId, $value);
            }
        }

        return $this->sankey->layout([$payers, $owners, $categories], $links);
    }

    /**
     * Owners on one side of the circle, categories on the other, ribbons colored by owner.
     *
     * @param array<string, string> $ownerColors
     * @param array<int, string>    $categoryColors
     */
    private function chordChart(
        SpendingAnalysis $analysis,
        array $ownerColors,
        array $categoryColors,
    ): ChordChart {
        $nodes = array_map(
            static fn (Owner $owner): ChartNode => new ChartNode(
                'o:' . $owner->key,
                $owner->label,
                $ownerColors[$owner->key],
            ),
            $analysis->owners,
        );
        $links = [];

        foreach (array_keys($analysis->categoryTotals) as $categoryId) {
            $category = $analysis->categories[$categoryId] ?? null;

            if ($category !== null) {
                $nodes[] = new ChartNode('c:' . $categoryId, $category->title, $categoryColors[$categoryId]);
            }
        }

        foreach ($analysis->ownerCategory as $ownerKey => $byCategory) {
            foreach ($byCategory as $categoryId => $value) {
                $links[] = new ChartLink('o:' . $ownerKey, 'c:' . $categoryId, $value);
            }
        }

        return $this->chord->layout($nodes, $links);
    }

    /**
     * @param array<int, string> $categoryColors
     *
     * @return list<DonutSegment>
     */
    private function donutChart(SpendingAnalysis $analysis, array $categoryColors): array
    {
        $parts = [];

        foreach ($analysis->categoryTotals as $categoryId => $total) {
            $category = $analysis->categories[$categoryId] ?? null;

            if ($category !== null) {
                $node    = new ChartNode((string) $categoryId, $category->title, $categoryColors[$categoryId]);
                $parts[] = [$node, $total];
            }
        }

        return $this->donut->layout($parts);
    }

    /**
     * @return list<list<HeatCell>>
     */
    private function calendar(SpendingAnalysis $analysis): array
    {
        $period = $analysis->period;

        if ($period->monthCount() > self::CALENDAR_MONTHS) {
            return [];
        }

        $max   = max([0, ...array_values($analysis->days)]);
        $start = $period->start();
        $end   = $period->endExclusive();
        $day   = $start->modify(sprintf('-%d days', (int) $start->format('N') - 1));
        $weeks = [];

        while ($day < $end) {
            $week = [];

            for ($i = 0; $i < 7; ++$i) {
                $week[] = $this->cell($day, $analysis->days[$day->format('Y-m-d')] ?? 0, $max, $start, $end);
                $day    = $day->modify('+1 day');
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    private function cell(
        DateTimeImmutable $day,
        int $total,
        int $max,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): HeatCell {
        $level = $total > 0 && $max > 0 ? max(1, (int) ceil(self::HEAT_LEVELS * $total / $max)) : 0;

        return new HeatCell($day, $total, $level, $day >= $start && $day < $end);
    }
}
