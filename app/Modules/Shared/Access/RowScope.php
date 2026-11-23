<?php

namespace App\Modules\Shared\Access;

use App\Modules\Shared\Auth\CurrentUser;

/**
 * Profile-derived row scope (UPT / Kanwil / Pusat + HQ working-scope filters).
 */
final class RowScope
{
    public function __construct(
        public readonly ?string $baseType,
        public readonly ?string $baseValue,
        public readonly ?string $sessionFilterUptId,
        public readonly ?string $sessionFilterKanwilId,
        public readonly bool $isKanwil,
    ) {
    }

    public static function fromUser(CurrentUser $user): self
    {
        $baseType  = null;
        $baseValue = null;
        $uptId     = trim((string) ($user->uptId ?? ''));

        if ($uptId !== '') {
            if ($user->isKanwil) {
                $baseType  = 'kanwil';
                $baseValue = $uptId;
            } elseif (! $user->isPusat) {
                $baseType  = 'upt';
                $baseValue = $uptId;
            }
        }

        $filterUpt    = trim((string) ($user->claims['sessionFilterUptId'] ?? ''));
        $filterKanwil = trim((string) ($user->claims['sessionFilterKanwilId'] ?? ''));

        return new self(
            baseType: $baseType,
            baseValue: $baseValue,
            sessionFilterUptId: $filterUpt !== '' ? $filterUpt : null,
            sessionFilterKanwilId: $filterKanwil !== '' ? $filterKanwil : null,
            isKanwil: $user->isKanwil,
        );
    }

    public function isUnrestricted(): bool
    {
        return $this->baseType === null
            && $this->sessionFilterUptId === null
            && $this->sessionFilterKanwilId === null;
    }

    public function needsSessionFilterUptKanwilCheck(): bool
    {
        return $this->isKanwil && $this->sessionFilterUptId !== null;
    }

    /**
     * @param array{idUpt: string, kanwil: string}|null $context
     */
    public function assertContextMatches(
        ?array $context,
        ?string $sessionFilterUptKanwil = null,
        string $message = AccessDeniedException::DEFAULT_MESSAGE,
    ): void {
        if ($context === null) {
            return;
        }

        if ($this->baseType === 'upt' && ($context['idUpt'] ?? '') !== $this->baseValue) {
            throw new AccessDeniedException($message);
        }

        if ($this->baseType === 'kanwil' && ($context['kanwil'] ?? '') !== $this->baseValue) {
            throw new AccessDeniedException($message);
        }

        if ($this->sessionFilterUptId !== null && ($context['idUpt'] ?? '') !== $this->sessionFilterUptId) {
            throw new AccessDeniedException($message);
        }

        if ($this->sessionFilterKanwilId !== null && ($context['kanwil'] ?? '') !== $this->sessionFilterKanwilId) {
            throw new AccessDeniedException($message);
        }

        if ($this->needsSessionFilterUptKanwilCheck() && ($sessionFilterUptKanwil ?? '') !== ($this->baseValue ?? '')) {
            throw new AccessDeniedException($message);
        }
    }
}
