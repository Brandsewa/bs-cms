<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Media;

use BsCms\Media\MediaSecurity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/bs_media_sec_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testMagicBytesValidationAndAllowList(): void
    {
        // 1. Valid PNG
        $pngPath = $this->tempDir . '/valid.png';
        $im = imagecreatetruecolor(10, 10);
        $this->assertNotFalse($im);
        imagepng($im, $pngPath);

        $mime = MediaSecurity::detectMimeType($pngPath);
        $this->assertSame('image/png', $mime);
        $this->assertTrue(MediaSecurity::isAllowedMime((string) $mime));
        $this->assertSame('png', MediaSecurity::getExtensionForMime((string) $mime));

        // 2. SVG is rejected by default
        $this->assertFalse(MediaSecurity::isAllowedMime('image/svg+xml'));
        $this->assertTrue(MediaSecurity::isAllowedMime('image/svg+xml', true));

        // 3. Fake PNG with php extension and PHP code
        $fakePhp = $this->tempDir . '/evil.php';
        file_put_contents($fakePhp, '<?php echo "evil"; ?>');
        $fakeMime = MediaSecurity::detectMimeType($fakePhp);
        $this->assertNotSame('image/png', $fakeMime);
        // PHP text files are not allowed image MIME
        $this->assertFalse(MediaSecurity::isAllowedMime((string) $fakeMime));
    }

    public function testReencodingStripsAppendedPhpCodeFromJpeg(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for image re-encoding test.');
        }

        // 1. Create valid JPEG image
        $cleanJpegPath = $this->tempDir . '/clean.jpg';
        $im = imagecreatetruecolor(20, 20);
        $this->assertNotFalse($im);
        $red = imagecolorallocate($im, 255, 0, 0);
        $this->assertNotFalse($red);
        imagefill($im, 0, 0, $red);
        imagejpeg($im, $cleanJpegPath, 90);

        // 2. Append PHP polyglot payload to the JPEG
        $polyglotPath = $this->tempDir . '/polyglot.jpg';
        $jpegData = file_get_contents($cleanJpegPath);
        $this->assertIsString($jpegData);

        $payload = '<?php echo "PWNED_BY_POLYGLOT"; phpinfo(); ?>';
        file_put_contents($polyglotPath, $jpegData . "\n" . $payload);

        // Confirm payload exists in source file
        $polyglotContent = file_get_contents($polyglotPath);
        $this->assertIsString($polyglotContent);
        $this->assertStringContainsString('PWNED_BY_POLYGLOT', $polyglotContent);

        // 3. Sanitize and store
        $targetPath = $this->tempDir . '/sanitized.jpg';
        MediaSecurity::sanitizeAndStore($polyglotPath, $targetPath, 'image/jpeg');

        // 4. Assert target file is a valid image and payload was stripped
        $this->assertFileExists($targetPath);
        $info = @getimagesize($targetPath);
        $this->assertNotFalse($info, "Sanitized image must remain a valid image.");
        $this->assertSame(20, $info[0]);
        $this->assertSame(20, $info[1]);

        $sanitizedContent = file_get_contents($targetPath);
        $this->assertIsString($sanitizedContent);
        $this->assertStringNotContainsString('PWNED_BY_POLYGLOT', $sanitizedContent, "Appended PHP payload must be completely stripped by GD re-encoding.");
        $this->assertStringNotContainsString('<?php', $sanitizedContent);
    }

    public function testTextFilesWithPhpTagsAreRejected(): void
    {
        $textFile = $this->tempDir . '/notes.txt';
        file_put_contents($textFile, "Header\n<?php phpinfo(); ?>\nFooter");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden executable script tags');

        MediaSecurity::sanitizeAndStore($textFile, $this->tempDir . '/stored.txt', 'text/plain');
    }

    public function testHtaccessHardeningContent(): void
    {
        $mediaDir = $this->tempDir . '/media';
        MediaSecurity::ensureHtaccess($mediaDir);

        $htaccessPath = $mediaDir . '/.htaccess';
        $this->assertFileExists($htaccessPath);

        $content = file_get_contents($htaccessPath);
        $this->assertIsString($content);

        // LiteSpeed and Apache directives
        $this->assertStringContainsString('php_flag engine off', $content);
        $this->assertStringContainsString('RemoveHandler .php', $content);
        $this->assertStringContainsString('RemoveType .php', $content);
        $this->assertStringContainsString('SetHandler none', $content);
        $this->assertStringContainsString('SetHandler default-handler', $content);
        $this->assertStringContainsString('Deny from all', $content);
        $this->assertStringContainsString('Options -ExecCGI -Indexes', $content);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $content);
        $this->assertStringContainsString('Content-Disposition "attachment"', $content);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->deleteDirectory($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dir);
    }
}
