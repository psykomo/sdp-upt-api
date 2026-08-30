<?php

declare(strict_types=1);

namespace Tests\Unit\Identitas;

use App\Modules\Identitas\Repositories\IdentitasRepositoryInterface;
use App\Modules\Identitas\Support\IdentitasPhotoResolver;
use App\Modules\Shared\Auth\CurrentUser;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

final class IdentitasPhotoResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('legacy.root');
        unset($_ENV['legacy.root'], $_SERVER['legacy.root']);
        parent::tearDown();
    }

    private function withLegacyRoot(string $root): void
    {
        putenv('legacy.root=' . $root);
        $_ENV['legacy.root']    = $root;
        $_SERVER['legacy.root'] = $root;
    }

    public function testUptProfileSkipsMutasiLookup(): void
    {
        $repo = $this->createMock(IdentitasRepositoryInterface::class);
        $repo->expects($this->never())->method('mutasiPhotoRows');

        $resolver = new IdentitasPhotoResolver($repo);
        $user     = new CurrentUser('u1', 'role', 'Name', '1', false, false, 'UPT1', 'UPT', []);
        $row      = ['FOTO_DEPAN' => 'upload/upt1/depan.jpg'];

        $resolved = $resolver->resolveRow($row, 'NO001', $user);

        $this->assertSame('upload/upt1/depan.jpg', $resolved['FOTO_DEPAN']);
    }

    public function testHqUsesExistingLocalFile(): void
    {
        $root = vfsStream::setup('legacy', null, [
            'upload' => [
                'upt2' => ['depan.jpg' => 'photo-bytes'],
            ],
        ]);
        $this->withLegacyRoot($root->url());

        $repo = $this->createMock(IdentitasRepositoryInterface::class);
        $repo->method('mutasiPhotoRows')->willReturn([]);

        $resolver = new IdentitasPhotoResolver($repo);
        $user     = new CurrentUser('u1', 'role', 'Name', '1', true, false, null, null, []);
        $row      = ['FOTO_DEPAN' => 'upload/upt2/depan.jpg'];

        $resolved = $resolver->resolveRow($row, 'NO001', $user);

        $this->assertSame('upload/upt2/depan.jpg', $resolved['FOTO_DEPAN']);
    }

    public function testHqRewritesMissingPathUsingMutasiUptAsal(): void
    {
        $root = dirname(__DIR__, 2) . '/build/photo-resolver-test';
        if (is_dir($root)) {
            $this->removeDirectory($root);
        }
        mkdir($root . '/upload/upt-asal', 0777, true);
        file_put_contents($root . '/upload/upt-asal/depan.jpg', 'photo-bytes');
        $this->withLegacyRoot($root);

        $repo = $this->createMock(IdentitasRepositoryInterface::class);
        $repo->method('mutasiPhotoRows')->willReturn([
            [
                'ID_UPT'      => 'upt-dest',
                'ID_UPT_ASAL' => 'upt-asal',
                'FOTO_DEPAN'  => '',
            ],
        ]);

        $resolver = new IdentitasPhotoResolver($repo);
        $user     = new CurrentUser('u1', 'role', 'Name', '1', false, true, 'KW1', 'Kanwil', []);
        $row      = ['FOTO_DEPAN' => 'upload/upt-dest/depan.jpg'];

        $resolved = $resolver->resolveRow($row, 'NO001', $user);

        $this->assertSame('upload/upt-asal/depan.jpg', $resolved['FOTO_DEPAN']);

        $this->removeDirectory($root);
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
