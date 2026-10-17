<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Settings;

use BsCms\Cache\FileCache;
use BsCms\Database\Connection;
use BsCms\Database\QueryBuilder;
use BsCms\Security\Crypto;
use BsCms\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    public function testGetAndSetWithMockedConnection(): void
    {
        $mockConn = $this->createMock(Connection::class);
        $mockQb = $this->createMock(QueryBuilder::class);

        $mockConn->method('table')->with('settings')->willReturn($mockQb);
        $mockQb->method('where')->willReturnSelf();
        $mockQb->method('first')->willReturn([
            'handle' => 'site_title',
            'value' => json_encode('My Awesome Site'),
            'is_secret' => 0,
            'updated_by' => null,
            'updated_at' => '2026-10-17 12:00:00.000',
        ]);

        $repo = new SettingsRepository($mockConn);
        $title = $repo->get('site_title');

        $this->assertSame('My Awesome Site', $title);
    }

    public function testEncryptedSecretSettings(): void
    {
        $appKey = Crypto::generateKey();
        $crypto = Crypto::fromAppKey($appKey);

        $plaintextSecret = 'sk_live_very_secret_token_12345';
        $encryptedSecret = $crypto->encrypt($plaintextSecret);

        $mockConn = $this->createMock(Connection::class);
        $mockQb = $this->createMock(QueryBuilder::class);

        $mockConn->method('table')->with('settings')->willReturn($mockQb);
        $mockQb->method('where')->willReturnSelf();
        $mockQb->method('first')->willReturn([
            'handle' => 'smtp_password',
            'value' => json_encode($encryptedSecret),
            'is_secret' => 1,
            'updated_by' => null,
            'updated_at' => '2026-10-17 12:00:00.000',
        ]);

        $repo = new SettingsRepository($mockConn, $crypto);
        $decrypted = $repo->get('smtp_password');

        $this->assertSame($plaintextSecret, $decrypted);
    }
}
