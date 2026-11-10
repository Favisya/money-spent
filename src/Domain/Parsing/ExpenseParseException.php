<?php

declare(strict_types=1);

namespace App\Domain\Parsing;

use DomainException;

final class ExpenseParseException extends DomainException
{
    /**
     * @param array<string, string> $context values for the user-facing message (token, currency, ...)
     */
    public function __construct(
        public readonly ParseError $error,
        public readonly array $context = [],
    ) {
        parent::__construct(sprintf('Expense parse error: %s.', $error->value));
    }
}
