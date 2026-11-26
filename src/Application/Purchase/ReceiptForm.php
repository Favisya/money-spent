<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Split\SplitType;

/**
 * The reviewed receipt as the person confirmed it, ready to become an expense.
 */
final readonly class ReceiptForm
{
    /**
     * @param int|null $payerId null means both paid, half each
     */
    public function __construct(
        public ReceiptContent $content,
        public ?int $payerId,
        public SplitType $split,
    ) {
    }

    public function isPaidByBoth(): bool
    {
        return $this->payerId === null;
    }
}
