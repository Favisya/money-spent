<?php

declare(strict_types=1);

namespace App\Application\Purchase;

enum ReceiptStatus: string
{
    /** Recognized or started by hand, not yet turned into an expense. */
    case Draft = 'draft';
    case Saved = 'saved';
}
