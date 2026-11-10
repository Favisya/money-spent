<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Clock\LocalCalendar;
use App\Application\Rates\PendingRateResolver;
use App\Application\Rates\RateUpdater;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:rates:fetch', description: 'Fetch exchange rates for recent days and resolve pending entries')]
final class FetchRatesCommand extends Command
{
    private const int MAX_DAYS = 366;

    public function __construct(
        private readonly RateUpdater $updater,
        private readonly PendingRateResolver $pending,
        private readonly LocalCalendar $calendar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days back, including today', '1')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Last day to fetch, Y-m-d (default: today)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days    = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $dateArg = $input->getOption('date');
        $last    = is_string($dateArg)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $dateArg)
            : $this->calendar->today();

        if ($days === false || $days > self::MAX_DAYS || $last === false) {
            $output->writeln(sprintf('<error>Use --days=1..%d and --date=Y-m-d.</error>', self::MAX_DAYS));

            return Command::INVALID;
        }

        $result = $this->updater->updateDays($last, $days);

        foreach ($result->storedByDate as $date => $stored) {
            $output->writeln(sprintf('%s: %d new rates', $date, $stored));
        }

        foreach ($result->errorsByDate as $date => $error) {
            $output->writeln(sprintf('<error>%s: %s</error>', $date, $error));
        }

        $output->writeln(sprintf('Resolved pending entries: %d', $this->pending->resolve()));

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }
}
