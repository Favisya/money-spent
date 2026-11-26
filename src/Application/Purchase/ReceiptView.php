<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class ReceiptView
{
    /**
     * @param list<int> $expenseIds active expenses recorded from this receipt
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public ReceiptStatus $status,
        public ReceiptSource $source,
        public ?AiProvider $provider,
        public ReceiptContent $content,
        public ?int $payerId,
        public bool $paidByBoth,
        public SplitType $split,
        public ?string $sourceText,
        public int $imageCount,
        public DateTimeImmutable $createdAt,
        public array $expenseIds,
    ) {
    }

    public function hasImages(): bool
    {
        return $this->imageCount > 0;
    }

    public function isDraft(): bool
    {
        return $this->status === ReceiptStatus::Draft;
    }
}
