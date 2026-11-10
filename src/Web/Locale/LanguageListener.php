<?php

declare(strict_types=1);

namespace App\Web\Locale;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Language of the page: the one picked in the switch, else the browser's (the system language in the app).
 *
 * Runs before Symfony's LocaleAwareListener (priority 15) so the translator follows the request.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
final readonly class LanguageListener
{
    public const string COOKIE = 'lang';

    /**
     * @param list<string> $languages
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private array $languages,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $chosen  = $request->cookies->getString(self::COOKIE);

        $request->setLocale(
            $this->isSupported($chosen) ? $chosen : (string) $request->getPreferredLanguage($this->languages),
        );
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
}
