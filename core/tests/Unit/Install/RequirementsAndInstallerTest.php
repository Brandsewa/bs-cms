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

    public function testInstallerRejectsDatabaseWithoutCsrfWith403ProblemDetails(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $request = (new ServerRequest('POST', '/install/database'))
            ->withParsedBody(['db_host' => '127.0.0.1']);

        $response = $installer->handle($request);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(403, $body['status']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertStringContainsString('Invalid or missing CSRF token', $body['detail']);
    }

    public function testInstallerSendsSecurityHeadersAndNoStore(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $request = new ServerRequest('GET', '/install/requirements');

        $response = $installer->handle($request);
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testInstallerRendersSiteUrlField(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $request = new ServerRequest('GET', '/install/requirements');

        $response = $installer->handle($request);
        $body = (string) $response->getBody();

        $this->assertStringContainsString('Site URL (Admin & API Base)', $body);
        $this->assertStringContainsString('name="site_url"', $body);
    }

    public function testExecuteRejectsEmptyOwnerNameWith400(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $csrfToken = 'test_csrf_token_123';
        file_put_contents($this->tempStorageDir . '/install_csrf.tmp', $csrfToken);

        $request = (new ServerRequest('POST', '/install/execute'))
            ->withParsedBody([
                '_csrf' => $csrfToken,
                'owner_name' => '',
                'owner_email' => 'admin@example.com',
                'owner_password' => 'CorrectHorseBatteryStaple2026!',
            ]);

        $response = $installer->handle($request);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Owner name cannot be empty.', $body['detail']);
    }

    public function testExecuteRejectsInvalidOwnerEmailWith400(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $csrfToken = 'test_csrf_token_123';
        file_put_contents($this->tempStorageDir . '/install_csrf.tmp', $csrfToken);

        $request = (new ServerRequest('POST', '/install/execute'))
            ->withParsedBody([
                '_csrf' => $csrfToken,
                'owner_name' => 'Owner',
                'owner_email' => 'not-an-email',
                'owner_password' => 'CorrectHorseBatteryStaple2026!',
            ]);

        $response = $installer->handle($request);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('A valid owner email address is required.', $body['detail']);
    }

    public function testExecuteRejectsWeakOrCommonPasswordWith400(): void
    {
        $installer = new InstallerController(dirname(__DIR__, 3), $this->tempStorageDir);
        $csrfToken = 'test_csrf_token_123';
        file_put_contents($this->tempStorageDir . '/install_csrf.tmp', $csrfToken);

        // Less than 12 characters
        $request = (new ServerRequest('POST', '/install/execute'))
            ->withParsedBody([
                '_csrf' => $csrfToken,
                'owner_name' => 'Owner',
                'owner_email' => 'admin@example.com',
                'owner_password' => 'short123',
            ]);

        $response = $installer->handle($request);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('at least 12 characters', $body['detail']);

        // Common password
        $request2 = (new ServerRequest('POST', '/install/execute'))
            ->withParsedBody([
                '_csrf' => $csrfToken,
                'owner_name' => 'Owner',
                'owner_email' => 'admin@example.com',
                'owner_password' => 'unbelievable',
            ]);

        $response2 = $installer->handle($request2);
        $this->assertSame(400, $response2->getStatusCode());
        $body2 = json_decode((string) $response2->getBody(), true);
        $this->assertStringContainsString('too common', $body2['detail']);
    }
}
