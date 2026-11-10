<?php

declare(strict_types=1);

namespace App\Bot\Locale;

use App\Application\Participant\Participant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Languages;

/**
 * Which language the bot talks in.
 *
 * The language picked with /language wins. Otherwise the Telegram interface language decides: a supported one is
 * used as is; languages of countries where Russian is widely understood get Russian (the default language);
 * any other language gets English. No language at all (old clients, channels) keeps the default.
 */
final readonly class BotLanguage
{
    /** Telegram languages answered in the default language (Russian) rather than English. */
    private const array NEAR_DEFAULT = ['uk', 'be', 'kk', 'ky', 'uz', 'tg', 'hy'];

    private const string INTERNATIONAL = 'en';

    private const string TAG_PATTERN = '/^([a-z]{2,3})(?:[-_][a-z0-9]{1,8})*$/';

    /**
     * @param list<string> $languages
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private array $languages,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLanguage,
    ) {
    }

    public function forParticipant(Participant $participant, ?string $telegramLanguage = null): string
    {
        if ($participant->locale !== null && $this->isSupported($participant->locale)) {
            return $participant->locale;
        }

        return $this->fromTelegram($telegramLanguage);
    }

    /**
     * @param string|null $tag untrusted language_code from a Telegram update
     */
    public function fromTelegram(?string $tag): string
    {
        if ($tag === null || preg_match(self::TAG_PATTERN, strtolower(trim($tag)), $match) !== 1) {
            return $this->defaultLanguage;
        }

        return $this->telegramLanguages()[$match[1]] ?? $this->fallback();
    }

    public function isSupported(string $language): bool
    {
        return in_array($language, $this->languages, true);
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return $this->languages;
    }

    /**
     * The language's own name, e.g. "English", "Русский".
     */
    public function name(string $language): string
    {
        $name = Languages::exists($language) ? Languages::getName($language, $language) : $language;

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /**
     * @return array<string, string> Telegram language code => bot language, for every code with a dedicated answer
     */
    public function telegramLanguages(): array
    {
        $map = array_fill_keys(self::NEAR_DEFAULT, $this->defaultLanguage);

        foreach ($this->languages as $language) {
            $map[$language] = $language;
        }

        return $map;
    }

    /**
     * The language for a Telegram language not listed in telegramLanguages().
     */
    public function fallback(): string
    {
        return $this->isSupported(self::INTERNATIONAL) ? self::INTERNATIONAL : $this->defaultLanguage;
    }
}
