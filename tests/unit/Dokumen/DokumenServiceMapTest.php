<?php

declare(strict_types=1);

namespace Tests\Unit\Dokumen;

use App\Modules\Dokumen\Services\DokumenService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DokumenServiceMapTest extends TestCase
{
    public function testMapRowIncludesSameOriginUrls(): void
    {
        $method = new \ReflectionMethod(DokumenService::class, 'mapRow');
        $method->setAccessible(true);

        $mapped = $method->invoke(new DokumenService(), [
            'ID_DOKUMEN' => 'DOC001',
            'JUDUL'      => 'SK',
            'KETERANGAN' => 'Lampiran',
            'NAMA_FILE'  => 'sk.pdf',
            'MIME_TYPE'  => 'application/pdf',
        ]);

        $this->assertSame('DOC001', $mapped['id']);
        $this->assertSame('/api/v1/documents/DOC001/download', $mapped['downloadUrl']);
        $this->assertSame('/api/v1/documents/DOC001/view', $mapped['viewUrl']);
    }
}
