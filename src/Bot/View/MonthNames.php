<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Report\MonthPeriod;

final class MonthNames
{
    private const array NAMES = [
        1  => 'Январь',
        2  => 'Февраль',
        3  => 'Март',
        4  => 'Апрель',
        5  => 'Май',
        6  => 'Июнь',
        7  => 'Июль',
        8  => 'Август',
        9  => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    public function label(MonthPeriod $period): string
    {
        return sprintf('%s %d', self::NAMES[$period->month], $period->year);
    }
}
