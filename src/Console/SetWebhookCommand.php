<?php

declare(strict_types=1);

namespace App\Console;

use App\Bot\Command\CommandMenu;
use App\Bot\Telegram\TelegramAdminClient;
use App\Bot\Telegram\TelegramApiException;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:telegram:set-webhook',
    description: 'Register the public HTTPS webhook URL and the command menu in Telegram',
)]
final class SetWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramAdminClient $telegram,
        #[SensitiveParameter]
        private readonly string $webhookSecret,
        private readonly CommandMenu $menu,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('base-url', InputArgument::REQUIRED, 'Public HTTPS origin, e.g. https://bot.example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseUrl = (string) $input->getArgument('base-url');

        if (!str_starts_with($baseUrl, 'https://') || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $output->writeln('<error>Telegram requires a valid https:// URL.</error>');

            return Command::INVALID;
        }

        if ($this->webhookSecret === '') {
            $output->writeln('<error>Set TELEGRAM_WEBHOOK_SECRET first.</error>');

            return Command::INVALID;
        }

        try {
            $this->telegram->setWebhook(rtrim($baseUrl, '/') . '/telegram/webhook', $this->webhookSecret);
            $this->menu->publish();
        } catch (TelegramApiException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln('Webhook and command menu registered.');

        return Command::SUCCESS;
    }
}
