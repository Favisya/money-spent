<?php

declare(strict_types=1);

namespace App\Application\Purchase;

enum ReceiptSource: string
{
    case Photo  = 'photo';
    case Text   = 'text';
    case Manual = 'manual';
}
