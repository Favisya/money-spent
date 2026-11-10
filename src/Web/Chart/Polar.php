<?php

declare(strict_types=1);

namespace App\Web\Chart;

/**
 * SVG geometry on a circle centred at 0,0. Angles are radians, 0 at twelve o'clock, growing clockwise.
 */
final class Polar
{
    private const float FULL_TURN = 2 * M_PI - 0.0001;

    public static function x(float $angle, float $radius): string
    {
        return self::number($radius * sin($angle));
    }

    public static function y(float $angle, float $radius): string
    {
        return self::number(-$radius * cos($angle));
    }

    public static function point(float $angle, float $radius): string
    {
        return self::x($angle, $radius) . ',' . self::y($angle, $radius);
    }

    /**
     * Arc command from the current point (which must be at $from) to $to, clockwise.
     */
    public static function arcTo(float $from, float $to, float $radius): string
    {
        return sprintf(
            'A%s,%s 0 %d 1 %s',
            self::number($radius),
            self::number($radius),
            $to - $from > M_PI ? 1 : 0,
            self::point($to, $radius),
        );
    }

    /**
     * Filled ring segment between two radii.
     */
    public static function band(float $from, float $to, float $inner, float $outer): string
    {
        $to = min($to, $from + self::FULL_TURN);

        $innerArc = sprintf(
            'A%s,%s 0 %d 0 %s',
            self::number($inner),
            self::number($inner),
            $to - $from > M_PI ? 1 : 0,
            self::point($from, $inner),
        );

        return sprintf(
            'M%s %s L%s %s Z',
            self::point($from, $outer),
            self::arcTo($from, $to, $outer),
            self::point($to, $inner),
            $innerArc,
        );
    }

    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
