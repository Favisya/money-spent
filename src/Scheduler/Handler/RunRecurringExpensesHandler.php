<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Application\Recurring\RecurringExpenses;
use App\Scheduler\Message\RunRecurringExpenses;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunRecurringExpensesHandler
{
    public function __construct(
        private RecurringExpenses $recurring,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunRecurringExpenses $message): void
    {
        $booked = $this->recurring->run();

        if ($booked > 0) {
            $this->logger->info('Recurring expenses booked', ['count' => $booked]);
        }
    }
}
