<?php

namespace App\Modules\Shared\Tenancy;

use CodeIgniter\Database\BaseConnection;

/**
 * Resolves which database a request should use.
 *
 * Modes (from Project Statements):
 * - single: one tenant, one database, one server (current UPT install)
 * - database_per_tenant: each tenant has its own database on a shared server
 * - shared_database: multiple tenants share one database (filter by tenant key)
 */
final class TenantContext
{
    public const MODE_SINGLE = 'single';
    public const MODE_DATABASE_PER_TENANT = 'database_per_tenant';
    public const MODE_SHARED_DATABASE = 'shared_database';

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

    public function apply(?string $tenantId): void
    {
        $this->tenantId = $tenantId === null || $tenantId === '' ? null : $tenantId;

        if ($this->mode() !== self::MODE_DATABASE_PER_TENANT || $this->tenantId === null) {
            return;
        }

        $prefix = (string) env('tenancy.databasePrefix', 'db_sdp_');
        $database = $prefix . $this->tenantId;

        /** @var BaseConnection $db */
        $db = db_connect();
        $db->setDatabase($database);
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
