<?php

namespace App\Modules\Dokumen\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Dokumen\Repositories\DokumenRepository;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Support\StoredUploadPath;
use App\Modules\Shared\Auth\CurrentUser;

final class DokumenException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}

final class DokumenService
{
    private const JENIS_IDENTITAS = 'identitas';
    private const MAX_BYTES       = 2097152;

    public function __construct(
        private readonly DokumenRepository $dokumen = new DokumenRepository(),
        private readonly DokumenAccessService $access = new DokumenAccessService(),
        private readonly IdentitasRepository $identitas = new IdentitasRepository(),
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIdentitasDocuments(string $nomorInduk, CurrentUser $user): array
    {
        $this->access->assertIdentitasNomorInduk($nomorInduk, $user);

        $items = [];
        foreach ($this->dokumen->listByReference(self::JENIS_IDENTITAS, $nomorInduk) as $row) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    /**
     * @return array{path: string, mimeType: string, fileName: string}
     */
    public function streamFile(string $idDokumen, CurrentUser $user): array
    {
        $row = $this->dokumen->find($idDokumen);
        if ($row === null) {
            throw new DokumenException('Dokumen tidak ditemukan.', [], 404);
        }

        $this->access->assertRowAccessible($row, $user);

        $absolute = $this->absolutePath((string) ($row['URL'] ?? ''));
        if ($absolute === null || ! is_file($absolute)) {
            throw new DokumenException('Berkas dokumen tidak ditemukan di server.', [], 404);
        }

        return [
            'path'     => $absolute,
            'mimeType' => (string) ($row['MIME_TYPE'] ?? 'application/pdf'),
            'fileName' => (string) ($row['NAMA_FILE'] ?? 'dokumen.pdf'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createIdentitasDocument(
        string $nomorInduk,
        CurrentUser $user,
        ?string $judul,
        ?string $keterangan,
        string $originalName,
        string $mimeType,
        string $bytes,
    ): array {
        $this->assertWrite($user);
        $this->access->assertIdentitasNomorInduk($nomorInduk, $user);
        $this->validatePdf($originalName, $mimeType, $bytes);

        $uptId = $this->resolveUptId($nomorInduk, $user);
        $stored = $this->storeFile($uptId, $originalName, $bytes);
        $id     = $this->nextDocumentId($uptId, $user);

        $this->dokumen->insert([
            'ID_DOKUMEN'        => $id,
            'ID_UPT'            => $user->isKanwil ? null : $uptId,
            'ID_KANWIL'         => $user->isKanwil ? $user->uptId : null,
            'ID_PERKARA'        => null,
            'ID_REFERENCE'      => $nomorInduk,
            'JENIS_DOKUMEN'     => self::JENIS_IDENTITAS,
            'SUB_JENIS_DOKUMEN' => null,
            'URL'               => $stored['relative'],
            'NAMA_FILE'         => $stored['fileName'],
            'MIME_TYPE'         => $stored['mimeType'],
            'JUDUL'             => $judul,
            'KETERANGAN'        => $keterangan,
            'TGL_ENTRY'         => date('Y-m-d H:i:s'),
            'CREATED_BY'        => $user->id,
            'CREATED'           => date('Y-m-d H:i:s'),
            'IS_DELETED'        => 0,
            'KONSOLIDASI'       => 0,
        ]);

        $row = $this->dokumen->find($id);

        return $row !== null ? $this->mapRow($row) : ['id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateDocument(
        string $idDokumen,
        CurrentUser $user,
        ?string $judul,
        ?string $keterangan,
        ?string $originalName,
        ?string $mimeType,
        ?string $bytes,
    ): array {
        $this->assertWrite($user);

        $row = $this->dokumen->find($idDokumen);
        if ($row === null) {
            throw new DokumenException('Dokumen tidak ditemukan.', [], 404);
        }

        $this->access->assertRowAccessible($row, $user);

        $update = [
            'JUDUL'       => $judul ?? ($row['JUDUL'] ?? null),
            'KETERANGAN'  => $keterangan ?? ($row['KETERANGAN'] ?? null),
            'KONSOLIDASI' => 1,
            'UPDATED_BY'  => $user->id,
            'UPDATED'     => date('Y-m-d H:i:s'),
        ];

        if ($bytes !== null && $originalName !== null) {
            $this->validatePdf($originalName, $mimeType ?? 'application/pdf', $bytes);
            $uptId  = trim((string) ($row['ID_UPT'] ?? $user->uptId ?? ''));
            $stored = $this->storeFile($uptId !== '' ? $uptId : '000', $originalName, $bytes);
            $update['URL']       = $stored['relative'];
            $update['NAMA_FILE'] = $stored['fileName'];
            $update['MIME_TYPE'] = $stored['mimeType'];
        }

        $this->dokumen->update($idDokumen, $update);
        $fresh = $this->dokumen->find($idDokumen);

        return $fresh !== null ? $this->mapRow($fresh) : ['id' => $idDokumen];
    }

    public function deleteDocument(string $idDokumen, CurrentUser $user): void
    {
        $this->assertWrite($user);

        $row = $this->dokumen->find($idDokumen);
        if ($row === null) {
            throw new DokumenException('Dokumen tidak ditemukan.', [], 404);
        }

        $this->access->assertRowAccessible($row, $user);
        $this->dokumen->softDelete($idDokumen);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $id = (string) $row['ID_DOKUMEN'];

        return [
            'id'          => $id,
            'judul'       => $row['JUDUL'] ?? null,
            'keterangan'  => $row['KETERANGAN'] ?? null,
            'namaFile'    => $row['NAMA_FILE'] ?? null,
            'mimeType'    => $row['MIME_TYPE'] ?? null,
            'downloadUrl' => '/api/v1/documents/' . rawurlencode($id) . '/download',
            'viewUrl'     => '/api/v1/documents/' . rawurlencode($id) . '/view',
        ];
    }

    private function assertWrite(CurrentUser $user): void
    {
        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canWrite']) {
            throw new DokumenException('Mohon maaf anda tidak berhak mengubah dokumen.', [], 403);
        }
    }

    private function resolveUptId(string $nomorInduk, CurrentUser $user): string
    {
        $uptId = trim((string) ($user->uptId ?? ''));
        if ($uptId !== '') {
            return $uptId;
        }

        $ctx = $this->identitas->activeStaParentPerkaraContext($nomorInduk);
        $fallback = trim((string) ($ctx['idUpt'] ?? ''));

        return $fallback !== '' ? $fallback : '000';
    }

    private function nextDocumentId(string $uptId, CurrentUser $user): string
    {
        if ($user->isPusat) {
            return $this->dokumen->nextId(date('Ymd'));
        }

        if ($user->isKanwil) {
            return $this->dokumen->nextId((string) $user->uptId . date('Ymd'));
        }

        return $this->dokumen->nextId($uptId . date('Ymd'));
    }

    private function validatePdf(string $originalName, string $mimeType, string $bytes): void
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new DokumenException('Ukuran file melebihi 2 MB.', ['file' => 'Maksimum 2 MB.']);
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf' && $mimeType !== 'application/pdf') {
            throw new DokumenException('Hanya file PDF yang diizinkan.', ['file' => 'Format harus PDF.']);
        }
    }

    /**
     * @return array{relative: string, fileName: string, mimeType: string}
     */
    private function storeFile(string $uptId, string $originalName, string $bytes): array
    {
        $root = StoredUploadPath::legacyRoot();
        $dir  = $root . '/upload/dokumen/' . $uptId . '/' . date('Y') . '/' . date('m');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new DokumenException('Gagal menyiapkan folder upload.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($originalName)) ?: 'dokumen.pdf';
        $stored   = date('YmdHis') . '_' . uniqid('', true) . '_' . $safeName;
        $absolute = $dir . '/' . $stored;
        if (file_put_contents($absolute, $bytes) === false) {
            throw new DokumenException('Gagal menyimpan berkas dokumen.');
        }

        $relative = 'upload/dokumen/' . $uptId . '/' . date('Y') . '/' . date('m') . '/' . $stored;

        return [
            'relative' => $relative,
            'fileName' => $safeName,
            'mimeType' => 'application/pdf',
        ];
    }

    private function absolutePath(string $url): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $url), './');

        return StoredUploadPath::absolute(StoredUploadPath::legacyRoot(), $normalized);
    }
}
