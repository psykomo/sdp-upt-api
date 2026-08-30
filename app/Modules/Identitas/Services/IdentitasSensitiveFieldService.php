<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Repositories\IdentitasRepositoryInterface;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\DateFormat;

final class IdentitasSensitiveFieldService
{
    /** @var list<string> */
    public const LOCKED_FIELDS = [
        'tanggalLahir',
        'kewarganegaraan',
        'negaraAsing',
        'agama',
        'agamaLain',
        'tempatLahir',
        'tempatLahirLain',
    ];

    public function __construct(
        private readonly IdentitasRepositoryInterface $identitas = new IdentitasRepository(),
    ) {
    }

    public function canEditSensitiveFields(string $nomorInduk, CurrentUser $user): bool
    {
        $idPerkara = $this->identitas->activeStaParentPerkaraId($nomorInduk);
        if ($idPerkara === null) {
            return true;
        }

        if (! $this->identitas->hasProcessedRemisi($idPerkara)) {
            return true;
        }

        $idUpt = trim((string) ($user->uptId ?? ''));
        if ($idUpt === '') {
            $idUpt = trim((string) ($this->identitas->activeStaParentUptId($nomorInduk) ?? ''));
        }

        if ($idUpt === '') {
            return false;
        }

        return $this->identitas->hasSidangTppAkses002($idPerkara, $idUpt);
    }

    /**
     * @return list<string>
     */
    public function lockedFields(string $nomorInduk, CurrentUser $user): array
    {
        return $this->canEditSensitiveFields($nomorInduk, $user) ? [] : self::LOCKED_FIELDS;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, string>
     */
    public function validateChanges(array $input, array $existing, string $nomorInduk, CurrentUser $user): array
    {
        if ($this->canEditSensitiveFields($nomorInduk, $user)) {
            return [];
        }

        $errors = [];
        $map    = [
            'tanggalLahir'    => 'TANGGAL_LAHIR',
            'kewarganegaraan' => 'ID_JENIS_WARGANEGARA',
            'negaraAsing'     => 'ID_NEGARA_ASING',
            'agama'           => 'ID_JENIS_AGAMA',
            'agamaLain'       => 'ID_JENIS_AGAMA_LAIN',
            'tempatLahir'     => 'ID_TEMPAT_LAHIR',
            'tempatLahirLain' => 'ID_TEMPAT_LAHIR_LAIN',
        ];

        foreach ($map as $apiField => $dbField) {
            if (! array_key_exists($apiField, $input)) {
                continue;
            }

            $incoming = trim((string) $input[$apiField]);
            $current  = trim((string) ($existing[$dbField] ?? ''));

            if ($apiField === 'tanggalLahir') {
                $incomingYmd = DateFormat::fromDisplay($incoming) ?? $incoming;
                $currentYmd  = trim((string) ($existing[$dbField] ?? ''));
                if ($incomingYmd !== $currentYmd && $currentYmd !== '' && $currentYmd !== '0000-00-00') {
                    $errors[$apiField] = 'Field ini terkunci sampai jendela akses Sidang TPP dibuka.';
                }
                continue;
            }

            if ($incoming !== $current) {
                $errors[$apiField] = 'Field ini terkunci sampai jendela akses Sidang TPP dibuka.';
            }
        }

        return $errors;
    }
}
