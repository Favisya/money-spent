<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\UserError;

/**
 * What the reader looks at: photos of one receipt (several screenshots of a long order) or a list typed by hand.
 */
final readonly class ReceiptInput
{
    public const int TEXT_LIMIT  = 5000;
    public const int MAX_IMAGES  = 8;
    public const int TOTAL_BYTES = 31_457_280;

    /**
     * @param list<ReceiptImage> $images
     */
    private function __construct(
        public array $images,
        public ?string $text,
    ) {
    }

    /**
     * @param list<ReceiptImage> $images in the order they were taken
     */
    public static function photos(array $images): self
    {
        if ($images === []) {
            throw new UserError('Перетащи фото чека или впиши список покупок.');
        }

        if (count($images) > self::MAX_IMAGES) {
            throw new UserError('За раз — до %max% фото.', ['%max%' => self::MAX_IMAGES]);
        }

        $bytes = array_sum(array_map(static fn (ReceiptImage $image): int => strlen($image->bytes), $images));

        if ($bytes > self::TOTAL_BYTES) {
            throw new UserError('Фото вместе больше %max% МБ — отправь их частями.', [
                '%max%' => intdiv(self::TOTAL_BYTES, 1_048_576),
            ]);
        }

        return new self($images, null);
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

        return new self([], $text);
    }

    public function source(): ReceiptSource
    {
        return $this->images === [] ? ReceiptSource::Text : ReceiptSource::Photo;
    }
}
