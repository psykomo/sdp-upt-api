<?php

namespace App\Modules\Auth\Services;

use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Tenancy\TenantContext;

final class AuthService
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly SiteContextService $site,
        private readonly RbacService $rbac,
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

        $user  = $this->site->hydrateUser($row);
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
        ];
    }

    /**
     * Port of Login::logindp for Hapus. Officer is already on a JWT.
     *
     * @return array{grant: string, supervisor: array<string, mixed>}
     */
    public function issueHapusGrant(CurrentUser $officer, string $username, string $password, string $nomorInduk): array
    {
        $username   = trim($username);
        $nomorInduk = trim($nomorInduk);
        if ($username === '' || $password === '' || $nomorInduk === '') {
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

        if ($this->rbac->levelForActor((string) $row['ID_USER'], RbacService::MENU_IDENTITAS) !== 'W') {
            throw new \RuntimeException('Mohon maaf anda tidak berhak mengakses halaman ini.', 403);
        }

        $supervisor = $this->site->hydrateUser($row);
        $grant      = $this->jwt->encodeWithTtl([
            'sub'        => $supervisor->id,
            'typ'        => 'hapus-supervisor',
            'officer'    => $officer->id,
            'nomorInduk' => $nomorInduk,
            'name'       => $supervisor->name,
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
        $grant = trim($grant);
        if ($grant === '') {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403);
        }

        try {
            $claims = $this->jwt->decode($grant);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403, $e);
        }

        if (($claims['typ'] ?? '') !== 'hapus-supervisor'
            || (string) ($claims['officer'] ?? '') !== $officer->id
            || (string) ($claims['nomorInduk'] ?? '') !== $nomorInduk
            || (string) ($claims['sub'] ?? '') === ''
        ) {
            throw new \RuntimeException('Halaman ini memerlukan autentikasi dari supervisor.', 403);
        }

        return [
            'supervisorId'   => (string) $claims['sub'],
            'supervisorName' => (string) ($claims['name'] ?? $claims['sub']),
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
        return [
            'user'   => $this->publicUser($user),
            'access' => [
                RbacService::MENU_IDENTITAS => $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS),
            ],
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
        ];
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
