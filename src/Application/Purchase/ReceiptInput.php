<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\UserError;

/**
 * What the reader looks at: a receipt photo or a list typed by hand.
 */
final readonly class ReceiptInput
{
    public const int TEXT_LIMIT = 5000;

    private function __construct(
        public ?ReceiptImage $image,
        public ?string $text,
    ) {
    }

    public static function photo(ReceiptImage $image): self
    {
        return new self($image, null);
    }

    public static function text(string $text): self
    {
        $text = trim($text);

        if ($text === '') {
            throw new UserError('Перетащи фото чека или впиши список покупок.');
        }

        if (mb_strlen($text) > self::TEXT_LIMIT) {
            throw new UserError('Список — до %max% символов.', ['%max%' => self::TEXT_LIMIT]);
        }

        return new self(null, $text);
    }

    public function source(): ReceiptSource
    {
        return $this->image === null ? ReceiptSource::Text : ReceiptSource::Photo;
    }
}
