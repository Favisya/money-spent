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
     */
    public function save(?int $id, string $name, string $telegramId): Participant
    {
        $name       = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $telegramId = trim($telegramId);

        if ($name === '' || mb_strlen($name) > self::NAME_LENGTH) {
            throw new UserError(sprintf('Имя нужно от 1 до %d символов.', self::NAME_LENGTH));
        }

        if ($telegramId !== '' && preg_match('/^[1-9]\d{0,18}$/', $telegramId) !== 1) {
            throw new UserError('Telegram id — это число, например 123456789.');
        }

        $telegram = $telegramId === '' ? null : (int) $telegramId;

        if ($telegram !== null && $this->participants->isTelegramIdTaken($telegram, $id)) {
            throw new UserError('Этот Telegram id уже привязан к другому участнику.');
        }

        if ($id === null) {
            return $this->participants->create($name, $telegram);
        }

        if ($this->participants->find($id) === null) {
            throw new UserError('Участник не найден.');
        }

        $this->participants->update($id, $name, $telegram);

        return new Participant($id, $telegram, $name);
    }
}
