<?php

declare(strict_types=1);

namespace App\Bot\Telegram;

final readonly class InlineKeyboard
{
    /**
     * @param list<list<InlineButton>> $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }

    /**
     * @param list<InlineButton> $buttons
     */
    public static function grid(array $buttons, int $perRow): self
    {
        return new self(array_chunk($buttons, max(1, $perRow)));
    }

    public function withRow(InlineButton ...$buttons): self
    {
        return new self([...$this->rows, array_values($buttons)]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function toArray(): array
    {
        return [
            'inline_keyboard' => array_map(
                static fn (array $row): array => array_map(
                    static fn (InlineButton $button): array => [
                        'text'          => $button->text,
                        'callback_data' => $button->callbackData,
                    ],
                    $row,
                ),
                $this->rows,
            ),
        ];
    }
}
