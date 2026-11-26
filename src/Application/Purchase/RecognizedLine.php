<?php

declare(strict_types=1);

namespace App\Application\Purchase;

/**
 * One position as the model read it: raw strings, checked later by the normalizer.
 */
final readonly class RecognizedLine
{
    public function __construct(
        public string $name,
        public string $quantity,
        public string $unit,
        public string $unitPrice,
        public string $amount,
    ) {
    }
}
