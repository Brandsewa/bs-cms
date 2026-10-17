<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Install;

use BsCms\Install\InstallerController;
use BsCms\Install\Requirements;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class RequirementsAndInstallerTest extends TestCase
{
    private string $tempStorageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempStorageDir = sys_get_temp_dir() . '/bs_cms_install_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempStorageDir)) {
            mkdir($this->tempStorageDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempStorageDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tempStorageDir);
        parent::tearDown();
    }

    public function testRequirementsCheckDetectsPhpAndExtensions(): void
    {
        $res = Requirements::check($this->tempStorageDir, true);

        $this->assertSame('pass', $res['php_version']['status']);
        $this->assertSame('pass', $res['storage_writable']['status']);
        $this->assertSame('pass', $res['https']['status']);
        $this->assertArrayHasKey('sodium', $res['extensions']);
        $this->assertArrayHasKey('pdo_mysql', $res['extensions']);
    }

    public function testDetectCliPhpBinaryReturnsPathOrFallback(): void
    {
        $binaryInfo = Requirements::detectCliPhpBinary();

        $this->assertNotEmpty($binaryInfo['path']);
        $this->assertContains($binaryInfo['type'], ['cloudlinux_matched', 'detected', 'generic_fallback']);
    }

    public function testInstallerRendersRequirementsWhenNotInstalled(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $request = new ServerRequest('GET', '/install/requirements');

        $response = $installer->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('BS CMS Setup', $body);
        $this->assertStringContainsString('System Requirements', $body);
    }

    public function testInstallerRefusesWhenInstalledLockPresent(): void
    {
        file_put_contents($this->tempStorageDir . '/installed.lock', 'locked');

        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $request = new ServerRequest('GET', '/install');

        $response = $installer->handle($request);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('already installed', (string) $response->getBody());
    }
}
