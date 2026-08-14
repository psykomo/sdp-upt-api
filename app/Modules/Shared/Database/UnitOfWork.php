<?php

namespace App\Modules\Shared\Database;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Transaction boundary that supports nested (savepoint) scopes.
 *
 * A module can run its own atomic unit even when it is already inside
 * a parent/orchestrator transaction. CI4 issues SAVEPOINT when transDepth > 0.
 */
final class UnitOfWork
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function fromDefault(): self
    {
        return new self(db_connect());
    }

    /**
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $this->db->transBegin();

        try {
            $result = $work($this);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                throw new \RuntimeException('Database transaction failed.');
            }

            $this->db->transCommit();

            return $result;
        } catch (Throwable $e) {
            if ($this->db->transStatus() !== false || $this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Nested scope. Safe to call from a module that may already be inside
     * a parent transactional() call.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function nested(callable $work): mixed
    {
        return $this->transactional($work);
    }

    public function connection(): BaseConnection
    {
        return $this->db;
    }
}
