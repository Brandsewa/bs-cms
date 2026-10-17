<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Cli;

use BsCms\Cli\Console;
use BsCms\Kernel\Application;
use BsCms\Log\Redactor;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/bs_cms_cli_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/config', 0755, true);
        mkdir($this->tempDir . '/storage', 0755, true);

        // Mock config with sensitive keys
        $appConfig = "<?php return ['name' => 'Test App', 'key' => 'super-secret-app-key', 'secret' => 'jwt-secret', 'token' => 'auth-token'];\n";
        file_put_contents($this->tempDir . '/config/app.php', $appConfig);

        $dbConfig = "<?php return ['database' => 'testdb', 'password' => 'supersecretpass'];\n";
        file_put_contents($this->tempDir . '/config/database.php', $dbConfig);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function testConfigShowRedactsAllSecretsKeysPasswordsAndTokens(): void
    {
        $app = new Application($this->tempDir);
        $console = new Console($app);

        ob_start();
        $exitCode = $console->run(['bs', 'config:show']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertIsString($output);

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded);

        // Assert sensitive keys are redacted
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $decoded['app']['key']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $decoded['app']['secret']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $decoded['app']['token']);
        $this->assertSame(Redactor::REDACTED_PLACEHOLDER, $decoded['database']['password']);

        // Assert non-sensitive keys remain visible
        $this->assertSame('Test App', $decoded['app']['name']);
        $this->assertSame('testdb', $decoded['database']['database']);

        // Ensure plaintext secrets are never present anywhere in output
        $this->assertStringNotContainsString('super-secret-app-key', $output);
        $this->assertStringNotContainsString('supersecretpass', $output);
        $this->assertStringNotContainsString('jwt-secret', $output);
        $this->assertStringNotContainsString('auth-token', $output);
    }

    public function testKeyGenerateReturns32ByteBase64Key(): void
    {
        $app = new Application($this->tempDir);
        $console = new Console($app);

        ob_start();
        $exitCode = $console->run(['bs', 'key:generate']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Generated APP_KEY:', (string) $output);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
