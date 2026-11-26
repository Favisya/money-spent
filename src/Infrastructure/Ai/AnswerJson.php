<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Purchase\RecognizedReceipt;
use App\Application\UserError;
use JsonException;

final class AnswerJson
{
    private const int DEPTH = 16;

    public static function decode(string $text): RecognizedReceipt
    {
        try {
            $data = json_decode(trim($text), true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UserError('ИИ ответил не по формату — попробуй ещё раз.');
        }

        if (!is_array($data)) {
            throw new UserError('ИИ ответил не по формату — попробуй ещё раз.');
        }

        return RecognizedReceipt::fromArray($data);
    }
}
