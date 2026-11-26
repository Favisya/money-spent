<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use SensitiveParameter;

final readonly class AiCredentials
{
    public function __construct(
        #[SensitiveParameter]
        public string $apiKey,
        public string $model,
    ) {
    }
}
