<?php

namespace App\Modules\Identitas\Repositories;

/**
 * Table access for the Identitas aggregate. Services map rows to camelCase JSON.
 * Search stays in IdentitasSearchRepository — that query is a different shape.
 */
final class IdentitasRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(string $nomorInduk): ?array
    {
        return db_connect()->table('identitas')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->get()
            ->getRowArray() ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithKtp(string $nomorInduk): ?array
    {
        return db_connect()->table('identitas')
            ->select('identitas.*, identitas_ktp.NAMA_LENGKAP AS NAMA_LENGKAP_KTP, identitas_ktp.TEMPAT_LAHIR AS TEMPAT_LAHIR_KTP')
            ->join('identitas_ktp', 'identitas_ktp.NIK = identitas.NIK', 'left')
            ->where('identitas.NOMOR_INDUK', $nomorInduk)
            ->get()
            ->getRowArray() ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(string $nomorInduk, array $row): void
    {
        db_connect()->table('identitas')->where('NOMOR_INDUK', $nomorInduk)->update($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSidikJari(string $nomorInduk, bool $requireTanggal = false): ?array
    {
        $builder = db_connect()->table('sidik_jari')->where('NOMOR_INDUK', $nomorInduk);
        if ($requireTanggal) {
            $builder->where('TANGGAL_SJ IS NOT NULL', null, false);
        }

        return $builder->get()->getRowArray() ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function upsertSidikJari(string $nomorInduk, array $row): void
    {
        $exists = $this->findSidikJari($nomorInduk);
        if ($exists) {
            db_connect()->table('sidik_jari')->where('NOMOR_INDUK', $nomorInduk)->update($row);

            return;
        }

        $row['NOMOR_INDUK'] = $nomorInduk;
        if (empty($row['ID_SIDIK_JARI'])) {
            unset($row['ID_SIDIK_JARI']);
        }
        db_connect()->table('sidik_jari')->insert($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function similarRows(string $nomorInduk): array
    {
        return db_connect()->table('identitas_mirip im')
            ->select('im.NOMOR_INDUK_2 AS nomorInduk, identitas.NAMA_LENGKAP AS namaLengkap')
            ->join('identitas', 'identitas.NOMOR_INDUK = im.NOMOR_INDUK_2', 'left')
            ->where('im.NOMOR_INDUK_1', $nomorInduk)
            ->where('im.IS_DELETE', '0')
            ->get()
            ->getResultArray();
    }

    public function similarIdsCsv(string $nomorInduk): string
    {
        $row = db_connect()->table('identitas_mirip')
            ->select('GROUP_CONCAT(NOMOR_INDUK_2) AS ids')
            ->where('NOMOR_INDUK_1', $nomorInduk)
            ->where('IS_DELETE', '0')
            ->get()
            ->getRowArray();

        return rtrim((string) ($row['ids'] ?? ''), ',');
    }

    /**
     * @param list<string> $nomorIndukLain
     */
    public function replaceKemiripan(string $nomorInduk, array $nomorIndukLain): void
    {
        db_connect()->table('identitas_mirip')
            ->where('NOMOR_INDUK_1', $nomorInduk)
            ->update(['IS_DELETE' => 1]);

        if ($nomorIndukLain === []) {
            return;
        }

        db_connect()->table('identitas')->where('NOMOR_INDUK', $nomorInduk)->update(['RESIDIVIS' => 'RDV1']);

        foreach ($nomorIndukLain as $other) {
            $row = db_connect()->table('identitas_mirip')
                ->where('NOMOR_INDUK_1', $nomorInduk)
                ->where('NOMOR_INDUK_2', $other)
                ->get()
                ->getRowArray();

            if ($row) {
                db_connect()->table('identitas_mirip')
                    ->where('NOMOR_INDUK_1', $nomorInduk)
                    ->where('NOMOR_INDUK_2', $other)
                    ->update(['IS_DELETE' => 0]);
                continue;
            }

            db_connect()->table('identitas_mirip')->insert([
                'NOMOR_INDUK_1' => $nomorInduk,
                'NOMOR_INDUK_2' => $other,
                'IS_DELETE'     => 0,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attributeHistory(string $nomorInduk): array
    {
        return db_connect()->table('identitas_attribute_histori ias')
            ->select('ias.NAMA_FIELD, ias.ISI_FIELD, ias.ID_PERKARA, ias.ID_REG, ias.CREATED, ias.CREATED_BY, jr.DESKRIPSI AS JENIS_REGISTRASI')
            ->join('jenis_registrasi jr', 'ias.ID_REG = jr.ID_REG', 'left')
            ->where('ias.NOMOR_INDUK', $nomorInduk)
            ->orderBy('ias.UPDATED', 'DESC')
            ->orderBy('ias.CREATED', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertChangeLog(array $row): void
    {
        db_connect()->table('log_file')->insert($row);
    }

    public function isTahanan(string $nomorInduk): bool
    {
        $row = db_connect()->table('perkara')
            ->select('jr.IS_TAHANAN')
            ->join('jenis_registrasi jr', 'jr.ID_REG = perkara.ID_REG', 'left')
            ->where('perkara.NOMOR_INDUK', $nomorInduk)
            ->where('perkara.IS_DELETE', 0)
            ->get()
            ->getRowArray();

        return (int) ($row['IS_TAHANAN'] ?? 0) === 1;
    }

    public function hasOpenPerkara(string $nomorInduk): bool
    {
        $row = db_connect()->table('perkara')
            ->select('ID_PERKARA')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->where('IS_DELETE', 0)
            ->get()
            ->getRowArray();

        return $row !== null;
    }

    public function softDelete(string $nomorInduk): void
    {
        db_connect()->table('identitas')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->update(['IS_DELETED' => 1]);

        db_connect()->table('sidik_jari')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->update(['IS_DELETED' => 1]);

        db_connect()->table('usertbl')
            ->where('dbid', $nomorInduk)
            ->update(['IS_DELETED' => 1]);
    }
}
