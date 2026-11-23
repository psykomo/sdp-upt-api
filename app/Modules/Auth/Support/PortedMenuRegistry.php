<?php

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Services\RbacService;

/**
 * Single source of truth for ported SDP menus exposed in /auth/me and the SPA shell.
 */
final class PortedMenuRegistry
{
    /**
     * @return list<array{id: string, key: string, label: string, path: string, icon: string, tipe: int}>
     */
    public static function all(): array
    {
        return [
            [
                'id'    => RbacService::MENU_IDENTITAS,
                'key'   => 'identitas',
                'label' => 'Data Identitas',
                'path'  => '/identitas',
                'icon'  => 'id-card',
                'tipe'  => 1,
            ],
        ];
    }
}
