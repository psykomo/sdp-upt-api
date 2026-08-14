<?php

namespace App\Modules\Lookup\Controllers;

use App\Controllers\BaseController;
use App\Modules\Shared\Http\ApiResponse;

final class LookupController extends BaseController
{
    public function group(string $group)
    {
        $map = [
            'agama'       => 'Agama',
            'pendidikan'  => 'Pendidikan',
            'kelamin'     => 'Kelamin',
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
}
