<?php

declare(strict_types=1);

namespace App\Application\Sharing;

use App\Application\UserError;
use App\Infrastructure\Persistence\DeviceRepository;
use App\Infrastructure\Persistence\PairingCodeRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use Psr\Clock\ClockInterface;

/**
 * Connecting another Mac: the host shows a 6-digit code for one participant, the other Mac enters it once and gets a
 * long random device token. A code lives 10 minutes and dies after 5 wrong guesses, so it cannot be brute-forced.
 */
final readonly class Pairing
{
    private const string CODE_TTL     = '+10 minutes';
    private const int CODE_DIGITS     = 6;
    private const int MAX_ATTEMPTS    = 5;
    private const int TOKEN_BYTES     = 32;
    private const int NAME_LENGTH     = 64;
    private const int TOUCH_EVERY_SEC = 300;

    public function __construct(
        private PairingCodeRepository $codes,
        private DeviceRepository $devices,
        private ParticipantRepository $participants,
        private ClockInterface $clock,
        #[\SensitiveParameter]
        private string $appSecret,
    ) {
    }

    public function createCode(int $participantId): string
    {
        if ($this->participants->find($participantId) === null) {
            throw new UserError('Выбери, для кого код.');
        }

        $code = str_pad((string) random_int(0, 10 ** self::CODE_DIGITS - 1), self::CODE_DIGITS, '0', STR_PAD_LEFT);
        $now  = $this->clock->now();

        $this->codes->replace($participantId, $this->hashCode($code), $now->modify(self::CODE_TTL), $now);

        return $code;
    }

    public function redeem(#[\SensitiveParameter] string $code, string $deviceName): IssuedDevice
    {
        $now    = $this->clock->now();
        $active = $this->codes->findActive($now, self::MAX_ATTEMPTS);
        $code   = preg_replace('/\D/', '', $code) ?? '';

        if ($active === null) {
            throw new UserError('Кода нет или он истёк. Попроси новый на главном Mac: Траты → Общий доступ.');
        }

        if (!hash_equals($active['code_hash'], $this->hashCode($code))) {
            $this->codes->countFailure($active['id']);

            throw new UserError('Код не подходит.');
        }

        $this->codes->markUsed($active['id'], $now);

        $name  = trim(preg_replace('/\s+/u', ' ', $deviceName) ?? '');
        $name  = $name === '' ? 'Mac' : mb_substr($name, 0, self::NAME_LENGTH);
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $id    = $this->devices->insert($active['participant_id'], $name, self::hashToken($token));

        return new IssuedDevice($this->devices->find($id) ?? throw new UserError('Устройство не сохранилось.'), $token);
    }

    public function authenticate(#[\SensitiveParameter] string $token): ?Device
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $device = $this->devices->findActiveByTokenHash(self::hashToken($token));
        $now    = $this->clock->now();

        if ($device !== null && ($device->lastSeenAt === null
            || $now->getTimestamp() - $device->lastSeenAt->getTimestamp() > self::TOUCH_EVERY_SEC)) {
            $this->devices->touch($device->id, $now);
        }

        return $device;
    }

    /**
     * @return list<Device>
     */
    public function devices(): array
    {
        return $this->devices->findActive();
    }

    public function revoke(int $deviceId): void
    {
        $this->devices->revoke($deviceId, $this->clock->now());
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', 'pairing:' . $code, $this->appSecret);
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
