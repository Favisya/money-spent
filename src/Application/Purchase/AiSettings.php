<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\UserError;
use App\Infrastructure\Persistence\SettingRepository;
use App\Infrastructure\Security\SecretBox;
use SensitiveParameter;

/**
 * Which model reads receipts and with which API key, if it needs one. Keys are stored encrypted and never shown
 * again in full.
 */
final readonly class AiSettings
{
    private const string PROVIDER      = 'ai.provider';
    private const string KEY           = 'ai.%s.key';
    private const string MODEL         = 'ai.%s.model';
    private const string MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,79}$/';
    private const string KEY_PATTERN   = '/^[\x21-\x7E]{20,400}$/';
    private const int HINT_LENGTH      = 4;

    public function __construct(
        private SettingRepository $settings,
        private SecretBox $secrets,
    ) {
    }

    public function provider(): AiProvider
    {
        return AiProvider::tryFrom((string) $this->settings->get(self::PROVIDER)) ?? AiProvider::Claude;
    }

    public function model(AiProvider $provider): string
    {
        return $this->settings->get(sprintf(self::MODEL, $provider->value)) ?? $provider->defaultModel();
    }

    public function credentials(AiProvider $provider): ?AiCredentials
    {
        if (!$provider->needsKey()) {
            return new AiCredentials('', $this->model($provider));
        }

        $key = $this->key($provider);

        return $key === null ? null : new AiCredentials($key, $this->model($provider));
    }

    /**
     * Whether receipts can be read right now, without decrypting the key.
     */
    public function isReady(AiProvider $provider): bool
    {
        return !$provider->needsKey() || $this->settings->get(sprintf(self::KEY, $provider->value)) !== null;
    }

    /**
     * The last characters of the stored key, to recognise it without revealing it.
     */
    public function keyHint(AiProvider $provider): ?string
    {
        $key = $this->key($provider);

        return $key === null ? null : '…' . substr($key, -self::HINT_LENGTH);
    }

    public function choose(AiProvider $provider): void
    {
        $this->settings->set(self::PROVIDER, $provider->value);
    }

    /**
     * An empty model means the default one; an empty key keeps the stored key.
     */
    public function configure(
        AiProvider $provider,
        string $model,
        #[SensitiveParameter]
        string $newKey,
        bool $forgetKey,
    ): void {
        $model  = trim($model) === '' ? $provider->defaultModel() : trim($model);
        $newKey = trim($newKey);

        if ($model !== '' && preg_match(self::MODEL_PATTERN, $model) !== 1) {
            throw new UserError('Модель — латиница, цифры, точка, двоеточие или дефис.');
        }

        if ($newKey !== '' && preg_match(self::KEY_PATTERN, $newKey) !== 1) {
            throw new UserError('Ключ выглядит неверно: скопируй его целиком, без пробелов.');
        }

        $this->settings->set(sprintf(self::MODEL, $provider->value), $model);

        if ($forgetKey) {
            $this->settings->delete(sprintf(self::KEY, $provider->value));
        }

        if ($newKey !== '') {
            $this->settings->set(sprintf(self::KEY, $provider->value), $this->secrets->seal($newKey));
        }
    }

    private function key(AiProvider $provider): ?string
    {
        $sealed = $this->settings->get(sprintf(self::KEY, $provider->value));

        return $sealed === null ? null : $this->secrets->open($sealed);
    }
}
