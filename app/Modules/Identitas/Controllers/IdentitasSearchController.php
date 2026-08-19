<?php

namespace App\Modules\Identitas\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\DTO\IdentitasSearchQuery;
use App\Modules\Identitas\Repositories\IdentitasSearchRepository;
use App\Modules\Identitas\Services\IdentitasCaseViewService;
use App\Modules\Identitas\Services\IdentitasSearchService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;

final class IdentitasSearchController extends BaseController
{
    private function service(): IdentitasSearchService
    {
        return new IdentitasSearchService(
            new IdentitasSearchRepository(),
            new IdentitasCaseViewService(),
            new RbacService(),
        );
    }

    public function index()
    {
        $query  = IdentitasSearchQuery::fromRequest($this->request->getGet() ?? []);
        $result = $this->service()->search($query, AuthContext::user());

        return ApiResponse::ok($result);
    }

    public function export()
    {
        $input = $this->request->getGet() ?? [];
        $input['exportAll'] = $this->request->getGet('exportAll') ?? '0';
        $query = IdentitasSearchQuery::fromRequest($input);
        $user  = AuthContext::user();
        $result = $this->service()->search($query, $user);

        $isHq = $user->isHq();
        $headers = $isHq
            ? ['No Induk', 'No. Registrasi', 'Tgl Msk UPT', 'Nama', 'Tanggal Lahir', 'Alamat', 'Asal UPT', 'Verifikasi']
            : ['No Induk', 'No. Registrasi', 'Tgl Msk UPT', 'Nama', 'Tanggal Lahir', 'Alamat', 'Agama', 'Verifikasi'];

        $fh = fopen('php://temp', 'w+');
        fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fh, $headers, ';');

        foreach ($result['items'] as $item) {
            fputcsv($fh, [
                $item['nomorInduk'],
                $item['nomorRegistrasi'],
                $item['tanggalMasukUpt'],
                $item['namaLengkap'],
                $item['tanggalLahir'],
                $item['alamat'],
                $isHq ? $item['asalUpt'] : $item['agama'],
                $item['verifikasi'],
            ], ';');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'pencarian-identitas-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }
}
