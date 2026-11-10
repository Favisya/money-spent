<?php

declare(strict_types=1);

namespace App\Application\Settlement;

use App\Application\Participant\Counterpart;
use App\Application\Participant\Participant;
use App\Application\Rates\RateResolver;
use App\Application\UserError;
use App\Domain\Money\Money;
use App\Infrastructure\Persistence\SettlementRepository;
use DateTimeImmutable;

final readonly class SettlementRecorder
{
    public function __construct(
        private SettlementRepository $settlements,
        private Counterpart $counterpart,
        private RateResolver $rates,
    ) {
    }

    public function record(
        Participant $from,
        Money $amount,
        DateTimeImmutable $settledAt,
        ?string $note,
        string $uuid,
        ?Participant $to = null,
    ): RecordedSettlement {
        if (!$amount->isPositive()) {
            throw new UserError('Сумма перевода должна быть больше нуля.');
        }

        $to ??= $this->counterpart->of($from);

        if ($to->id === $from->id) {
            throw new UserError('Перевод самому себе не записываю.');
        }

        $draft = new SettlementDraft(
            $uuid,
            $from->id,
            $to->id,
            $amount,
            $this->rates->find($amount->currency, $settledAt),
            $settledAt,
            $note,
        );

        $this->settlements->insert($draft);

        return new RecordedSettlement($draft, $to);
    }
}
