<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

use InvalidArgumentException;

/**
 * The subset of a Telegram Update the bot understands: private text messages and inline button presses.
 */
final readonly class Update
{
    private const int MAX_TEXT = 4096;

    public function __construct(
        public int $updateId,
        public ?IncomingMessage $message,
        public ?CallbackQuery $callback,
    ) {
    }

    /**
     * @param array<mixed> $payload decoded JSON from Telegram
     */
    public static function fromArray(array $payload): self
    {
        $updateId = $payload['update_id'] ?? null;

        if (!is_int($updateId) || $updateId < 0) {
            throw new InvalidArgumentException('Update has no valid update_id.');
        }

        return new self(
            $updateId,
            self::message($payload['message'] ?? null),
            self::callback($payload['callback_query'] ?? null),
        );
    }

    public function actor(): ?TelegramUser
    {
        return $this->message->from ?? $this->callback?->from;
    }

    private static function message(mixed $message): ?IncomingMessage
    {
        if (!is_array($message)) {
            return null;
        }

        $text   = $message['text'] ?? null;
        $chatId = is_array($message['chat'] ?? null) ? ($message['chat']['id'] ?? null) : null;
        $from   = self::user($message['from'] ?? null);

        if (!is_string($text) || !is_int($message['message_id'] ?? null) || !is_int($chatId) || $from === null) {
            return null;
        }

        return new IncomingMessage($message['message_id'], $chatId, $from, mb_substr($text, 0, self::MAX_TEXT));
    }

    private static function callback(mixed $callback): ?CallbackQuery
    {
        if (!is_array($callback) || !is_array($callback['message'] ?? null)) {
            return null;
        }

        $message = $callback['message'];
        $chatId  = is_array($message['chat'] ?? null) ? ($message['chat']['id'] ?? null) : null;
        $from    = self::user($callback['from'] ?? null);
        $id      = $callback['id'] ?? null;
        $data    = $callback['data'] ?? null;

        if (!is_string($id) || !is_string($data) || !is_int($chatId) || !is_int($message['message_id'] ?? null)) {
            return null;
        }

        return $from === null ? null : new CallbackQuery($id, $from, $chatId, $message['message_id'], $data);
    }

    private static function user(mixed $user): ?TelegramUser
    {
        if (!is_array($user) || !is_int($user['id'] ?? null) || ($user['is_bot'] ?? false) === true) {
            return null;
        }

        $firstName = $user['first_name'] ?? '';
        $language  = $user['language_code'] ?? null;

        return new TelegramUser(
            $user['id'],
            is_string($firstName) ? $firstName : '',
            is_string($language) ? $language : null,
        );
    }
}
