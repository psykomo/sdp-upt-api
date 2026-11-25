<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Sdp extends BaseConfig
{
    public string $tenancyMode = 'single';

    public string $tenancyDatabasePrefix = 'db';

    public string $jwtSecret = '';

    public int $jwtTtlHours = 8;

    public string $legacyBaseURL = 'http://127.0.0.1:8080';

    /**
     * Path to the legacy PHP tree that owns upload/.
     * Empty auto-detects ../htdocs/www/sdp (UPT) then ../102sdp/sdp (dev).
     * Accepts a relative path (from sdp-upt-api/) or an absolute path.
     */
    public string $legacyRoot = '';

    public string $frontendOrigin = 'http://127.0.0.1:8082';

    public string $publicLegacyHost = 'sdp.caripasal.com';

    public string $publicApiHost = 'sdp-api.caripasal.com';

    public string $publicFrontendHost = 'sdp-front.caripasal.com';

    /**
     * Optional override path for tests.
     */
    public static ?string $localOverrideFile = null;

    public function __construct()
    {
        $this->loadLocalOverride();

        if (! static::$override) {
            return;
        }

        parent::__construct();

        $this->applyLegacyAliases();
    }

    public static function current(): self
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            return new self();
        }

        /** @var self $config */
        $config = config(self::class);

        return $config;
    }

    public static function defaultLocalOverridePath(): string
    {
        return dirname(rtrim(ROOTPATH, '/\\')) . DIRECTORY_SEPARATOR . 'sdp-local.php';
    }

    private function loadLocalOverride(): void
    {
        $path = self::$localOverrideFile ?? self::defaultLocalOverridePath();
        if (! is_file($path)) {
            return;
        }

        $data = include $path;
        if (! is_array($data)) {
            return;
        }

        // Only non-secret legacyRoot is allowlisted for per-UPT local overrides.
        if (isset($data['legacyRoot']) && is_string($data['legacyRoot'])) {
            $this->legacyRoot = trim($data['legacyRoot']);
        }
    }

    private function applyLegacyAliases(): void
    {
        $aliases = [
            'tenancyMode'           => 'tenancy.mode',
            'tenancyDatabasePrefix' => 'tenancy.databasePrefix',
            'jwtSecret'             => 'jwt.secret',
            'jwtTtlHours'           => 'jwt.ttlHours',
            'legacyBaseURL'         => 'legacy.baseURL',
            'legacyRoot'            => 'legacy.root',
            'frontendOrigin'        => 'frontend.origin',
            'publicLegacyHost'      => 'public.legacyHost',
            'publicApiHost'         => 'public.apiHost',
            'publicFrontendHost'    => 'public.frontendHost',
        ];

        foreach ($aliases as $property => $legacyKey) {
            if ($this->getEnvValue($property, self::class, 'sdp') !== null) {
                continue;
            }

            $value = $this->readRawEnv($legacyKey);
            if ($value === null) {
                continue;
            }

            if ($property === 'legacyRoot') {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $this->legacyRoot = $trimmed;
                }
                continue;
            }

            if ($property === 'jwtTtlHours') {
                $this->jwtTtlHours = (int) $value;
                continue;
            }

            $this->{$property} = $value;
        }
    }

    private function readRawEnv(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return trim((string) $_ENV[$key], '\'"');
        }

        if (array_key_exists($key, $_SERVER)) { // @phpstan-ignore codeigniter.superglobalsOffsetAccess
            return trim((string) $_SERVER[$key], '\'"'); // @phpstan-ignore codeigniter.superglobalsOffsetAccess
        }

        $value = getenv($key);
        if ($value === false) {
            return null;
        }

        return trim($value, '\'"');
    }
}
