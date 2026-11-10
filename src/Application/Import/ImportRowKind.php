<?php

declare(strict_types=1);

namespace App\Application\Import;

enum ImportRowKind: string
{
    case Expense    = 'expense';
    case Transfer   = 'transfer';
    case DateHeader = 'date';
    case Blank      = 'blank';
    case Comment    = 'comment';
    case Error      = 'error';
}
