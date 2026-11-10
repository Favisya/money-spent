<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Application\Sharing\Pairing;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The host Mac itself (loopback) is trusted as before. Anyone reaching the app over the network needs a paired device
 * token; the sharing settings stay reachable from the host only.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 32)]
final readonly class DeviceGuard
{
    public const string COOKIE    = 'device';
    public const string ATTRIBUTE = '_device';

    private const array LOOPBACK      = ['127.0.0.1', '::1'];
    private const array OPEN_PATHS    = ['/pair', '/health'];
    private const string WEBHOOK_PATH = '/telegram/';
    private const string HOST_ONLY    = '/sharing';

    public function __construct(
        private Pairing $pairing,
    ) {
    }

    public static function isLocal(Request $request): bool
    {
        return in_array($request->server->getString('REMOTE_ADDR'), self::LOOPBACK, true);
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || self::isLocal($request)) {
            return;
        }

        $path = $request->getPathInfo();

        if (in_array($path, self::OPEN_PATHS, true) || str_starts_with($path, self::WEBHOOK_PATH)) {
            return;
        }

        $device = $this->pairing->authenticate($request->cookies->getString(self::COOKIE));

        if ($device === null) {
            $event->setResponse($request->isMethodSafe()
                ? new RedirectResponse('/pair')
                : new Response('Device is not paired.', Response::HTTP_UNAUTHORIZED));

            return;
        }

        if (str_starts_with($path, self::HOST_ONLY)) {
            $event->setResponse(new Response('Only on the host Mac.', Response::HTTP_FORBIDDEN));

            return;
        }

        $request->attributes->set(self::ATTRIBUTE, $device);
    }
}
