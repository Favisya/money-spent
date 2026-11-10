<?php

declare(strict_types=1);

namespace App\Web\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF protection for the session-less web app: state-changing requests must come from our own pages.
 * Browsers send Sec-Fetch-Site / Origin on form posts and a foreign page cannot forge them.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
final class SameOriginGuard
{
    private const array SAFE_METHODS  = ['GET', 'HEAD', 'OPTIONS'];
    private const string WEBHOOK_PATH = '/telegram/';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        if (str_starts_with($request->getPathInfo(), self::WEBHOOK_PATH)) {
            return;
        }

        $site       = $request->headers->get('Sec-Fetch-Site');
        $origin     = $request->headers->get('Origin');
        $sameOrigin = $site !== null
            ? $site === 'same-origin'
            : $origin !== null && $origin === $request->getSchemeAndHttpHost();

        if (!$sameOrigin) {
            $event->setResponse(new Response('Cross-site request rejected.', Response::HTTP_FORBIDDEN));
        }
    }
}
