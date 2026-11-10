<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\UserError;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bot texts in the current language. Keys are the Russian source texts (translations/bot.*.yaml), so a missing
 * translation shows the Russian original; %placeholders% are filled from the parameters.
 */
final readonly class BotTranslator
{
    public const string DOMAIN = 'bot';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function text(string $key, array $parameters = [], ?string $locale = null): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN, $locale);
    }

    /**
     * Business errors are shared with the web app and live in the default domain.
     */
    public function error(UserError $error): string
    {
        return $this->translator->trans($error->key, $error->parameters);
    }

    public function locale(): string
    {
        return $this->translator->getLocale();
    }
}
