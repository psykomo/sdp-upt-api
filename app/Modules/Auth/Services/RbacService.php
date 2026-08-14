<?php

namespace App\Modules\Auth\Services;

use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Database\BaseConnection;

final class RbacService
{
    public const MENU_IDENTITAS = '10';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Legacy hak_akses.Deskripsi: W write, R read, N none.
     *
     * @return array{level: string, canWrite: bool, canDelete: bool, canPrint: bool}
     */
    public function accessFor(CurrentUser $user, string $menuId, int $tipe = 1): array
    {
        $row = $this->db->table('hak_akses')
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
            $deleteRow = $this->db->table('hak_akses')
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

    public function canPrint(CurrentUser $user): bool
    {
        if (! $user->isHq()) {
            return true;
        }

        return in_array($user->level, ['1', '2', '3', '80'], true);
    }
}
