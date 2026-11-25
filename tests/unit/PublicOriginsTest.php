<?php

use App\Modules\Shared\Support\PublicOrigins;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;

/**
 * @internal
 */
final class PublicOriginsTest extends CIUnitTestCase
{
    private const ENV_KEYS = [
        'sdp.legacyBaseURL',
        'sdp_legacyBaseURL',
        'sdp.frontendOrigin',
        'sdp_frontendOrigin',
        'legacy.baseURL',
        'frontend.origin',
    ];

    /**
     * @var array<string, array{env: bool, envVal: mixed, server: bool, serverVal: mixed, getenv: string|false}>
     */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedEnv = [];
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = [
                'env'       => array_key_exists($key, $_ENV),
                'envVal'    => $_ENV[$key] ?? null,
                'server'    => array_key_exists($key, $_SERVER),
                'serverVal' => $_SERVER[$key] ?? null,
                'getenv'    => getenv($key),
            ];
            $this->unsetEnv($key);
        }

        Services::reset(true);
    }

    protected function tearDown(): void
    {
        Services::reset(true);

        foreach (self::ENV_KEYS as $key) {
            $this->unsetEnv($key);
            $saved = $this->savedEnv[$key] ?? null;
            if ($saved === null) {
                continue;
            }
            if ($saved['env']) {
                $_ENV[$key] = $saved['envVal'];
            }
            if ($saved['server']) {
                $_SERVER[$key] = $saved['serverVal'];
            }
            if ($saved['getenv'] !== false) {
                putenv($key . '=' . $saved['getenv']);
            }
        }

        parent::tearDown();
    }

    public function testPort80LegacyBaseRewritesLanHostWithoutPort(): void
    {
        $this->setEnv('legacy.baseURL', 'http://127.0.0.1');
        $this->setEnv('frontend.origin', 'http://127.0.0.1:8082');
        $this->withPublicHost('192.168.1.2:8082');

        $this->assertTrue(PublicOrigins::hasLegacyBase());
        $this->assertSame('http://192.168.1.2', PublicOrigins::legacyBase());
        $this->assertSame('http://192.168.1.2:8082', PublicOrigins::frontendBase());
    }

    public function testPort8080LegacyBaseRewritesLanHostWithPort(): void
    {
        $this->setEnv('legacy.baseURL', 'http://127.0.0.1:8080');
        $this->withPublicHost('192.168.1.2:8082');

        $this->assertTrue(PublicOrigins::hasLegacyBase());
        $this->assertSame('http://192.168.1.2:8080', PublicOrigins::legacyBase());
    }

    public function testEmptyLegacyBaseDisablesLegacyHost(): void
    {
        $this->setEnv('legacy.baseURL', '');
        $this->withPublicHost('192.168.1.2:8082');

        $this->assertFalse(PublicOrigins::hasLegacyBase());
        $this->assertSame('', PublicOrigins::legacyBase());
    }

    private function withPublicHost(string $host): void
    {
        $request = Services::incomingrequest(new App(), false);
        $request->setHeader('X-SDP-Public-Host', $host);
        Services::injectMock('request', $request);
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
