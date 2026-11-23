<?php

namespace App\Modules\Shared\Access;

use CodeIgniter\Database\BaseBuilder;

/**
 * Shared MariaDB row-scope queries for perkara- and identitas-bound modules.
 */
final class PerkaraScopeRepository
{
    /**
     * Active STA parent perkara UPT context for row scoping (legacy ManajemenIdentitas::viewEdit).
     *
     * @return array{idUpt: string, kanwil: string}|null
     */
    public function activeStaParentPerkaraContext(string $nomorInduk): ?array
    {
        $row = db_connect()->table('identitas')
            ->select('perkara.ID_UPT, upt.KANWIL')
            ->join('perkara', 'perkara.NOMOR_INDUK = identitas.NOMOR_INDUK', 'left')
            ->join('upt', 'perkara.ID_UPT = upt.ID_UPT', 'left')
            ->where('identitas.NOMOR_INDUK', $nomorInduk)
            ->where('identitas.IS_DELETED', 0)
            ->where('perkara.IS_DELETE', 0)
            ->where('perkara.ID_STATUS', 'STA')
            ->where('perkara.ID_PERKARA_PARENT IS NULL', null, false)
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($row === null || trim((string) ($row['ID_UPT'] ?? '')) === '') {
            return null;
        }

        return [
            'idUpt'  => (string) $row['ID_UPT'],
            'kanwil' => (string) ($row['KANWIL'] ?? ''),
        ];
    }

    /**
     * Direct perkara UPT context by ID_PERKARA for perkara-bound modules (Perkara, Remisi, Integrasi, etc.).
     *
     * @return array{idUpt: string, kanwil: string}|null
     */
    public function perkaraContext(string $idPerkara): ?array
    {
        $row = db_connect()->table('perkara')
            ->select('perkara.ID_UPT, upt.KANWIL')
            ->join('upt', 'perkara.ID_UPT = upt.ID_UPT', 'left')
            ->where('perkara.ID_PERKARA', $idPerkara)
            ->where('perkara.IS_DELETE', 0)
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($row === null || trim((string) ($row['ID_UPT'] ?? '')) === '') {
            return null;
        }

        return [
            'idUpt'  => (string) $row['ID_UPT'],
            'kanwil' => (string) ($row['KANWIL'] ?? ''),
        ];
    }

    public function uptKanwil(string $idUpt): ?string
    {
        $row = db_connect()->table('upt')
            ->select('KANWIL')
            ->where('ID_UPT', $idUpt)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        return (string) ($row['KANWIL'] ?? '');
    }

    /**
     * Applies base + HQ working-scope filters onto a query that already selects/joins `perkara`.
     */
    public function applyToPerkaraAlias(BaseBuilder $builder, RowScope $scope, string $perkaraAlias = 'perkara'): void
    {
        if ($scope->isUnrestricted()) {
            return;
        }

        $db = db_connect();

        if ($scope->baseType === 'upt' && $scope->baseValue !== null) {
            $builder->where($perkaraAlias . '.ID_UPT', $scope->baseValue);
        } elseif ($scope->baseType === 'kanwil' && $scope->baseValue !== null) {
            $escaped = $db->escape($scope->baseValue);
            $builder->where(
                'EXISTS (SELECT 1 FROM upt u_scope'
                . ' WHERE u_scope.ID_UPT = ' . $perkaraAlias . '.ID_UPT'
                . ' AND u_scope.KANWIL = ' . $escaped . ')',
                null,
                false,
            );
        }

        if ($scope->sessionFilterUptId !== null) {
            $builder->where($perkaraAlias . '.ID_UPT', $scope->sessionFilterUptId);
        }

        if ($scope->sessionFilterKanwilId !== null) {
            $escaped = $db->escape($scope->sessionFilterKanwilId);
            $builder->where(
                'EXISTS (SELECT 1 FROM upt u_kw'
                . ' WHERE u_kw.ID_UPT = ' . $perkaraAlias . '.ID_UPT'
                . ' AND u_kw.KANWIL = ' . $escaped . ')',
                null,
                false,
            );
        }
    }

    /**
     * Applies Identitas search scoping (handles activeOnly=false rows with no active STA parent perkara).
     */
    public function applyToIdentitasBuilder(BaseBuilder $builder, RowScope $scope, bool $activeOnly): void
    {
        if ($scope->isUnrestricted()) {
            return;
        }

        $db = db_connect();

        if ($scope->baseType !== null && $scope->baseValue !== null) {
            $escaped = $db->escape($scope->baseValue);

            if ($activeOnly) {
                if ($scope->baseType === 'upt') {
                    $builder->where('perkara.ID_UPT', $scope->baseValue);
                } else {
                    $builder->where(
                        'EXISTS (SELECT 1 FROM upt u_scope'
                        . ' WHERE u_scope.ID_UPT = perkara.ID_UPT'
                        . ' AND u_scope.KANWIL = ' . $escaped . ')',
                        null,
                        false,
                    );
                }
            } else {
                if ($scope->baseType === 'upt') {
                    $scopedExists = 'EXISTS (SELECT 1 FROM perkara p_scope'
                        . ' WHERE p_scope.NOMOR_INDUK = identitas.NOMOR_INDUK'
                        . ' AND p_scope.IS_DELETE = 0'
                        . ' AND p_scope.ID_UPT = ' . $escaped . ')';
                } else {
                    $scopedExists = 'EXISTS (SELECT 1 FROM perkara p_scope'
                        . ' INNER JOIN upt u_scope ON p_scope.ID_UPT = u_scope.ID_UPT'
                        . ' WHERE p_scope.NOMOR_INDUK = identitas.NOMOR_INDUK'
                        . ' AND p_scope.IS_DELETE = 0'
                        . ' AND u_scope.KANWIL = ' . $escaped . ')';
                }

                $noStaParent = 'NOT EXISTS (SELECT 1 FROM perkara p_sta'
                    . ' WHERE p_sta.NOMOR_INDUK = identitas.NOMOR_INDUK'
                    . ' AND p_sta.IS_DELETE = 0'
                    . ' AND p_sta.ID_STATUS = \'STA\''
                    . ' AND p_sta.ID_PERKARA_PARENT IS NULL)';

                $builder->groupStart()
                    ->where($scopedExists, null, false)
                    ->orWhere($noStaParent, null, false)
                    ->groupEnd();
            }
        }

        if ($scope->sessionFilterUptId !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM perkara p_filt'
                . ' WHERE p_filt.NOMOR_INDUK = identitas.NOMOR_INDUK'
                . ' AND p_filt.IS_DELETE = 0'
                . ' AND p_filt.ID_UPT = ' . $db->escape($scope->sessionFilterUptId) . ')',
                null,
                false,
            );
        }

        if ($scope->sessionFilterKanwilId !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM perkara p_kw'
                . ' INNER JOIN upt u_kw ON p_kw.ID_UPT = u_kw.ID_UPT'
                . ' WHERE p_kw.NOMOR_INDUK = identitas.NOMOR_INDUK'
                . ' AND p_kw.IS_DELETE = 0'
                . ' AND u_kw.KANWIL = ' . $db->escape($scope->sessionFilterKanwilId) . ')',
                null,
                false,
            );
        }
    }
}
