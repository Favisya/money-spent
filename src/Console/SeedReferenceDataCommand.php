<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\CurrencyRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed', description: 'Upsert currencies and categories from config/packages/reference_data.yaml')]
final class SeedReferenceDataCommand extends Command
{
    /**
     * @param list<array{code: string, exponent: int, aliases: list<string>}>              $currencies
     * @param list<array{code: string, title: string, keywords: list<string>}> $categories
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly CurrencyRepository $currencyRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly array $currencies,
        private readonly array $categories,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connection->transactional(function (): void {
            foreach ($this->currencies as $currency) {
                $this->currencyRepository->upsert($currency['code'], $currency['exponent'], $currency['aliases']);
            }

            foreach ($this->categories as $order => $category) {
                $this->categoryRepository->insertIfMissing(
                    $category['code'],
                    $category['title'],
                    $category['keywords'],
                    $order,
                );
            }
        });

        $output->writeln(sprintf(
            'Seeded %d currencies and %d categories.',
            count($this->currencies),
            count($this->categories),
        ));

        return Command::SUCCESS;
    }
}
