<?php

use App\Modules\Shared\Support\StoredUploadPath;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class StoredUploadPathTest extends CIUnitTestCase
{
    private mixed $savedLegacyRoot = null;
    private bool $hadLegacyRoot = false;
    private string|false $savedLegacyRootGetenv = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadLegacyRoot         = array_key_exists('legacy.root', $_ENV);
        $this->savedLegacyRoot       = $_ENV['legacy.root'] ?? null;
        $this->savedLegacyRootGetenv = getenv('legacy.root');
    }

    protected function tearDown(): void
    {
        putenv('legacy.root');
        unset($_ENV['legacy.root'], $_SERVER['legacy.root']);

        if ($this->hadLegacyRoot) {
            $_ENV['legacy.root'] = $this->savedLegacyRoot;
        }
        if ($this->savedLegacyRootGetenv !== false) {
            putenv('legacy.root=' . $this->savedLegacyRootGetenv);
            $_SERVER['legacy.root'] = $this->savedLegacyRootGetenv;
        }

        parent::tearDown();
    }

    public function testRelativeRejectsAbsoluteAndTraversal(): void
    {
        $this->assertNull(StoredUploadPath::relative('/etc/passwd'));
        $this->assertNull(StoredUploadPath::relative('../upload/x.jpg'));
        $this->assertNull(StoredUploadPath::relative('upload/../secret.jpg'));
        $this->assertNull(StoredUploadPath::relative(''));
        $this->assertNull(StoredUploadPath::relative('foto.jpg'));
    }

    public function testRelativeAcceptsUploadPath(): void
    {
        $this->assertSame(
            'upload/2026/08/1-depan.jpg',
            StoredUploadPath::relative('upload/2026/08/1-depan.jpg'),
        );
    }

    public function testAbsoluteStaysUnderRoot(): void
    {
        $root = sys_get_temp_dir();
        $full = StoredUploadPath::absolute($root, 'upload/2026/08/1-depan.jpg');

        $this->assertNotNull($full);
        $this->assertStringContainsString('/upload/2026/08/1-depan.jpg', str_replace('\\', '/', (string) $full));
        $this->assertNull(StoredUploadPath::absolute($root, '/tmp/evil.jpg'));
    }

    public function testWindowsBackslashPathsNormalizeCleanly(): void
    {
        putenv('legacy.root=C:\\sdp\\102sdp\\sdp\\');
        $_ENV['legacy.root']    = 'C:\\sdp\\102sdp\\sdp\\';
        $_SERVER['legacy.root'] = 'C:\\sdp\\102sdp\\sdp\\';

        $this->assertSame('C:/sdp/102sdp/sdp', StoredUploadPath::legacyRoot());

        $rootWithBackslashes = str_replace('/', '\\', sys_get_temp_dir()) . '\\';
        $full                = StoredUploadPath::absolute($rootWithBackslashes, 'upload\\2026\\08\\1-depan.jpg');

        $this->assertNotNull($full);
        $this->assertStringContainsString('/upload/2026/08/1-depan.jpg', str_replace('\\', '/', (string) $full));
    }

    public function testResolveLegacyRootAutoDetectsUptAndDevLayouts(): void
    {
        $sandbox = sys_get_temp_dir() . '/sdp-root-detect-' . uniqid('', true);
        $apiRoot = $sandbox . '/sdp-upt-api';
        $uptRoot = $sandbox . '/htdocs/www/sdp';
        $devRoot = $sandbox . '/102sdp/sdp';

        mkdir($apiRoot, 0777, true);

        try {
            // Default when neither exists: UPT layout ../htdocs/www/sdp
            $this->assertSame(
                str_replace('\\', '/', $uptRoot),
                StoredUploadPath::resolveLegacyRoot('', $apiRoot),
            );

            // Falls back to ../102sdp/sdp when only dev layout exists
            mkdir($devRoot, 0777, true);
            $this->assertSame(
                str_replace('\\', '/', $devRoot),
                StoredUploadPath::resolveLegacyRoot('', $apiRoot),
            );

            // Prefers ../htdocs/www/sdp when UPT layout exists
            mkdir($uptRoot, 0777, true);
            $this->assertSame(
                str_replace('\\', '/', $uptRoot),
                StoredUploadPath::resolveLegacyRoot('', $apiRoot),
            );
        } finally {
            $this->removeDirectory($sandbox);
        }
    }

    public function testResolveLegacyRootHandlesRelativeUnixAndWindowsPaths(): void
    {
        $this->assertSame(
            '/opt/SDP/htdocs/www/sdp',
            StoredUploadPath::resolveLegacyRoot('../htdocs/www/sdp', '/opt/SDP/sdp-upt-api'),
        );
        $this->assertSame(
            'C:/SDP/htdocs/www/sdp',
            StoredUploadPath::resolveLegacyRoot('..\\htdocs\\www\\sdp\\', 'C:\\SDP\\sdp-upt-api\\'),
        );
        $this->assertSame(
            'vfs://legacy',
            StoredUploadPath::resolveLegacyRoot('vfs://legacy', '/opt/SDP/sdp-upt-api'),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
