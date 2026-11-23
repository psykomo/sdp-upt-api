<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Identitas\DTO\IdentitasSearchQuery;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Repositories\IdentitasRowScopeRepository;
use App\Modules\Shared\Access\AccessDeniedException;
use App\Modules\Shared\Access\PerkaraScopeRepository;
use App\Modules\Shared\Access\RowScope;
use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Database\BaseBuilder;

final class IdentitasAccessService
{
    private const ACCESS_DENIED = 'Mohon maaf anda tidak berhak mengakses data identitas ini.';

    public function __construct(
        private readonly IdentitasRowScopeRepository $identitas = new IdentitasRepository(),
        private readonly PerkaraScopeRepository $scopeRepo = new PerkaraScopeRepository(),
    ) {
    }

    public function assertNomorIndukAccessible(string $nomorInduk, CurrentUser $user): void
    {
        $scope = RowScope::fromUser($user);
        if ($scope->isUnrestricted()) {
            return;
        }

        $context = $this->identitas->activeStaParentPerkaraContext($nomorInduk);
        if ($context === null) {
            return;
        }

        $sessionFilterUptKanwil = $scope->needsSessionFilterUptKanwilCheck() && $scope->sessionFilterUptId !== null
            ? $this->scopeRepo->uptKanwil($scope->sessionFilterUptId)
            : null;

        try {
            $scope->assertContextMatches($context, $sessionFilterUptKanwil, self::ACCESS_DENIED);
        } catch (AccessDeniedException $e) {
            throw new IdentitasAccessException($e->getMessage());
        }
    }

    public function parentPerkaraUptId(string $nomorInduk): ?string
    {
        $context = $this->identitas->activeStaParentPerkaraContext($nomorInduk);
        if ($context === null) {
            return null;
        }

        $idUpt = trim((string) ($context['idUpt'] ?? ''));

        return $idUpt !== '' ? $idUpt : null;
    }

    public function applySearchScope(BaseBuilder $builder, CurrentUser $user, IdentitasSearchQuery $query): void
    {
        $this->scopeRepo->applyToIdentitasBuilder($builder, RowScope::fromUser($user), $query->activeOnly);
    }
}
