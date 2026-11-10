<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The macOS app ships the code read-only inside its bundle and points APP_VAR_DIR at a writable folder.
     */
    public function getCacheDir(): string
    {
        $varDir = $this->varDir();

        return $varDir === null ? parent::getCacheDir() : $varDir . '/cache/' . $this->environment;
    }

    public function getShareDir(): ?string
    {
        $varDir = $this->varDir();

        return $varDir === null ? parent::getShareDir() : $varDir . '/share';
    }

    private function varDir(): ?string
    {
        $varDir = $_SERVER['APP_VAR_DIR'] ?? null;

        return is_string($varDir) && $varDir !== '' ? $varDir : null;
    }
}
