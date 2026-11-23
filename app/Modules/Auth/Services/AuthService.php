<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Support\PortedMenuRegistry;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\PublicOrigins;
use App\Modules\Shared\Tenancy\TenantContext;

final class AuthService
{
    public function __construct(
        private readonly JwtService $jwt = new JwtService(),
        private readonly SiteContextService $site = new SiteContextService(),
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    /**
     * @return array{token: string, user: array<string, mixed>}
     */
    public function login(string $username, string $password, ?string $tenantId = null): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new \RuntimeException('Masukkan username & password.', 422);
        }

        (new TenantContext())->apply($tenantId);

        $failed = $this->recentFailedCount($username);
        if ($failed >= 5) {
            throw new \RuntimeException(
                'Mohon maaf, anda sudah 5 kali gagal login dalam 15 menit terakhir, harap tunggu 15 menit lagi kemudian silahkan login kembali.',
                429
            );
        }

        $row = db_connect()->table('pengguna')
            ->select('pengguna.ID_ROLE, pengguna.ID_USER, pengguna.KANWIL, pengguna.LEVEL, level.NAMA AS NAMA_LEVEL, pengguna.NAMA, pengguna.NIP')
            ->join('level', 'pengguna.LEVEL = level.ID')
            ->where('pengguna.ID_USER', $username)
            ->where('pengguna.PASSWORD', md5($password))
            ->where('pengguna.IS_DELETE', 0)
            ->where('pengguna.IS_APPROVE', 1)
            ->get()
            ->getRowArray();

        if (! $row) {
            $this->logLogin($username, 'Failed');
            throw new \RuntimeException(
                'Username dan Password tidak cocok atau akun anda belum diaktivasi oleh Administrator',
                401
            );
        }

        $user  = $this->site->hydrateUser($row, $tenantId);
        $token = $this->jwt->encode($this->claimsFrom($user, $row));
        $this->logLogin($user->id, 'Login');

        db_connect()->table('pengguna')
            ->where('ID_USER', $user->id)
            ->update(['LAST_LOGIN' => date('Y-m-d')]);

        return [
            'token' => $token,
            'user'  => $this->publicUser($user, $row),
        ];
    }

    /**
     * Exchanges a short-lived handover JWT (or existing session JWT) for a fresh session JWT.
     */
    public function exchangeHandoverToken(string $token): string
    {
        $claims = $this->jwt->decode($token);
        $typ    = (string) ($claims['typ'] ?? JwtService::TYP_SESSION);
        if (! in_array($typ, [JwtService::TYP_HANDOVER, JwtService::TYP_SESSION], true)) {
            throw new \RuntimeException('Token tidak valid.', 401);
        }

        $user = $this->userFromClaims($claims);
        if ($user->id === '') {
            throw new \RuntimeException('Token tidak valid.', 401);
        }

        unset($claims['typ'], $claims['iat'], $claims['nbf'], $claims['exp'], $claims['iss']);

        return $this->jwt->encode($claims);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function userFromClaims(array $claims): CurrentUser
    {
        return new CurrentUser(
            id: (string) ($claims['sub'] ?? ''),
            role: (string) ($claims['role'] ?? ''),
            name: (string) ($claims['name'] ?? ''),
            level: (string) ($claims['level'] ?? ''),
            isPusat: (bool) ($claims['isPusat'] ?? false),
            isKanwil: (bool) ($claims['isKanwil'] ?? false),
            uptId: isset($claims['uptId']) ? (string) $claims['uptId'] : null,
            uptName: isset($claims['uptName']) ? (string) $claims['uptName'] : null,
            claims: $claims,
        );
    }

    /**
     * @return array{token: string, user: array<string, mixed>}
     */
    public function setWorkingScope(CurrentUser $user, ?string $filterUptId, ?string $filterKanwilId): array
    {
        if (! $user->isHq()) {
            throw new \RuntimeException('Mohon maaf anda tidak berhak mengubah filter wilayah.', 403);
        }

        $filterUptId    = $this->normalizeFilter($filterUptId);
        $filterKanwilId = $this->normalizeFilter($filterKanwilId);

        if ($filterUptId !== null) {
            $upt = db_connect()->table('upt')
                ->select('ID_UPT, KANWIL')
                ->where('ID_UPT', $filterUptId)
                ->get()
                ->getRowArray();
            if ($upt === null) {
                throw new \RuntimeException('ID UPT tidak dikenali.', 422);
            }

            if ($user->isKanwil && (string) ($upt['KANWIL'] ?? '') !== (string) $user->uptId) {
                throw new \RuntimeException('UPT di luar Kanwil anda.', 403);
            }

            if ($filterKanwilId !== null && (string) ($upt['KANWIL'] ?? '') !== $filterKanwilId) {
                throw new \RuntimeException('UPT tidak berada di Kanwil yang dipilih.', 422);
            }
        }

        if ($filterKanwilId !== null) {
            $kanwil = db_connect()->table('kanwil')
                ->select('KODE')
                ->where('KODE', $filterKanwilId)
                ->get()
                ->getRowArray();
            if ($kanwil === null) {
                throw new \RuntimeException('Kode Kanwil tidak dikenali.', 422);
            }
        }

        $claims = $user->claims;
        if ($filterUptId === null) {
            unset($claims['sessionFilterUptId']);
        } else {
            $claims['sessionFilterUptId'] = $filterUptId;
        }

        if ($filterKanwilId === null) {
            unset($claims['sessionFilterKanwilId']);
        } else {
            $claims['sessionFilterKanwilId'] = $filterKanwilId;
        }

        $updated = $this->userFromClaims($claims);
        $token   = $this->jwt->encode($claims);

        return [
            'token' => $token,
            'user'  => $this->publicUser($updated),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicUser(CurrentUser $user, ?array $row = null): array
    {
        return [
            'id'       => $user->id,
            'role'     => $user->role,
            'name'     => $user->name,
            'level'    => $user->level,
            'levelName'=> $row['NAMA_LEVEL'] ?? ($user->claims['levelName'] ?? null),
            'nip'      => $row['NIP'] ?? ($user->claims['nip'] ?? null),
            'isPusat'  => $user->isPusat,
            'isKanwil' => $user->isKanwil,
            'uptId'    => $user->uptId,
            'uptName'  => $user->uptName,
            'sessionFilterUptId'    => $user->claims['sessionFilterUptId'] ?? null,
            'sessionFilterKanwilId' => $user->claims['sessionFilterKanwilId'] ?? null,
        ];
    }

    /**
     * Port of Login::logindp for Hapus. Officer is already on a JWT.
     *
     * @return array{grant: string, supervisor: array<string, mixed>}
     */
    public function issueHapusGrant(CurrentUser $officer, string $username, string $password, string $nomorInduk): array
    {
        return $this->issueSupervisorGrant(
            $officer,
            $username,
            $password,
            RbacService::MENU_IDENTITAS,
            $nomorInduk,
            'delete',
        );
    }

    /**
     * Port of Login::logindp for any dual-password protected action.
     *
     * @return array{grant: string, supervisor: array<string, mixed>}
     */
    public function issueSupervisorGrant(
        CurrentUser $officer,
        string $username,
        string $password,
        string $menuId,
        string $resourceKey,
        string $action = 'delete',
    ): array {
        $username    = trim($username);
        $menuId      = trim($menuId);
        $resourceKey = trim($resourceKey);
        $action      = trim($action) ?: 'delete';

        if ($username === '' || $password === '' || $menuId === '' || $resourceKey === '') {
            throw new \RuntimeException('Masukkan username & password.', 422);
        }

        $levels = $this->supervisorLevels($officer);
        if ($username === $officer->role && ! in_array($officer->level, $levels, true)) {
            throw new \RuntimeException('Maaf anda tidak memiliki akses.', 403);
        }

        $row = db_connect()->table('pengguna')
            ->select('pengguna.ID_ROLE, pengguna.ID_USER, pengguna.KANWIL, pengguna.LEVEL, level.NAMA AS NAMA_LEVEL, pengguna.NAMA, pengguna.NIP, pengguna.IP_USER')
            ->join('level', 'pengguna.LEVEL = level.ID')
            ->where('pengguna.ID_USER', $username)
            ->where('pengguna.PASSWORD', md5($password))
            ->where('pengguna.IS_DELETE', 0)
            ->where('pengguna.IS_APPROVE', 1)
            ->get()
            ->getRowArray();

        if (! $row) {
            $this->logLogin($username, 'Failed');
            throw new \RuntimeException('Username dan Password tidak cocok', 401);
        }

        if (! in_array((string) $row['LEVEL'], $levels, true)) {
            throw new \RuntimeException('Anda BUKAN Supervisor.', 403);
        }

        if ($this->rbac->levelForActor((string) $row['ID_USER'], $menuId) !== 'W') {
            throw new \RuntimeException('Mohon maaf anda tidak berhak mengakses halaman ini.', 403);
        }

        $supervisor = $this->site->hydrateUser($row);
        $grant      = $this->jwt->encodeWithTtl([
            'sub'         => $supervisor->id,
            'typ'         => JwtService::TYP_SUPERVISOR,
            'officer'     => $officer->id,
            'menuId'      => $menuId,
            'action'      => $action,
            'resourceKey' => $resourceKey,
            'nomorInduk'  => $resourceKey,
            'name'        => $supervisor->name,
        ], 10 * 60);

        return [
            'grant'      => $grant,
            'supervisor' => $this->publicUser($supervisor, $row),
        ];
    }

    /**
     * @return array{supervisorId: string, supervisorName: string}
     */
    public function readHapusGrant(CurrentUser $officer, string $nomorInduk, string $grant): array
    {
        return $this->readSupervisorGrant(
            $officer,
            RbacService::MENU_IDENTITAS,
            $nomorInduk,
            $grant,
            'delete',
        );
    }

    /**
     * @return array{supervisorId: string, supervisorName: string}
     */
    public function readSupervisorGrant(
        CurrentUser $officer,
        string $menuId,
        string $resourceKey,
        string $grant,
        string $action = 'delete',
    ): array {
        $grant = trim($grant);
        if ($grant === '') {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403);
        }

        try {
            $claims = $this->jwt->decode($grant);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403, $e);
        }

        $typ = (string) ($claims['typ'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');

        if ($sub === '' || (string) ($claims['officer'] ?? '') !== $officer->id) {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403);
        }

        $matchesNew = $typ === JwtService::TYP_SUPERVISOR
            && (string) ($claims['menuId'] ?? '') === $menuId
            && (string) ($claims['action'] ?? '') === $action
            && (string) ($claims['resourceKey'] ?? '') === $resourceKey;

        $matchesLegacyHapus = $typ === JwtService::TYP_HAPUS_LEGACY
            && $menuId === RbacService::MENU_IDENTITAS
            && $action === 'delete'
            && (string) ($claims['nomorInduk'] ?? '') === $resourceKey;

        if (! $matchesNew && ! $matchesLegacyHapus) {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403);
        }

        return [
            'supervisorId'   => $sub,
            'supervisorName' => (string) ($claims['name'] ?? $sub),
        ];
    }

    /**
     * @return list<string>
     */
    public function supervisorLevels(CurrentUser $officer): array
    {
        return $officer->isHq() ? ['3', '80'] : ['2'];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(CurrentUser $user): array
    {
        $registry = PortedMenuRegistry::all();
        $access   = $this->rbac->accessMapFor($user, $registry);
        $menus    = [];

        foreach ($registry as $item) {
            $level = $access[$item['id']]['level'] ?? 'N';
            if ($level !== 'N') {
                $menus[] = [
                    'id'    => $item['id'],
                    'key'   => $item['key'],
                    'label' => $item['label'],
                    'path'  => $item['path'],
                    'icon'  => $item['icon'],
                ];
            }
        }

        return [
            'user'          => $this->publicUser($user),
            'hasLegacyHost' => PublicOrigins::hasLegacyBase(),
            'access'        => $access,
            'menus'         => $menus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimsFrom(CurrentUser $user, array $row): array
    {
        return [
            'sub'       => $user->id,
            'role'      => $user->role,
            'name'      => $user->name,
            'level'     => $user->level,
            'levelName' => $row['NAMA_LEVEL'] ?? null,
            'nip'       => $row['NIP'] ?? null,
            'isPusat'   => $user->isPusat,
            'isKanwil'  => $user->isKanwil,
            'uptId'     => $user->uptId,
            'uptName'   => $user->uptName,
            'sessionFilterUptId'    => null,
            'sessionFilterKanwilId' => null,
        ];
    }

    private function normalizeFilter(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'semua') === 0) {
            return null;
        }

        return $value;
    }

    private function recentFailedCount(string $username): int
    {
        $row = db_connect()->query(
            "SELECT COUNT(*) AS c
             FROM log_login
             WHERE USERID = ?
               AND JENIS IN ('Failed', 'Failed Block')
               AND TANGGAL >= (NOW() - INTERVAL 15 MINUTE)
               AND TANGGAL <= NOW()",
            [$username]
        )->getRowArray();

        return (int) ($row['c'] ?? 0);
    }

    private function logLogin(string $userId, string $jenis): void
    {
        db_connect()->table('log_login')->insert([
            'USERID'        => $userId,
            'IP_ADDRESS'    => service('request')->getIPAddress(),
            'USER_AGENT'    => substr((string) service('request')->getUserAgent(), 0, 120),
            'LAST_ACTIVITY' => date('Y-m-d H:i:s'),
            'EKSPIRASI'     => date('Y-m-d H:i:s'),
            'JENIS'         => $jenis,
            'TANGGAL'       => date('Y-m-d H:i:s'),
        ]);
    }
}
