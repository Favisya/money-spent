<?php

declare(strict_types=1);

namespace App\Console;

use App\Bot\Command\CommandMenu;
use App\Bot\Telegram\TelegramAdminClient;
use App\Bot\Telegram\TelegramApiException;
use App\Bot\Telegram\Update;
use App\Bot\UpdateProcessor;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'app:telegram:poll', description: 'Long-polling alternative to the webhook for local development')]
final class PollUpdatesCommand extends Command
{
    private const int POLL_TIMEOUT = 25;
    private const int ERROR_PAUSE  = 5;

    public function __construct(
        private readonly TelegramAdminClient $telegram,
        private readonly UpdateProcessor $processor,
        private readonly LoggerInterface $logger,
        private readonly CommandMenu $menu,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after N seconds', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deadline = time() + max(1, (int) $input->getOption('time-limit'));
        $offset   = 0;

        $this->telegram->deleteWebhook();
        $this->menu->publish();
        $output->writeln('Polling Telegram updates...');

        while (time() < $deadline) {
            try {
                $updates = $this->telegram->getUpdates($offset, self::POLL_TIMEOUT);
            } catch (TelegramApiException $exception) {
                $this->logger->error('getUpdates failed', ['error' => $exception->getMessage()]);
                sleep(self::ERROR_PAUSE);

                continue;
            }

            foreach ($updates as $payload) {
                $offset = max($offset, $this->handle($payload) + 1);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return int processed update id, -1 when the payload is unusable
     */
    private function handle(array $payload): int
    {
        try {
            $update = Update::fromArray($payload);
        } catch (InvalidArgumentException) {
            return -1;
        }

        try {
            $this->processor->process($update);
        } catch (Throwable $exception) {
            $this->logger->error('Update processing failed', [
                'update_id' => $update->updateId,
                'error'     => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $update->updateId;
    }
}
