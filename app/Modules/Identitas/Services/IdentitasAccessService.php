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
        if ($scope === null) {
            return;
        }

        $context = $this->identitas->activeStaParentPerkaraContext($nomorInduk);
        if ($context === null) {
            return;
        }

        if ($scope['type'] === 'upt' && $context['idUpt'] !== $scope['value']) {
            throw new IdentitasAccessException(self::ACCESS_DENIED);
        }

        if ($scope['type'] === 'kanwil' && $context['kanwil'] !== $scope['value']) {
            throw new IdentitasAccessException(self::ACCESS_DENIED);
        }
    }

    public function applySearchScope(BaseBuilder $builder, CurrentUser $user, IdentitasSearchQuery $query): void
    {
        $scope = $this->resolveScope($user);
        if ($scope === null) {
            return;
        }

        if ($query->activeOnly) {
            if ($scope['type'] === 'upt') {
                $builder->where('perkara.ID_UPT', $scope['value']);
            } else {
                $this->ensureUptJoin($builder, $user);
                $builder->where('upt.KANWIL', $scope['value']);
            }

            return;
        }

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

    private function ensureUptJoin(BaseBuilder $builder, CurrentUser $user): void
    {
        if ($user->isHq()) {
            return;
        }

        $builder->join('upt', 'perkara.ID_UPT = upt.ID_UPT', 'left');
    }
}
