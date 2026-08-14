<?php

namespace App\Modules\Identity\DTO;

final class IdentitySearchQuery
{
    public const FIELDS = [
        'nama',
        'no_induk',
        'tgl',
        'usia',
        'alamat',
        'jk',
        'wg',
        'agama',
        'pendidikan',
        'uu',
        'ver',
        'res',
        'ruh',
        'no_reg',
        'tgl_msk_lapas',
        'semua',
    ];

    public const SORTABLE = [
        'noin'          => 'identitas.NOMOR_INDUK',
        'NMR_REG_GOL'   => 'perkara.NMR_REG_GOL',
        'TGL_MSK_LAPAS' => 'perkara.TGL_MSK_LAPAS',
        'NAMA_LENGKAP'  => 'identitas.NAMA_LENGKAP',
        'TANGGAL_LAHIR' => 'identitas.TANGGAL_LAHIR',
        'ALAMAT'        => 'identitas.ALAMAT',
        'agama'         => 'dr1.DESKRIPSI',
        'konsol'        => 'identitas.KONSOLIDASI',
        'URAIAN'        => 'upt.URAIAN',
    ];

    public function __construct(
        public readonly string $field,
        public readonly string $q,
        public readonly string $photoStatus,
        public readonly string $fingerprintStatus,
        public readonly bool $activeOnly,
        public readonly bool $recidivistOnly,
        public readonly string $mode,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sort,
        public readonly string $order,
        public readonly bool $exportAll,
        public readonly bool $includeCase,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromRequest(array $input): self
    {
        $field = (string) ($input['field'] ?? 'semua');
        if (! in_array($field, self::FIELDS, true)) {
            $field = 'semua';
        }

        $q = trim((string) ($input['q'] ?? ''));
        if ($field === 'semua' && $q !== '') {
            $field = 'nama';
        }

        $photo = (string) ($input['photoStatus'] ?? 'semua');
        if (! in_array($photo, ['semua', 'sudah', 'belum'], true)) {
            $photo = 'semua';
        }

        $finger = (string) ($input['fingerprintStatus'] ?? 'semua');
        if (! in_array($finger, ['semua', 'sudah', 'belum'], true)) {
            $finger = 'semua';
        }

        $mode = (string) ($input['mode'] ?? 'grid');
        $mode = $mode === 'analisa' ? 'analisa' : 'grid';

        $sort = (string) ($input['sort'] ?? 'noin');
        if (! isset(self::SORTABLE[$sort])) {
            $sort = 'noin';
        }

        $order = strtolower((string) ($input['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($input['perPage'] ?? ($mode === 'analisa' ? 1 : 20));
        if (! in_array($perPage, [1, 10, 20, 50, 100], true)) {
            $perPage = $mode === 'analisa' ? 1 : 20;
        }

        $page = max(1, (int) ($input['page'] ?? 1));

        $rawActive = $input['activeOnly'] ?? true;
        if (is_array($rawActive)) {
            $rawActive = end($rawActive);
        }
        $rawRecid = $input['recidivistOnly'] ?? false;
        if (is_array($rawRecid)) {
            $rawRecid = end($rawRecid);
        }

        $active = self::boolish($rawActive, true);
        $recid  = self::boolish($rawRecid, false);
        $export = self::boolish($input['exportAll'] ?? false, false);

        return new self(
            field: $field,
            q: $q,
            photoStatus: $photo,
            fingerprintStatus: $finger,
            activeOnly: $active,
            recidivistOnly: $recid,
            mode: $mode,
            page: $page,
            perPage: $perPage,
            sort: $sort,
            order: $order,
            exportAll: $export,
            includeCase: $mode === 'analisa',
        );
    }

    private static function boolish(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
