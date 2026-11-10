<?php

declare(strict_types=1);

namespace App\Bot\Callback;

use App\Application\UserError;

final class CallbackPayload
{
    /**
     * @return non-empty-list<int>
     */
    public static function ids(string $payload, int $count): array
    {
        $parts = explode(':', $payload);

        if (count($parts) !== $count) {
            throw new UserError('Кнопка устарела.');
        }

        $ids = [];

        foreach ($parts as $part) {
            if (preg_match('/^[1-9]\d{0,17}$/', $part) !== 1) {
                throw new UserError('Кнопка устарела.');
            }

            $ids[] = (int) $part;
        }

        return $ids;
    }
}
