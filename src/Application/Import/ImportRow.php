<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Transfer\TransferIntent;
use App\Domain\Parsing\ParsedExpense;
use DateTimeImmutable;

final readonly class ImportRow
{
    public function __construct(
        public int $lineNumber,
        public string $text,
        public ImportRowKind $kind,
        public ?ParsedExpense $expense = null,
        public ?string $categoryTitle = null,
        public ?int $payerId = null,
        public ?DateTimeImmutable $date = null,
        public ?string $error = null,
        public ?TransferIntent $transfer = null,
    ) {
    }
}
