<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Recurring\RecurringExpenses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:recurring:run',
    description: 'Book monthly recurring expenses that are due, catching up missed months',
)]
final class RunRecurringExpensesCommand extends Command
{
    public function __construct(
        private readonly RecurringExpenses $recurring,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Booked recurring expenses: %d', $this->recurring->run()));

        return Command::SUCCESS;
    }
}
