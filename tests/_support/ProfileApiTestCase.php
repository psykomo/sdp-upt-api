<?php

namespace Tests\Support;

use App\Modules\Shared\Auth\AuthContext;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Config as DatabaseConfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;

abstract class ProfileApiTestCase extends CIUnitTestCase
{
    use FeatureTestTrait;

    private static bool $dbReady = false;
    private static ?string $skipReason = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMariaDbTestingConfig();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        AuthContext::clear();
        $this->clearTenancyEnv();

        if (trim((string) env('jwt.secret', '')) === '') {
            $this->setEnv('jwt.secret', 'sdp-feature-test-secret');
        }
    }

    protected function tearDown(): void
    {
        AuthContext::clear();
        $this->clearTenancyEnv();
        Services::reset(true);

        parent::tearDown();
    }

    protected function useProfile(string $tenancyMode, string $database): void
    {
        $this->setEnv('tenancy.mode', $tenancyMode);
        $this->setEnv('tenancy.databasePrefix', 'db');
        db_connect()->setDatabase($database);
    }

    /**
     * @return array{token: string, user: array<string, mixed>, response: \CodeIgniter\Test\TestResponse}
     */
    protected function loginAs(string $username, string $password, ?string $tenantId = null): array
    {
        $payload = ['username' => $username, 'password' => $password];
        if ($tenantId !== null) {
            $payload['tenantId'] = $tenantId;
        }

        $res = $this->apiPost('/api/v1/auth/login', $payload);
        $res->assertStatus(200);
        $json = json_decode($res->getJSON(), true);

        return [
            'token'    => (string) ($json['data']['token'] ?? ''),
            'user'     => (array) ($json['data']['user'] ?? []),
            'response' => $res,
        ];
    }

    protected function apiGet(string $path, ?string $token = null, array $headers = []): \CodeIgniter\Test\TestResponse
    {
        AuthContext::clear();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('get', ltrim($path, '/'));
    }

    protected function apiPost(string $path, array $payload = [], ?string $token = null, array $headers = []): \CodeIgniter\Test\TestResponse
    {
        AuthContext::clear();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('post', ltrim($path, '/'), $payload);
    }

    protected function apiDelete(string $path, ?string $token = null, array $headers = []): \CodeIgniter\Test\TestResponse
    {
        AuthContext::clear();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('delete', ltrim($path, '/'));
    }

    protected function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }

    protected function clearTenancyEnv(): void
    {
        putenv('tenancy.mode');
        putenv('tenancy.databasePrefix');
        unset($_ENV['tenancy.mode'], $_SERVER['tenancy.mode']);
        unset($_ENV['tenancy.databasePrefix'], $_SERVER['tenancy.databasePrefix']);
    }

    private function ensureMariaDbTestingConfig(): void
    {
        if (self::$dbReady || self::$skipReason !== null) {
            return;
        }

        $host = (string) env('database.default.hostname', '127.0.0.1');
        $port = (int) env('database.default.port', 3307);
        $user = (string) env('database.default.username', 'sdp');
        $pass = (string) env('database.default.password', 'sdp_local');

        $mysqli = @new \mysqli($host, $user, $pass, '', $port);
        if ($mysqli->connect_errno) {
            self::$skipReason = sprintf('MariaDB prod-sim not reachable on %s:%d (%s)', $host, $port, $mysqli->connect_error);
            return;
        }

        foreach (['db_sdp', 'db093', 'db001', 'sdp_pusat'] as $schema) {
            $res = $mysqli->query(sprintf("SHOW DATABASES LIKE '%s'", $mysqli->real_escape_string($schema)));
            if (! $res || $res->num_rows === 0) {
                $mysqli->close();
                self::$skipReason = sprintf('Prod-sim schema "%s" missing. Run ./scripts/dev-mariadb-prod-sim.sh', $schema);
                return;
            }
        }
        $mysqli->close();

        $dbConfig = new Database();
        $dbConfig->tests = [
            'DSN'          => '',
            'hostname'     => $host,
            'username'     => $user,
            'password'     => $pass,
            'database'     => 'db_sdp',
            'DBDriver'     => 'MySQLi',
            'DBPrefix'     => '',
            'pConnect'     => false,
            'DBDebug'      => true,
            'charset'      => 'utf8mb4',
            'DBCollat'     => 'utf8mb4_general_ci',
            'swapPre'      => '',
            'encrypt'      => false,
            'compress'     => false,
            'strictOn'     => false,
            'failover'     => [],
            'port'         => $port,
            'numberNative' => false,
            'foundRows'    => false,
            'dateFormat'   => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
        ];
        Factories::injectMock('config', 'Database', $dbConfig);

        // Reset cached SQLite test connection if unit tests ran in the same PHPUnit process.
        $resetter = new class () extends DatabaseConfig {
            public static function resetTests(): void
            {
                unset(static::$instances['tests']);
            }
        };
        $resetter::resetTests();

        self::$dbReady = true;
    }
}
