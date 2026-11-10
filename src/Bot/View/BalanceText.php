<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Balance\BalanceReport;
use App\Domain\Money\Money;

final readonly class BalanceText
{
    public function __construct(
        private MoneyFormatter $money,
        private BotTranslator $translator,
    ) {
    }

    public function render(BalanceReport $report): string
    {
        if (count($report->participants) < 2) {
            return $this->translator->text('Пока зарегистрирован один участник — второй должен написать боту /start.');
        }

        $lines = [];

        foreach ($report->balance->debts as $debt) {
            $lines[] = '💸 ' . $this->translator->text('<b>%debtor%</b> должен(на) <b>%creditor%</b> %amount%', [
                '%debtor%'   => Html::escape($this->name($report, $debt->debtorId)),
                '%creditor%' => Html::escape($this->name($report, $debt->creditorId)),
                '%amount%'   => $this->money->format(Money::ofMinor($debt->amountBaseMinor, $report->base)),
            ]);
        }

        if ($lines === []) {
            $lines[] = '✅ ' . $this->translator->text('Никто никому не должен.');
        }

        if ($report->pendingCount > 0) {
            $lines[] = '⚠️ ' . $this->translator->text(
                'Без курса и пока не учтено записей: %count%.',
                ['%count%' => $report->pendingCount],
            );
        }

        return implode("\n", $lines);
    }

    private function name(BalanceReport $report, int $participantId): string
    {
        return $report->participants[$participantId]->name ?? '#' . $participantId;
    }
}
