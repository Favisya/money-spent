<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Expense\ExpenseRecorder;
use App\Application\Settlement\SettlementRecorder;
use App\Domain\Parsing\ParsedExpense;
use App\Domain\Split\SplitType;

/**
 * Between the two participants a transfer settles the debt. Money sent to anyone else is the sender's personal
 * expense in the "transfers" category, so it shows up in their spending but never in the shared balance.
 */
final readonly class TransferRecorder
{
    private const string CATEGORY = 'transfers';

    public function __construct(
        private SettlementRecorder $settlements,
        private ExpenseRecorder $expenses,
    ) {
    }

    public function record(TransferIntent $transfer, string $uuid): void
    {
        if ($transfer->to !== null) {
            $this->settlements->record(
                $transfer->from,
                $transfer->amount,
                $transfer->date,
                $transfer->note,
                $uuid,
                $transfer->to,
            );

            return;
        }

        $note = trim('→ ' . $transfer->outsider . ($transfer->note === null ? '' : ': ' . $transfer->note));

        $this->expenses->record(
            $transfer->from,
            new ParsedExpense(
                $transfer->amount,
                $transfer->date,
                [self::CATEGORY],
                SplitType::PayerOnly,
                mb_substr($note, 0, 255),
                true,
            ),
            $uuid,
        );
    }
}
