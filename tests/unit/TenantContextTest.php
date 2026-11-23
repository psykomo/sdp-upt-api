<?php

use App\Modules\Shared\Tenancy\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TenantContextTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearTenancyEnv();
    }

    protected function tearDown(): void
    {
        $this->clearTenancyEnv();

        parent::tearDown();
    }

    private function clearTenancyEnv(): void
    {
        putenv('tenancy.mode');
        putenv('tenancy.databasePrefix');
        unset($_ENV['tenancy.mode'], $_SERVER['tenancy.mode']);
        unset($_ENV['tenancy.databasePrefix'], $_SERVER['tenancy.databasePrefix']);
    }

    public function testDefaultModeIsSingle(): void
    {
        $this->assertSame(TenantContext::MODE_SINGLE, (new TenantContext())->mode());
    }

    public function testDefaultPrefixIsDb(): void
    {
        $this->assertSame('db', (new TenantContext())->prefix());
    }

    public function testNormalizeAcceptsUptKeys(): void
    {
        $this->assertSame('1', TenantContext::normalize('1'));
        $this->assertSame('093', TenantContext::normalize('093'));
        $this->assertSame('0501', TenantContext::normalize(' 0501 '));
    }

    public function testNormalizeRejectsUnsafeKeys(): void
    {
        $this->assertNull(TenantContext::normalize(''));
        $this->assertNull(TenantContext::normalize('../db093'));
        $this->assertNull(TenantContext::normalize('db-1'));
        $this->assertNull(TenantContext::normalize('db 1'));
    }

    public function testDatabaseNameUsesPrefixAndTenant(): void
    {
        $this->setEnv('tenancy.mode', TenantContext::MODE_DATABASE_PER_TENANT);

        $tenant = new TenantContext();
        $this->setPrivateProperty($tenant, 'tenantId', '093');

        $this->assertSame('db093', $tenant->databaseName());
    }

    public function testDatabaseNameWithExplicitPrefix(): void
    {
        $this->setEnv('tenancy.mode', TenantContext::MODE_DATABASE_PER_TENANT);
        $this->setEnv('tenancy.databasePrefix', 'db');

        $tenant = new TenantContext();
        $this->setPrivateProperty($tenant, 'tenantId', '001');

        $this->assertSame('db001', $tenant->databaseName());
    }

    public function testDatabasePerTenantWithoutIdFailsClosed(): void
    {
        $this->setEnv('tenancy.mode', TenantContext::MODE_DATABASE_PER_TENANT);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant tidak dikenali.');

        (new TenantContext())->apply(null);
    }

    public function testSingleModeIgnoresMissingTenant(): void
    {
        $this->setEnv('tenancy.mode', TenantContext::MODE_SINGLE);

        $tenant = new TenantContext();
        $tenant->apply(null);

        $this->assertNull($tenant->tenantId());
        $this->assertNull($tenant->databaseName());
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }
}
