<?php

namespace App\Modules\Shared\Tenancy;

/**
 * Resolves which database a request should use.
 *
 * Modes: docs/TENANCY.md
 * - single: one tenant, one database, one server (current UPT install)
 * - database_per_tenant: each UPT has its own schema on one MariaDB (upt_1, upt_2)
 * - shared_database: multiple tenants share one database (filter by UPT key)
 */
final class TenantContext
{
    public const MODE_SINGLE = 'single';
    public const MODE_DATABASE_PER_TENANT = 'database_per_tenant';
    public const MODE_SHARED_DATABASE = 'shared_database';

    private const DEFAULT_PREFIX = 'upt_';

    private ?string $tenantId = null;

    public function mode(): string
    {
        $mode = (string) env('tenancy.mode', self::MODE_SINGLE);

        return in_array($mode, [
            self::MODE_SINGLE,
            self::MODE_DATABASE_PER_TENANT,
            self::MODE_SHARED_DATABASE,
        ], true) ? $mode : self::MODE_SINGLE;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function prefix(): string
    {
        $prefix = (string) env('tenancy.databasePrefix', self::DEFAULT_PREFIX);

        return $prefix === '' ? self::DEFAULT_PREFIX : $prefix;
    }

    public function databaseName(): ?string
    {
        if ($this->mode() !== self::MODE_DATABASE_PER_TENANT || $this->tenantId === null) {
            return null;
        }

        return $this->prefix() . $this->tenantId;
    }

    /**
     * @throws \RuntimeException when database_per_tenant cannot resolve a safe schema
     */
    public function apply(?string $tenantId): void
    {
        $this->tenantId = self::normalize($tenantId);

        if ($this->mode() !== self::MODE_DATABASE_PER_TENANT) {
            return;
        }

        if ($this->tenantId === null) {
            throw new \RuntimeException('Tenant tidak dikenali.', 422);
        }

        db_connect()->setDatabase($this->databaseName());
    }

    public static function normalize(?string $raw): ?string
    {
        $id = trim((string) $raw);
        if ($id === '' || preg_match('/^[A-Za-z0-9_]+$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    /**
     * Extra WHERE fragment for shared-database tenancy.
     * Legacy schema typically scopes via perkara.ID_UPT rather than a tenant_id column.
     */
    public function sharedDatabaseUptId(): ?string
    {
        if ($this->mode() !== self::MODE_SHARED_DATABASE) {
            return null;
        }

        return $this->tenantId;
    }
}
