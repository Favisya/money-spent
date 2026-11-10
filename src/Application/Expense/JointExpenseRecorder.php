<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Application\UserError;
use App\Domain\Parsing\ParsedExpense;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\ParticipantRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * "Both paid, half each": one shared expense stored as equal shared parts, one paid by each participant.
 * The totals count the whole amount, and the debts of the parts cancel out.
 */
final readonly class JointExpenseRecorder
{
    /** The payer choice in forms that means "both, half each". */
    public const string BOTH = 'both';

    public function __construct(
        private ExpenseRecorder $recorder,
        private ParticipantRepository $participants,
        private Connection $connection,
    ) {
    }

    /**
     * @param string $uuid of the whole entry; every part gets its own UUID derived from it, so a retry adds nothing
     *
     * @return non-empty-list<RecordedExpense> one per participant, the first one gets an odd minor unit
     */
    public function record(ParsedExpense $parsed, string $uuid, ?int $recurringId = null): array
    {
        $payers = $this->participants->all();

        if (count($payers) < 2) {
            throw new UserError('«Оба» можно выбрать, когда зарегистрированы оба участника.');
        }

        $parts = $parsed->amount->split(count($payers));
        $whole = $parsed->withSplit(SplitType::Equal);

        return $this->connection->transactional(function () use ($payers, $parts, $whole, $uuid, $recurringId): array {
            $recorded = [];

            foreach ($payers as $index => $payer) {
                $recorded[] = $this->recorder->record(
                    $payer,
                    $whole->withAmount($parts[$index]),
                    self::partUuid($uuid, $payer->id),
                    $recurringId,
                );
            }

            return $recorded;
        });
    }

    public static function partUuid(string $uuid, int $payerId): string
    {
        return Uuid::v5(Uuid::fromString($uuid), 'payer:' . $payerId)->toRfc4122();
    }
}
