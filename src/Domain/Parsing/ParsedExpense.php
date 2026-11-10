<?php

declare(strict_types=1);

namespace App\Domain\Parsing;

use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class ParsedExpense
{
    /**
     * @param list<string> $categoryCodes matched candidates; empty when nothing matched, 2+ when ambiguous
     */
    public function __construct(
        public Money $amount,
        public DateTimeImmutable $spentAt,
        public array $categoryCodes,
        public SplitType $split,
        public ?string $note,
        public bool $hasExplicitDate,
    ) {
    }

    public function withSpentAt(DateTimeImmutable $spentAt): self
    {
        return new self($this->amount, $spentAt, $this->categoryCodes, $this->split, $this->note, true);
    }

    public function withSplit(SplitType $split): self
    {
        return new self(
            $this->amount,
            $this->spentAt,
            $this->categoryCodes,
            $split,
            $this->note,
            $this->hasExplicitDate,
        );
    }

    public function withAmount(Money $amount): self
    {
        return new self(
            $amount,
            $this->spentAt,
            $this->categoryCodes,
            $this->split,
            $this->note,
            $this->hasExplicitDate,
        );
    }

    public function withNote(?string $note): self
    {
        return new self(
            $this->amount,
            $this->spentAt,
            $this->categoryCodes,
            $this->split,
            $note,
            $this->hasExplicitDate,
        );
    }

    public function isCategoryAmbiguous(): bool
    {
        return count($this->categoryCodes) > 1;
    }
}
