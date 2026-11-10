<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bot\Telegram\Update;
use App\Bot\UpdateProcessor;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TelegramWebhookController
{
    private const string SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';
    private const int MAX_BODY_BYTES   = 1_048_576;
    private const int JSON_DEPTH       = 32;

    public function __construct(
        private UpdateProcessor $processor,
        #[SensitiveParameter]
        private string $webhookSecret,
    ) {
    }

    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $secret = (string) $request->headers->get(self::SECRET_HEADER, '');

        if ($this->webhookSecret === '' || !hash_equals($this->webhookSecret, $secret)) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['ok' => false], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($body, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
            $update  = Update::fromArray(is_array($payload) ? $payload : []);
        } catch (JsonException | InvalidArgumentException) {
            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $this->processor->process($update);

        return new JsonResponse(['ok' => true]);
    }
}
