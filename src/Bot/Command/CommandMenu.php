<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Bot\Locale\BotLanguage;
use App\Bot\Telegram\TelegramAdminClient;
use App\Bot\Telegram\TelegramApiException;
use App\Bot\View\BotTranslator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Publishes the "/" menu in Telegram: one list per Telegram language the bot answers in its own language, and the
 * fallback language for everybody else. A language picked with /language does not change the menu.
 */
final readonly class CommandMenu
{
    /**
     * @param iterable<BotCommand> $commands
     */
    public function __construct(
        #[AutowireIterator(BotCommand::TAG)]
        private iterable $commands,
        private TelegramAdminClient $telegram,
        private BotLanguage $languages,
        private BotTranslator $translator,
    ) {
    }

    /**
     * @throws TelegramApiException
     */
    public function publish(): void
    {
        $this->telegram->setMyCommands($this->menu($this->languages->fallback()));

        foreach ($this->languages->telegramLanguages() as $telegramLanguage => $language) {
            $this->telegram->setMyCommands($this->menu($language), $telegramLanguage);
        }
    }

    /**
     * @return array<string, string>
     */
    private function menu(string $language): array
    {
        $menu = [];

        foreach ($this->commands as $command) {
            $menu[$command->name()] = $this->translator->text($command->description(), [], $language);
        }

        return $menu;
    }
}
