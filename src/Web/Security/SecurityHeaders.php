<?php

declare(strict_types=1);

namespace App\Web\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class SecurityHeaders
{
    private const array HEADERS = [
        'X-Frame-Options'         => 'DENY',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'",
    ];

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach (self::HEADERS as $name => $value) {
            $event->getResponse()->headers->set($name, $value);
        }
    }
}
