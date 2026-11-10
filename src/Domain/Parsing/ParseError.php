<?php

declare(strict_types=1);

namespace App\Domain\Parsing;

enum ParseError: string
{
    case Empty          = 'empty';
    case TooLong        = 'too_long';
    case NoAmount       = 'no_amount';
    case ZeroAmount     = 'zero_amount';
    case TooPrecise     = 'too_precise';
    case AmountTooLarge = 'amount_too_large';
    case InvalidDate    = 'invalid_date';
    case FutureDate     = 'future_date';
}
