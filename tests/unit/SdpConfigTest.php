<?php

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Sdp;

/**
 * @internal
 */
final class SdpConfigTest extends CIUnitTestCase
{
    private const ENV_KEYS = [
        'sdp.tenancyMode',
        'sdp_tenancyMode',
        'sdp.tenancyDatabasePrefix',
        'sdp_tenancyDatabasePrefix',
        'sdp.jwtSecret',
        'sdp_jwtSecret',
        'sdp.jwtTtlHours',
        'sdp_jwtTtlHours',
        'sdp.legacyBaseURL',
        'sdp_legacyBaseURL',
        'sdp.legacyRoot',
        'sdp_legacyRoot',
        'sdp.frontendOrigin',
        'sdp_frontendOrigin',
        'sdp.publicLegacyHost',
        'sdp_publicLegacyHost',
        'sdp.publicApiHost',
        'sdp_publicApiHost',
        'sdp.publicFrontendHost',
        'sdp_publicFrontendHost',
        'tenancy.mode',
        'tenancy.databasePrefix',
        'jwt.secret',
        'jwt.ttlHours',
        'legacy.baseURL',
        'legacy.root',
        'frontend.origin',
        'public.legacyHost',
        'public.apiHost',
        'public.frontendHost',
    ];

    /**
     * @var array<string, array{env: bool, envVal: mixed, server: bool, serverVal: mixed, getenv: string|false}>
     */
    private array $savedEnv = [];

    private ?string $tempOverrideFile = null;

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

        Sdp::$localOverrideFile = sys_get_temp_dir() . '/sdp-local-missing-' . uniqid('', true) . '.php';
    }

    protected function tearDown(): void
    {
        BaseConfig::$override   = true;
        Sdp::$localOverrideFile = null;

        if ($this->tempOverrideFile !== null && is_file($this->tempOverrideFile)) {
            @unlink($this->tempOverrideFile);
        }
        $this->tempOverrideFile = null;

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

    public function testDefaultsWhenNoEnvOrOverridePresent(): void
    {
        $cfg = new Sdp();

        $this->assertSame('single', $cfg->tenancyMode);
        $this->assertSame('db', $cfg->tenancyDatabasePrefix);
        $this->assertSame('', $cfg->jwtSecret);
        $this->assertSame(8, $cfg->jwtTtlHours);
        $this->assertSame('http://127.0.0.1:8080', $cfg->legacyBaseURL);
        $this->assertSame('', $cfg->legacyRoot);
        $this->assertSame('http://127.0.0.1:8082', $cfg->frontendOrigin);
        $this->assertSame('sdp.caripasal.com', $cfg->publicLegacyHost);
        $this->assertSame('sdp-api.caripasal.com', $cfg->publicApiHost);
        $this->assertSame('sdp-front.caripasal.com', $cfg->publicFrontendHost);
    }

    public function testLocalOverrideAllowsOnlyLegacyRoot(): void
    {
        $this->tempOverrideFile = tempnam(sys_get_temp_dir(), 'sdp_local_');
        file_put_contents(
            $this->tempOverrideFile,
            "<?php\nreturn [\n"
            . "    'legacyRoot' => ' /opt/sdp/102sdp/sdp ',\n"
            . "    'jwtSecret' => 'injected-secret',\n"
            . "    'tenancyMode' => 'shared_database',\n"
            . "    'legacyBaseURL' => 'http://evil.example',\n"
            . "    'frontendOrigin' => 'http://evil.example:8082',\n"
            . "];\n"
        );
        Sdp::$localOverrideFile = $this->tempOverrideFile;

        $cfg = new Sdp();

        $this->assertSame('/opt/sdp/102sdp/sdp', $cfg->legacyRoot);
        $this->assertSame('', $cfg->jwtSecret);
        $this->assertSame('single', $cfg->tenancyMode);
        $this->assertSame('http://127.0.0.1:8080', $cfg->legacyBaseURL);
        $this->assertSame('http://127.0.0.1:8082', $cfg->frontendOrigin);
    }

    public function testLegacyAliasesAndCanonicalPrecedence(): void
    {
        $this->tempOverrideFile = tempnam(sys_get_temp_dir(), 'sdp_local_');
        file_put_contents(
            $this->tempOverrideFile,
            "<?php\nreturn ['legacyRoot' => '/from/local-file'];\n"
        );
        Sdp::$localOverrideFile = $this->tempOverrideFile;

        // Blank legacy.root must not clobber local override file.
        $this->setEnv('legacy.root', '');
        // Explicit empty legacy.baseURL disables legacy redirects.
        $this->setEnv('legacy.baseURL', '');
        $this->setEnv('jwt.secret', 'legacy-secret');
        $this->setEnv('jwt.ttlHours', '12');
        $this->setEnv('tenancy.mode', 'shared_database');

        // Canonical sdp.* takes precedence over legacy alias.
        $this->setEnv('sdp.jwtSecret', 'canonical-secret');

        $cfg = new Sdp();

        $this->assertSame('/from/local-file', $cfg->legacyRoot);
        $this->assertSame('', $cfg->legacyBaseURL);
        $this->assertSame('canonical-secret', $cfg->jwtSecret);
        $this->assertSame(12, $cfg->jwtTtlHours);
        $this->assertSame('shared_database', $cfg->tenancyMode);
    }

    public function testLockedOverrideIgnoresEnvAndLegacyAliases(): void
    {
        $this->tempOverrideFile = tempnam(sys_get_temp_dir(), 'sdp_local_');
        file_put_contents(
            $this->tempOverrideFile,
            "<?php\nreturn ['legacyRoot' => '/from/local-file'];\n"
        );
        Sdp::$localOverrideFile = $this->tempOverrideFile;

        $this->setEnv('jwt.secret', 'tampered-secret');
        $this->setEnv('sdp.jwtSecret', 'tampered-canonical');
        $this->setEnv('tenancy.mode', 'shared_database');
        $this->setEnv('legacy.baseURL', 'http://evil.example:9999');

        BaseConfig::$override = false;
        try {
            $cfg = new Sdp();
        } finally {
            BaseConfig::$override = true;
        }

        $this->assertSame('/from/local-file', $cfg->legacyRoot);
        $this->assertSame('', $cfg->jwtSecret);
        $this->assertSame('single', $cfg->tenancyMode);
        $this->assertSame('http://127.0.0.1:8080', $cfg->legacyBaseURL);
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
