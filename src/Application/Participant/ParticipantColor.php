<?php

declare(strict_types=1);

namespace App\Application\Participant;

/**
 * The badge color of a participant, also used for their personal spending in charts. The values are CSS token names
 * (--pc-orange ...), each defined for light and dark themes and checked for color-blind separation.
 */
enum ParticipantColor: string
{
    case Orange  = 'orange';
    case Violet  = 'violet';
    case Blue    = 'blue';
    case Aqua    = 'aqua';
    case Yellow  = 'yellow';
    case Magenta = 'magenta';
    case Green   = 'green';
    case Red     = 'red';

    public static function defaultFor(int $position): self
    {
        $cases = self::cases();

        return $cases[$position % count($cases)];
    }

    public function label(): string
    {
        return match ($this) {
            self::Orange  => 'оранжевый',
            self::Violet  => 'фиолетовый',
            self::Blue    => 'синий',
            self::Aqua    => 'бирюзовый',
            self::Yellow  => 'жёлтый',
            self::Magenta => 'розовый',
            self::Green   => 'зелёный',
            self::Red     => 'красный',
        };
    }

    public function cssVar(): string
    {
        return 'var(--pc-' . $this->value . ')';
    }
}
