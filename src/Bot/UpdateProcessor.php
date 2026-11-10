<?php

declare(strict_types=1);

namespace App\Bot;

use App\Application\Clock\LocalCalendar;
use App\Bot\Reply\Reply;
use App\Bot\Telegram\TelegramApiException;
use App\Bot\Telegram\TelegramClient;
use App\Bot\Telegram\Update;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\TelegramUpdateRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Handles one update exactly once: the update id and all writes commit together, replies go out afterwards.
 */
final readonly class UpdateProcessor
{
    public function __construct(
        private Connection $connection,
        private TelegramUpdateRepository $updates,
        private ParticipantRepository $participants,
        private AccessPolicy $accessPolicy,
        private BotRouter $router,
        private LocalCalendar $calendar,
        private TelegramClient $telegram,
        private LoggerInterface $logger,
    ) {
    }

    public function process(Update $update): void
    {
        $actor = $update->actor();

        if ($actor === null || !$this->accessPolicy->isAllowed($actor->id)) {
            return;
        }

        /** @var list<Reply> $replies */
        $replies = $this->connection->transactional(function () use ($update, $actor): array {
            if (!$this->updates->markProcessed($update->updateId)) {
                return [];
            }

            $participant = $this->participants->register($actor->id, $actor->firstName);

            return $this->router->route($update, $participant, $this->calendar->today());
        });

        foreach ($replies as $reply) {
            try {
                $reply->send($this->telegram);
            } catch (TelegramApiException $exception) {
                $this->logger->error('Telegram reply failed', [
                    'update_id' => $update->updateId,
                    'reply'     => $reply::class,
                    'error'     => $exception->getMessage(),
                ]);
            }
        }
    }
}
