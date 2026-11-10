<?php

declare(strict_types=1);

namespace App\Application\Sharing;

/**
 * A freshly paired device and its secret token. The token is shown to the device once and only its hash is stored.
 */
final readonly class IssuedDevice
{
    public function __construct(
        public Device $device,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
