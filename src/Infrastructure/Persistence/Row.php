<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use DateTimeImmutable;
use UnexpectedValueException;

/**
 * Typed access to a DBAL result row.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        return match ($value) {
            true, 't', 'true', 1, '1'   => true,
            false, 'f', 'false', 0, '0' => false,
            default                     => throw new UnexpectedValueException(
                sprintf('Column "%s" is not a boolean.', $key),
            ),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('Column "%s" is not an integer.', $key));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::int($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Column "%s" is not a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : self::string($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function date(array $row, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable(self::string($row, $key));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public static function stringList(array $row, string $key): array
    {
        $decoded = json_decode(self::string($row, $key), true, 4, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new UnexpectedValueException(sprintf('Column "%s" is not a JSON list.', $key));
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
