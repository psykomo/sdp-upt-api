<?php

use App\Modules\Shared\Support\DebugbarHistory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DebugbarHistoryTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sdp-debugbar-' . uniqid('', true);
        mkdir($this->dir, 0777, true);

        foreach (['100', '101', '102', '103', '104'] as $stamp) {
            file_put_contents($this->dir . '/debugbar_' . $stamp . '.json', '{}');
        }
        file_put_contents($this->dir . '/index.html', '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    public function testKeepsNewestUpToLimit(): void
    {
        DebugbarHistory::prune($this->dir, 2);

        $left = $this->jsonNames();
        sort($left);

        $this->assertSame(['debugbar_103.json', 'debugbar_104.json'], $left);
        $this->assertFileExists($this->dir . '/index.html');
    }

    public function testZeroDeletesAllDumps(): void
    {
        DebugbarHistory::prune($this->dir, 0);

        $this->assertSame([], $this->jsonNames());
        $this->assertFileExists($this->dir . '/index.html');
    }

    public function testNegativeKeepsUnlimited(): void
    {
        DebugbarHistory::prune($this->dir, -1);

        $this->assertCount(5, $this->jsonNames());
    }

    /**
     * @return list<string>
     */
    private function jsonNames(): array
    {
        $names = [];
        foreach (glob($this->dir . '/debugbar_*.json') ?: [] as $file) {
            $names[] = basename($file);
        }

        return $names;
    }
}
