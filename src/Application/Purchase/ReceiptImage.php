<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\UserError;
use finfo;

/**
 * An uploaded receipt photo whose type is detected from its bytes, never from the client's claim.
 */
final readonly class ReceiptImage
{
    public const int MAX_BYTES = 10_485_760;

    public const array TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private function __construct(
        public string $bytes,
        public string $mediaType,
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new UserError('Файл пустой.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new UserError('Фото больше 10 МБ — уменьши его или сделай снимок заново.');
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (!in_array($type, self::TYPES, true) || @getimagesizefromstring($bytes) === false) {
            throw new UserError('Нужна картинка JPEG, PNG, WebP или GIF.');
        }

        return new self($bytes, $type);
    }
}
