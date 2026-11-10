<?php

declare(strict_types=1);

namespace App\Infrastructure\Telegram;

use App\Bot\Telegram\InlineKeyboard;
use App\Bot\Telegram\TelegramAdminClient;
use App\Bot\Telegram\TelegramApiException;
use App\Bot\Telegram\TelegramClient;
use SensitiveParameter;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bot API over HTTPS. Errors never include the request URL because it contains the bot token.
 */
final readonly class HttpTelegramClient implements TelegramClient, TelegramAdminClient
{
    private const array ALLOWED_UPDATES = ['message', 'callback_query'];
    private const string NOT_MODIFIED   = 'message is not modified';
    private const int POLL_GRACE        = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[SensitiveParameter]
        private string $botToken,
    ) {
    }

    public function sendMessage(int $chatId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        $this->call('sendMessage', ['json' => $this->textPayload($html, $keyboard) + ['chat_id' => $chatId]]);
    }

    public function editMessage(int $chatId, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        try {
            $this->call('editMessageText', [
                'json' => $this->textPayload($html, $keyboard) + ['chat_id' => $chatId, 'message_id' => $messageId],
            ]);
        } catch (TelegramApiException $exception) {
            if (!str_contains($exception->getMessage(), self::NOT_MODIFIED)) {
                throw $exception;
            }
        }
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', ['json' => array_filter([
            'callback_query_id' => $callbackId,
            'text'              => $text,
        ])]);
    }

    public function sendDocument(int $chatId, string $filename, string $content, string $caption): void
    {
        $form = new FormDataPart([
            'chat_id'    => (string) $chatId,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
            'document'   => new DataPart($content, $filename, 'text/csv'),
        ]);

        $this->call('sendDocument', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body'    => $form->bodyToIterable(),
        ]);
    }

    public function getUpdates(int $offset, int $timeoutSeconds): array
    {
        $result = $this->call('getUpdates', [
            'json' => [
                'offset'          => $offset,
                'timeout'         => $timeoutSeconds,
                'allowed_updates' => self::ALLOWED_UPDATES,
            ],
            'timeout' => $timeoutSeconds + self::POLL_GRACE,
        ]);

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function setWebhook(string $url, #[SensitiveParameter] string $secret): void
    {
        $this->call('setWebhook', ['json' => [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => self::ALLOWED_UPDATES,
        ]]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['json' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(string $html, ?InlineKeyboard $keyboard): array
    {
        $payload = [
            'text'                 => $html,
            'parse_mode'           => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = $keyboard->toArray();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function call(string $method, array $options): mixed
    {
        if ($this->botToken === '') {
            throw new TelegramApiException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            $data = $this->httpClient
                ->request('POST', sprintf('/bot%s/%s', $this->botToken, $method), $options)
                ->toArray(false);
        } catch (ExceptionInterface) {
            throw new TelegramApiException(sprintf('Telegram %s request failed.', $method));
        }

        if (($data['ok'] ?? false) !== true) {
            $description = is_string($data['description'] ?? null) ? $data['description'] : 'unknown error';

            throw new TelegramApiException(sprintf('Telegram %s failed: %s', $method, $description));
        }

        return $data['result'] ?? null;
    }
}
