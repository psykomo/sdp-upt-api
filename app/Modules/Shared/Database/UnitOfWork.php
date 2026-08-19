<?php

namespace App\Modules\Shared\Database;

use Throwable;

/**
 * Transaction boundary on the default shared connection (`db_connect()`).
 *
 * Safe to call from a module that is already inside a parent transactional()
 * call: CI4 only begins, commits, or rolls back the outermost scope.
 */
final class UnitOfWork
{
    private function __construct()
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function transactional(callable $work): mixed
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $result = $work();

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new \RuntimeException('Database transaction failed.');
            }

            $db->transCommit();

            return $result;
        } catch (Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }
}
