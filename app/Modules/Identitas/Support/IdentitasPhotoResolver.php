<?php

namespace App\Modules\Identitas\Support;

use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Repositories\IdentitasRepositoryInterface;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\StoredUploadPath;

final class IdentitasPhotoResolver
{
    private const PHOTO_COLUMNS = [
        'FOTO_KIRI',
        'FOTO_KANAN',
        'FOTO_DEPAN',
        'FOTO_CLOSEUP',
        'FOTO_CIRI_1',
        'FOTO_CIRI_2',
        'FOTO_CIRI_3',
    ];

    public function __construct(
        private readonly IdentitasRepositoryInterface $identitas = new IdentitasRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $row identitas row (uppercase foto columns)
     * @return array<string, string|null> resolved relative paths keyed by column name
     */
    public function resolveRow(array $row, string $nomorInduk, CurrentUser $user): array
    {
        if (! $user->isHq()) {
            return $this->rawPhotoSlice($row);
        }

        $mutasiRows = $this->identitas->mutasiPhotoRows($nomorInduk);
        $root       = StoredUploadPath::legacyRoot();
        $resolved   = [];

        foreach (self::PHOTO_COLUMNS as $column) {
            $dbValue = trim((string) ($row[$column] ?? ''));
            if ($dbValue !== '') {
                $found = $this->existingRelative($root, $dbValue);
                if ($found !== null) {
                    $resolved[$column] = $found;
                    continue;
                }

                foreach ($mutasiRows as $mutasi) {
                    $rewritten = $this->rewriteForMutasi($dbValue, $mutasi);
                    if ($rewritten === null) {
                        continue;
                    }
                    $found = $this->existingRelative($root, $rewritten);
                    if ($found !== null) {
                        $resolved[$column] = $found;
                        break;
                    }
                }
            }
        }

        foreach ($mutasiRows as $mutasi) {
            foreach (self::PHOTO_COLUMNS as $column) {
                if (isset($resolved[$column])) {
                    continue;
                }

                $value = trim((string) ($mutasi[$column] ?? ''));
                if ($value === '') {
                    continue;
                }

                $found = $this->existingRelative($root, $value);
                if ($found === null) {
                    $rewritten = $this->rewriteForMutasi($value, $mutasi);
                    if ($rewritten !== null) {
                        $found = $this->existingRelative($root, $rewritten);
                    }
                }

                if ($found !== null) {
                    $resolved[$column] = $found;
                }
            }
        }

        foreach (self::PHOTO_COLUMNS as $column) {
            if (! isset($resolved[$column])) {
                $dbValue = trim((string) ($row[$column] ?? ''));
                $resolved[$column] = $dbValue !== '' ? $dbValue : null;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolvePath(array $row, string $column, string $nomorInduk, CurrentUser $user): ?string
    {
        $slice = $this->resolveRow($row, $nomorInduk, $user);

        return $slice[$column] ?? null;
    }

    /**
     * @param array<string, mixed> $mutasi
     */
    private function rewriteForMutasi(string $path, array $mutasi): ?string
    {
        $idUpt     = trim((string) ($mutasi['ID_UPT'] ?? ''));
        $idUptAsal = trim((string) ($mutasi['ID_UPT_ASAL'] ?? ''));
        if ($idUpt === '' || $idUptAsal === '') {
            return null;
        }

        return str_replace($idUpt . '/', $idUptAsal . '/', $path);
    }

    private function existingRelative(string $root, string $path): ?string
    {
        $absolute = StoredUploadPath::absolute($root, $path);
        if ($absolute === null || ! is_file($absolute)) {
            return null;
        }

        return StoredUploadPath::relative($path) ?? ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    private function rawPhotoSlice(array $row): array
    {
        $out = [];
        foreach (self::PHOTO_COLUMNS as $column) {
            $value = trim((string) ($row[$column] ?? ''));

            $out[$column] = $value !== '' ? $value : null;
        }

        return $out;
    }
}
