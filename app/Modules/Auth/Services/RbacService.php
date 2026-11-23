<?php

namespace App\Modules\Auth\Services;

use App\Modules\Shared\Auth\CurrentUser;

final class RbacService
{
    public const MENU_IDENTITAS = '10';

    /**
     * Legacy hak_akses.Deskripsi: W write, R read, N none.
     *
     * @return array{level: string, canWrite: bool, canDelete: bool, canPrint: bool}
     */
    public function accessFor(CurrentUser $user, string $menuId, int $tipe = 1): array
    {
        $row = db_connect()->table('hak_akses')
            ->select('Deskripsi, HAK_DELETE')
            ->where('Id_Menu', $menuId)
            ->where('Id_Role', $user->role)
            ->get()
            ->getRowArray();

        $level = strtoupper((string) ($row['Deskripsi'] ?? 'N'));
        if ($level === '') {
            $level = 'N';
        }

        // Pusat/Kanwil cannot mutate master identity data (tipe=1).
        if ($user->isHq() && $tipe === 1 && $level === 'W') {
            $level = 'R';
        }

        $canDelete = false;
        if ($level === 'W') {
            $deleteRow = db_connect()->table('hak_akses')
                ->select('HAK_DELETE')
                ->where('ID_MENU', $menuId)
                ->where('ID_ROLE', $user->id)
                ->get()
                ->getRowArray();

            $flag = $deleteRow['HAK_DELETE'] ?? $row['HAK_DELETE'] ?? '0';
            $canDelete = (string) $flag === '1';
        }

        return [
            'level'     => $level,
            'canWrite'  => $level === 'W',
            'canDelete' => $canDelete,
            'canPrint'  => $this->canPrint($user),
        ];
    }

    /**
     * Batch-computes access permissions for a list of PortedMenuRegistry entries in at most 2 queries.
     *
     * @param list<array{id: string, tipe?: int}> $menus
     * @return array<string, array{level: string, canWrite: bool, canDelete: bool, canPrint: bool}>
     */
    public function accessMapFor(CurrentUser $user, array $menus): array
    {
        $menuIds = array_values(array_unique(array_map(
            static fn (array $menu): string => (string) $menu['id'],
            $menus,
        )));

        if ($menuIds === []) {
            return [];
        }

        $roleRows = db_connect()->table('hak_akses')
            ->select('Id_Menu, Deskripsi, HAK_DELETE')
            ->where('Id_Role', $user->role)
            ->whereIn('Id_Menu', $menuIds)
            ->get()
            ->getResultArray();

        $byMenu = [];
        foreach ($roleRows as $row) {
            $byMenu[(string) ($row['Id_Menu'] ?? '')] = $row;
        }

        $resolvedLevels = [];
        $hasWrite       = false;
        foreach ($menus as $menu) {
            $menuId = (string) $menu['id'];
            $tipe   = (int) ($menu['tipe'] ?? 1);
            $row    = $byMenu[$menuId] ?? null;

            $level = strtoupper((string) ($row['Deskripsi'] ?? 'N'));
            if ($level === '') {
                $level = 'N';
            }

            if ($user->isHq() && $tipe === 1 && $level === 'W') {
                $level = 'R';
            }

            if ($level === 'W') {
                $hasWrite = true;
            }

            $resolvedLevels[$menuId] = $level;
        }

        $actorDeleteFlags = [];
        if ($hasWrite) {
            $deleteRows = db_connect()->table('hak_akses')
                ->select('ID_MENU, HAK_DELETE')
                ->where('ID_ROLE', $user->id)
                ->whereIn('ID_MENU', $menuIds)
                ->get()
                ->getResultArray();

            foreach ($deleteRows as $dRow) {
                $actorDeleteFlags[(string) ($dRow['ID_MENU'] ?? '')] = $dRow['HAK_DELETE'] ?? null;
            }
        }

        $canPrint = $this->canPrint($user);
        $map      = [];
        foreach ($menus as $menu) {
            $menuId = (string) $menu['id'];
            $level  = $resolvedLevels[$menuId];
            $canDelete = false;
            if ($level === 'W') {
                $flag      = $actorDeleteFlags[$menuId] ?? $byMenu[$menuId]['HAK_DELETE'] ?? '0';
                $canDelete = (string) $flag === '1';
            }

            $map[$menuId] = [
                'level'     => $level,
                'canWrite'  => $level === 'W',
                'canDelete' => $canDelete,
                'canPrint'  => $canPrint,
            ];
        }

        return $map;
    }

    public function canPrint(CurrentUser $user): bool
    {
        if (! $user->isHq()) {
            return true;
        }

        return in_array($user->level, ['1', '2', '3', '80'], true);
    }

    /**
     * PHP getHakAkses looks up hak_akses.Id_Role with the value it is given.
     * Dual-password passes the supervisor's ID_USER, not ID_ROLE.
     */
    public function levelForActor(string $actorId, string $menuId): string
    {
        $row = db_connect()->table('hak_akses')
            ->select('Deskripsi')
            ->where('Id_Menu', $menuId)
            ->where('Id_Role', $actorId)
            ->get()
            ->getRowArray();

        $level = strtoupper((string) ($row['Deskripsi'] ?? 'N'));

        return $level === '' ? 'N' : $level;
    }
}
