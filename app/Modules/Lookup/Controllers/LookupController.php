<?php

namespace App\Modules\Lookup\Controllers;

use App\Controllers\BaseController;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;

final class LookupController extends BaseController
{
    public function group(string $group)
    {
        if ($group === 'upt') {
            return $this->uptList();
        }

        if ($group === 'kanwil') {
            return $this->kanwilList();
        }

        $map = [
            'agama'           => 'Agama',
            'pendidikan'      => 'Pendidikan',
            'kelamin'         => 'Kelamin',
            'kewarganegaraan' => 'Kewarganegaraan',
        ];

        $groups = $map[$group] ?? null;
        if ($groups === null) {
            return ApiResponse::fail('Lookup tidak dikenal.', 404);
        }

        $rows = db_connect()->table('daftar_referensi')
            ->select('ID_LOOKUP AS id, DESKRIPSI AS label')
            ->where('GROUPS', $groups)
            ->orderBy('DESKRIPSI', 'ASC')
            ->get()
            ->getResultArray();

        return ApiResponse::ok(['items' => $rows]);
    }

    private function uptList()
    {
        $user   = AuthContext::user();
        $kanwil = trim((string) ($this->request->getGet('kanwil') ?? ''));

        if ($user->isKanwil) {
            $kanwil = (string) $user->uptId;
        }

        $builder = db_connect()->table('upt')
            ->select('ID_UPT AS id, URAIAN AS label, KANWIL AS kanwilId')
            ->orderBy('URAIAN', 'ASC');

        if ($kanwil !== '' && strcasecmp($kanwil, 'semua') !== 0) {
            $builder->where('KANWIL', $kanwil);
        }

        return ApiResponse::ok(['items' => $builder->get()->getResultArray()]);
    }

    private function kanwilList()
    {
        $rows = db_connect()->table('kanwil')
            ->select('KODE AS id, URAIAN AS label')
            ->orderBy('URAIAN', 'ASC')
            ->get()
            ->getResultArray();

        return ApiResponse::ok(['items' => $rows]);
    }
}
