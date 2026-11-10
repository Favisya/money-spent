<?php

declare(strict_types=1);

namespace App\Application\Rates;

final readonly class RateUpdateResult
{
    /**
     * @param array<string, int>    $storedByDate requested date (Y-m-d) => newly stored rates
     * @param array<string, string> $errorsByDate requested date (Y-m-d) => error
     */
    public function __construct(
        public array $storedByDate,
        public array $errorsByDate,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errorsByDate !== [];
    }
}
