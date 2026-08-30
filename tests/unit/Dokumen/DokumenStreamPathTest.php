<?php

declare(strict_types=1);

namespace Tests\Unit\Dokumen;

use App\Modules\Identitas\Support\StoredUploadPath;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DokumenStreamPathTest extends CIUnitTestCase
{
    public function testDokumenRelativePathIsAccepted(): void
    {
        $this->assertSame(
            'upload/dokumen/001/2026/08/sample.pdf',
            StoredUploadPath::relative('upload/dokumen/001/2026/08/sample.pdf'),
        );
    }

    public function testTraversalOutsideUploadRootIsRejected(): void
    {
        $root = sys_get_temp_dir();
        $this->assertNull(StoredUploadPath::absolute($root, 'upload/../secret.pdf'));
    }
}
