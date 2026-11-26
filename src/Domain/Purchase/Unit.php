<?php

declare(strict_types=1);

namespace App\Domain\Purchase;

enum Unit: string
{
    case Piece      = 'pcs';
    case Kilogram   = 'kg';
    case Gram       = 'g';
    case Liter      = 'l';
    case Milliliter = 'ml';

    private const array ALIASES = [
        'pcs'   => self::Piece,
        'pc'    => self::Piece,
        'шт'    => self::Piece,
        'штук'  => self::Piece,
        'штука' => self::Piece,
        'штуки' => self::Piece,
        'հատ'   => self::Piece,
        'kg'    => self::Kilogram,
        'кг'    => self::Kilogram,
        'կգ'    => self::Kilogram,
        'g'     => self::Gram,
        'gr'    => self::Gram,
        'г'     => self::Gram,
        'гр'    => self::Gram,
        'գ'     => self::Gram,
        'l'     => self::Liter,
        'л'     => self::Liter,
        'լ'     => self::Liter,
        'ml'    => self::Milliliter,
        'мл'    => self::Milliliter,
        'մլ'    => self::Milliliter,
    ];

    /**
     * "кг", "kg.", "Шт" and the like; null when the text is not a known unit.
     */
    public static function fromText(string $text): ?self
    {
        $key = rtrim(mb_strtolower(trim($text)), '.');

        return self::ALIASES[$key] ?? null;
    }

    public function isWeighed(): bool
    {
        return $this !== self::Piece;
    }
}
