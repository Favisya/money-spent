<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Report\MonthlyReport;

final readonly class MonthlyReportText
{
    public function __construct(
        private MoneyFormatter $money,
        private MonthNames $months,
        private BotTranslator $translator,
    ) {
    }

    public function render(MonthlyReport $report): string
    {
        $title = $this->months->label($report->period);

        if ($report->isEmpty()) {
            return '📊 ' . $this->translator->text('<b>%month%</b>: трат нет.', ['%month%' => $title]);
        }

        $lines = [
            sprintf('📊 <b>%s</b>', $title),
            $this->translator->text('Всего: <b>%total%</b>', ['%total%' => $this->money->format($report->total)]),
            '',
            '<b>' . $this->translator->text('По категориям') . '</b>',
        ];

        foreach ($report->categories as $category) {
            $lines[] = sprintf(
                '• %s — %s (%d)',
                Html::escape($this->translator->text($category->title)),
                $this->money->format($category->amount),
                $category->count,
            );
        }

        $lines[] = '';
        $lines[] = '<b>' . $this->translator->text('По участникам') . '</b>';

        foreach ($report->participants as $total) {
            $lines[] = '• ' . $this->translator->text('%name%: заплатил(а) %paid%, доля %share%', [
                '%name%'  => Html::escape($total->participant->name),
                '%paid%'  => $this->money->format($total->paid),
                '%share%' => $this->money->format($total->share),
            ]);
        }

        if ($report->pendingCount > 0) {
            $lines[] = '';
            $lines[] = '⚠️ ' . $this->translator->text(
                'Трат без курса, не вошли в итог: %count%.',
                ['%count%' => $report->pendingCount],
            );
        }

        return implode("\n", $lines);
    }
}
