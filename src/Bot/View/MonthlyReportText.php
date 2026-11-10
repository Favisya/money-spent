<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Report\MonthlyReport;

final readonly class MonthlyReportText
{
    public function __construct(
        private MoneyFormatter $money,
        private MonthNames $months,
    ) {
    }

    public function render(MonthlyReport $report): string
    {
        $title = $this->months->label($report->period);

        if ($report->isEmpty()) {
            return sprintf('📊 <b>%s</b>: трат нет.', $title);
        }

        $lines = [
            sprintf('📊 <b>%s</b>', $title),
            sprintf('Всего: <b>%s</b>', $this->money->format($report->total)),
            '',
            '<b>По категориям</b>',
        ];

        foreach ($report->categories as $category) {
            $lines[] = sprintf(
                '• %s — %s (%d)',
                Html::escape($category->title),
                $this->money->format($category->amount),
                $category->count,
            );
        }

        $lines[] = '';
        $lines[] = '<b>По участникам</b>';

        foreach ($report->participants as $total) {
            $lines[] = sprintf(
                '• %s: заплатил(а) %s, доля %s',
                Html::escape($total->participant->name),
                $this->money->format($total->paid),
                $this->money->format($total->share),
            );
        }

        if ($report->pendingCount > 0) {
            $lines[] = '';
            $lines[] = sprintf('⚠️ Трат без курса, не вошли в итог: %d.', $report->pendingCount);
        }

        return implode("\n", $lines);
    }
}
