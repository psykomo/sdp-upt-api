<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Identitas\DTO\IdentitasSearchQuery;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Repositories\IdentitasRowScopeRepository;
use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Database\BaseBuilder;

final class IdentitasAccessService
{
    private const ACCESS_DENIED = 'Mohon maaf anda tidak berhak mengakses data identitas ini.';

    public function __construct(
        private readonly IdentitasRowScopeRepository $identitas = new IdentitasRepository(),
    ) {
    }

    public function assertNomorIndukAccessible(string $nomorInduk, CurrentUser $user): void
    {
        $scope = $this->resolveScope($user);
        if ($scope !== null) {
            $context = $this->identitas->activeStaParentPerkaraContext($nomorInduk);
            if ($context !== null) {
                if ($scope['type'] === 'upt' && $context['idUpt'] !== $scope['value']) {
                    throw new IdentitasAccessException(self::ACCESS_DENIED);
                }

                if ($scope['type'] === 'kanwil' && $context['kanwil'] !== $scope['value']) {
                    throw new IdentitasAccessException(self::ACCESS_DENIED);
                }
            }
        }

        $this->assertSessionFilters($nomorInduk, $user);
    }

    public function applySearchScope(BaseBuilder $builder, CurrentUser $user, IdentitasSearchQuery $query): void
    {
        $scope = $this->resolveScope($user);

        if ($scope !== null) {
            if ($query->activeOnly) {
                if ($scope['type'] === 'upt') {
                    $builder->where('perkara.ID_UPT', $scope['value']);
                } else {
                    $escaped = db_connect()->escape($scope['value']);
                    $builder->where(
                        'EXISTS (SELECT 1 FROM upt u_scope'
                        . ' WHERE u_scope.ID_UPT = perkara.ID_UPT'
                        . ' AND u_scope.KANWIL = ' . $escaped . ')',
                        null,
                        false,
                    );
                }
            } else {
                $db = db_connect();
                $escaped = $db->escape($scope['value']);

                if ($scope['type'] === 'upt') {
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

        $this->applySessionFilters($builder, $user);
    }

    private function assertSessionFilters(string $nomorInduk, CurrentUser $user): void
    {
        $filterUpt    = $this->sessionFilterUptId($user);
        $filterKanwil = $this->sessionFilterKanwilId($user);

        if ($filterUpt === null && $filterKanwil === null) {
            return;
        }

        $context = $this->identitas->activeStaParentPerkaraContext($nomorInduk);
        if ($context === null) {
            return;
        }

        if ($filterUpt !== null && $context['idUpt'] !== $filterUpt) {
            throw new IdentitasAccessException(self::ACCESS_DENIED);
        }

        if ($filterKanwil !== null && $context['kanwil'] !== $filterKanwil) {
            throw new IdentitasAccessException(self::ACCESS_DENIED);
        }

        if ($filterUpt !== null && $user->isKanwil) {
            $row = db_connect()->table('upt')
                ->select('KANWIL')
                ->where('ID_UPT', $filterUpt)
                ->get()
                ->getRowArray();
            if (($row['KANWIL'] ?? '') !== $user->uptId) {
                throw new IdentitasAccessException(self::ACCESS_DENIED);
            }
        }
    }

    private function applySessionFilters(BaseBuilder $builder, CurrentUser $user): void
    {
        $filterUpt    = $this->sessionFilterUptId($user);
        $filterKanwil = $this->sessionFilterKanwilId($user);

        if ($filterUpt !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM perkara p_filt'
                . ' WHERE p_filt.NOMOR_INDUK = identitas.NOMOR_INDUK'
                . ' AND p_filt.IS_DELETE = 0'
                . ' AND p_filt.ID_UPT = ' . db_connect()->escape($filterUpt) . ')',
                null,
                false,
            );
        }

        if ($filterKanwil !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM perkara p_kw'
                . ' INNER JOIN upt u_kw ON p_kw.ID_UPT = u_kw.ID_UPT'
                . ' WHERE p_kw.NOMOR_INDUK = identitas.NOMOR_INDUK'
                . ' AND p_kw.IS_DELETE = 0'
                . ' AND u_kw.KANWIL = ' . db_connect()->escape($filterKanwil) . ')',
                null,
                false,
            );
        }
    }

    /**
     * @return array{type: 'upt'|'kanwil', value: string}|null
     */
    private function resolveScope(CurrentUser $user): ?array
    {
        $uptId = trim((string) ($user->uptId ?? ''));
        if ($uptId === '') {
            return null;
        }

        if ($user->isKanwil) {
            return ['type' => 'kanwil', 'value' => $uptId];
        }

        if (! $user->isPusat && ! $user->isKanwil) {
            return ['type' => 'upt', 'value' => $uptId];
        }

        return null;
    }

    private function sessionFilterUptId(CurrentUser $user): ?string
    {
        $value = trim((string) ($user->claims['sessionFilterUptId'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function sessionFilterKanwilId(CurrentUser $user): ?string
    {
        $value = trim((string) ($user->claims['sessionFilterKanwilId'] ?? ''));

        return $value !== '' ? $value : null;
    }
}
