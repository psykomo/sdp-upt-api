<?php

use App\Modules\Shared\Tenancy\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TenantContextTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        putenv('tenancy.mode');
        putenv('tenancy.databasePrefix');
        unset($_ENV['tenancy.mode'], $_SERVER['tenancy.mode']);
        unset($_ENV['tenancy.databasePrefix'], $_SERVER['tenancy.databasePrefix']);

        parent::tearDown();
    }

    public function testDefaultModeIsSingle(): void
    {
        $this->assertSame(TenantContext::MODE_SINGLE, (new TenantContext())->mode());
    }

    public function testNormalizeAcceptsUptKeys(): void
    {
        $this->assertSame('1', TenantContext::normalize('1'));
        $this->assertSame('0501', TenantContext::normalize(' 0501 '));
        $this->assertSame('upt_1', TenantContext::normalize('upt_1'));
    }

    public function testNormalizeRejectsUnsafeKeys(): void
    {
        $this->assertNull(TenantContext::normalize(''));
        $this->assertNull(TenantContext::normalize('../upt_1'));
        $this->assertNull(TenantContext::normalize('upt-1'));
        $this->assertNull(TenantContext::normalize('upt 1'));
    }

    public function testDatabaseNameUsesPrefixAndTenant(): void
    {
        $this->setEnv('tenancy.mode', TenantContext::MODE_DATABASE_PER_TENANT);
        $this->setEnv('tenancy.databasePrefix', 'upt_');

        $tenant = new TenantContext();
        $this->setPrivateProperty($tenant, 'tenantId', '1');

        $this->assertSame('upt_1', $tenant->databaseName());
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
