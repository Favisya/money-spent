<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Participant\Participant;
use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Money sent by a participant either to the other participant (settles the debt) or to someone outside the ledger.
 */
final readonly class TransferIntent
{
    public function __construct(
        public Participant $from,
        public ?Participant $to,
        public ?string $outsider,
        public Money $amount,
        public DateTimeImmutable $date,
        public ?string $note,
    ) {
    }

    public function isBetweenParticipants(): bool
    {
        return $this->to !== null;
    }

    public function recipientName(): string
    {
        return $this->to->name ?? (string) $this->outsider;
    }
}
