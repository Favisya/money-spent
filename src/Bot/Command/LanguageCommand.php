<?php

declare(strict_types=1);

namespace App\Bot\Command;

use App\Bot\BotContext;
use App\Bot\Locale\BotLanguage;
use App\Bot\Reply\TextReply;
use App\Bot\View\BotTranslator;
use App\Infrastructure\Persistence\ParticipantRepository;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * "/language" shows the bot language and how to switch, "/language en" picks one for this participant.
 */
final readonly class LanguageCommand implements BotCommand
{
    public function __construct(
        private BotLanguage $languages,
        private ParticipantRepository $participants,
        private LocaleSwitcher $locales,
        private BotTranslator $translator,
    ) {
    }

    public function name(): string
    {
        return 'language';
    }

    public function description(): string
    {
        return 'язык бота: /language en, /language ru';
    }

    public function handle(BotContext $context, string $arguments): array
    {
        $choice = strtolower(trim($arguments));

        if ($choice === '') {
            return [new TextReply($context->chatId, $this->current())];
        }

        if (!$this->languages->isSupported($choice)) {
            return [new TextReply($context->chatId, '⚠️ ' . $this->translator->text(
                'Такого языка нет. Можно так: %options%',
                ['%options%' => $this->options()],
            ))];
        }

        $this->participants->changeLocale($context->participant->id, $choice);

        return $this->locales->runWithLocale(
            $choice,
            fn (): array => [new TextReply($context->chatId, '✅ ' . $this->current())],
        );
    }

    private function current(): string
    {
        return $this->translator->text('Язык бота: %language%. Сменить: %options%', [
            '%language%' => $this->languages->name($this->translator->locale()),
            '%options%'  => $this->options(),
        ]);
    }

    private function options(): string
    {
        return implode(', ', array_map(
            fn (string $language): string => sprintf('/%s %s', $this->name(), $language),
            $this->languages->languages(),
        ));
    }
}
