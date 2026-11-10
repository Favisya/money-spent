<?php

declare(strict_types=1);

namespace App\Application\Participant;

use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;

final readonly class ParticipantAdmin
{
    private const int NAME_LENGTH = 64;

    public function __construct(
        private ParticipantRepository $participants,
    ) {
    }

    /**
     * @param string $telegramId digits or empty
     * @param string $color      a ParticipantColor value or empty to keep the default
     */
    public function save(?int $id, string $name, string $telegramId, string $color = ''): Participant
    {
        $picked = $color === '' ? null : ParticipantColor::tryFrom($color);

        if ($color !== '' && $picked === null) {
            throw new UserError('Такого цвета нет.');
        }

        $name       = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $telegramId = trim($telegramId);

        if ($name === '' || mb_strlen($name) > self::NAME_LENGTH) {
            throw new UserError('Имя нужно от 1 до %max% символов.', ['%max%' => self::NAME_LENGTH]);
        }

        if ($telegramId !== '' && preg_match('/^[1-9]\d{0,18}$/', $telegramId) !== 1) {
            throw new UserError('Telegram id — это число, например 123456789.');
        }

        $telegram = $telegramId === '' ? null : (int) $telegramId;

        if ($telegram !== null && $this->participants->isTelegramIdTaken($telegram, $id)) {
            throw new UserError('Этот Telegram id уже привязан к другому участнику.');
        }

        if ($id === null) {
            $created = $this->participants->create($name, $telegram);

            if ($picked !== null) {
                $this->participants->update($created->id, $name, $telegram, $picked);
            }

            return $this->participants->find($created->id) ?? $created;
        }

        if ($this->participants->find($id) === null) {
            throw new UserError('Участник не найден.');
        }

        $this->participants->update($id, $name, $telegram, $picked);

        return new Participant($id, $telegram, $name, $picked);
    }
}
