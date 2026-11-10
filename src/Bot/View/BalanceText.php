<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Balance\BalanceReport;
use App\Domain\Money\Money;

final readonly class BalanceText
{
    public function __construct(
        private MoneyFormatter $money,
    ) {
    }

    public function render(BalanceReport $report): string
    {
        if (count($report->participants) < 2) {
            return 'Пока зарегистрирован один участник — второй должен написать боту /start.';
        }

        $lines = [];

        foreach ($report->balance->debts as $debt) {
            $lines[] = sprintf(
                '💸 <b>%s</b> должен(на) <b>%s</b> %s',
                Html::escape($this->name($report, $debt->debtorId)),
                Html::escape($this->name($report, $debt->creditorId)),
                $this->money->format(Money::ofMinor($debt->amountBaseMinor, $report->base)),
            );
        }

        if ($lines === []) {
            $lines[] = '✅ Никто никому не должен.';
        }

        if ($report->pendingCount > 0) {
            $lines[] = sprintf('⚠️ Без курса и пока не учтено записей: %d.', $report->pendingCount);
        }

        return implode("\n", $lines);
    }

    private function name(BalanceReport $report, int $participantId): string
    {
        return $report->participants[$participantId]->name ?? '#' . $participantId;
    }
}
