<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use SensitiveParameter;

/**
 * Encrypts small secrets (API keys) at rest with a key derived from APP_SECRET.
 * A secret sealed under another APP_SECRET cannot be opened and reads as missing.
 */
final readonly class SecretBox
{
    private const string CONTEXT = 'money-spent:secret-box:v1';

    private string $key;

    public function __construct(#[SensitiveParameter] string $appSecret)
    {
        $this->key = sodium_crypto_generichash(self::CONTEXT . $appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function seal(#[SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return $plain === false ? null : $plain;
    }
}
