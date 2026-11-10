<?php

declare(strict_types=1);

namespace App\Application\Recurring;

/**
 * A recurring expense row as typed into its inline editor; nothing here is validated yet.
 */
final readonly class RecurringChange
{
    /**
     * @param string $payer participant id or JointExpenseRecorder::BOTH
     * @param string $split SplitType value as shown for the current payer
     */
    public function __construct(
        public int $dayOfMonth,
        public string $amount,
        public string $currency,
        public string $payer,
        public int $categoryId,
        public string $split,
        public string $note,
    ) {
    }
}
