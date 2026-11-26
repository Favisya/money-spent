<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\Security\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTripAndTamperDetection(): void
    {
        $box    = new SecretBox('app-secret');
        $sealed = $box->seal('sk-ant-api03-secret');

        self::assertStringNotContainsString('secret', (string) base64_decode($sealed, true));
        self::assertNotSame($sealed, $box->seal('sk-ant-api03-secret'));
        self::assertSame('sk-ant-api03-secret', $box->open($sealed));
        self::assertNull((new SecretBox('another-secret'))->open($sealed));
        self::assertNull($box->open('not base64 at all!'));
        self::assertNull($box->open(base64_encode('short')));
    }
}
