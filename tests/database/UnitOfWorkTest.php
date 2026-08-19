<?php

use App\Modules\Shared\Database\UnitOfWork;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class UnitOfWorkTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetConnection();
        $this->rebuildProbeTable();
    }

    protected function tearDown(): void
    {
        $this->resetConnection();
        Database::forge()->dropTable('uow_probe', true);

        parent::tearDown();
    }

    public function testReturnsWorkResult(): void
    {
        $result = UnitOfWork::transactional(static fn (): int => 7);

        $this->assertSame(7, $result);
        $this->assertSame(0, db_connect()->transDepth);
    }

    public function testCommitPersistsWrites(): void
    {
        $result = UnitOfWork::transactional(function (): string {
            db_connect()->table('uow_probe')->insert(['name' => 'kept']);

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $this->countProbe());
        $this->assertSame(0, db_connect()->transDepth);
    }

    public function testExceptionRollsBackAndRethrows(): void
    {
        try {
            UnitOfWork::transactional(function (): void {
                db_connect()->table('uow_probe')->insert(['name' => 'lost']);
                throw new RuntimeException('identitas boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('identitas boom', $e->getMessage());
        }

        $this->assertSame(0, $this->countProbe());
        $this->assertSame(0, db_connect()->transDepth);
    }

    public function testFailedQueryRollsBackWithoutDbDebug(): void
    {
        $db       = db_connect();
        $previous = $this->getPrivateProperty($db, 'DBDebug');
        $this->setPrivateProperty($db, 'DBDebug', false);

        try {
            UnitOfWork::transactional(function (): void {
                db_connect()->table('uow_probe')->insert(['name' => 'lost']);
                db_connect()->query('INSERT INTO missing_table (name) VALUES (\'x\')');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Database transaction failed.', $e->getMessage());
        } finally {
            $this->setPrivateProperty($db, 'DBDebug', $previous);
        }

        $this->assertSame(0, $this->countProbe());
        $this->assertSame(0, db_connect()->transDepth);
    }

    public function testNestedSuccessCommitsOnce(): void
    {
        $result = UnitOfWork::transactional(function (): string {
            db_connect()->table('uow_probe')->insert(['name' => 'outer']);

            return UnitOfWork::transactional(function (): string {
                db_connect()->table('uow_probe')->insert(['name' => 'inner']);

                return 'nested';
            });
        });

        $this->assertSame('nested', $result);
        $this->assertSame(2, $this->countProbe());
        $this->assertSame(0, db_connect()->transDepth);
    }

    public function testNestedExceptionRollsBackOuterWrites(): void
    {
        try {
            UnitOfWork::transactional(function (): void {
                db_connect()->table('uow_probe')->insert(['name' => 'outer']);

                UnitOfWork::transactional(function (): void {
                    db_connect()->table('uow_probe')->insert(['name' => 'inner']);
                    throw new RuntimeException('inner failed');
                });
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('inner failed', $e->getMessage());
        }

        $this->assertSame(0, $this->countProbe());
        $this->assertSame(0, db_connect()->transDepth);
    }

    public function testNextCallWorksAfterRollback(): void
    {
        try {
            UnitOfWork::transactional(function (): void {
                db_connect()->table('uow_probe')->insert(['name' => 'lost']);
                throw new RuntimeException('first');
            });
        } catch (RuntimeException) {
        }

        UnitOfWork::transactional(function (): void {
            db_connect()->table('uow_probe')->insert(['name' => 'second']);
        });

        $this->assertSame(['second'], $this->probeNames());
        $this->assertSame(0, db_connect()->transDepth);
    }

    private function rebuildProbeTable(): void
    {
        $forge = Database::forge();
        $forge->dropTable('uow_probe', true);
        $forge->addField('id');
        $forge->addField([
            'name' => ['type' => 'varchar', 'constraint' => 32],
        ]);
        $forge->createTable('uow_probe');
    }

    private function resetConnection(): void
    {
        $db = db_connect();
        while ($db->transDepth > 0) {
            $db->transRollback();
        }
        $db->resetTransStatus();
    }

    private function countProbe(): int
    {
        return db_connect()->table('uow_probe')->countAllResults();
    }

    /**
     * @return list<string>
     */
    private function probeNames(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            db_connect()->table('uow_probe')->select('name')->orderBy('id')->get()->getResultArray(),
        );
    }
}
