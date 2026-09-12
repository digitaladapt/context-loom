<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\HealthRegistry;
use PHPUnit\Framework\TestCase;

final class HealthRegistryTest extends TestCase
{
    public function test_empty_snapshot_is_ok(): void
    {
        $registry = new HealthRegistry();

        $snapshot = $registry->snapshot();

        self::assertSame('ok', $snapshot['status']);
        self::assertArrayHasKey('providers', $snapshot);
        self::assertSame([], $snapshot['providers']);
        self::assertStringContainsString('0.1.0-dev', $snapshot['version']);
    }

    public function test_add_provider_appears_in_snapshot(): void
    {
        $registry = new HealthRegistry();
        $registry->add([
            'name' => 'penny-track',
            'status' => 'ok',
            'detail' => null,
            'checkedAt' => null,
        ]);

        $snapshot = $registry->snapshot();

        self::assertSame('ok', $snapshot['status']);
        self::assertArrayHasKey('penny-track', $snapshot['providers']);
        self::assertSame('ok', $snapshot['providers']['penny-track']['status']);
        self::assertNull($snapshot['providers']['penny-track']['checked_at']);
    }
}
