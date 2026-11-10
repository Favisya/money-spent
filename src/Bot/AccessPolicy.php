<?php

declare(strict_types=1);

namespace App\Bot;

final readonly class AccessPolicy
{
    /** @var array<int, true> */
    private array $allowed;

    /**
     * @param list<string> $allowedTelegramIds
     */
    public function __construct(array $allowedTelegramIds)
    {
        $allowed = [];

        foreach ($allowedTelegramIds as $id) {
            $id = trim($id);

            if (preg_match('/^\d+$/', $id) === 1) {
                $allowed[(int) $id] = true;
            }
        }

        $this->allowed = $allowed;
    }

    public function isAllowed(int $telegramId): bool
    {
        return isset($this->allowed[$telegramId]);
    }
}
