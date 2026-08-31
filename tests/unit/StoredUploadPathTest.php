<?php

use App\Modules\Shared\Support\StoredUploadPath;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class StoredUploadPathTest extends CIUnitTestCase
{
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
}
