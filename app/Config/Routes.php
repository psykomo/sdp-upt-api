<?php

use App\Modules\Shared\Support\PublicOrigins;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', static function () {
    return service('response')->setJSON([
        'ok'       => true,
        'name'     => 'sdp-api',
        'version'  => '0.1.0',
        'api'      => PublicOrigins::apiBase(),
        'legacy'   => PublicOrigins::legacyBase(),
        'frontend' => PublicOrigins::frontendBase(),
    ]);
});

$routes->group('api/v1', static function (RouteCollection $routes) {
    $routes->options('(:any)', static fn () => service('response')->setStatusCode(204));

    $routes->post('auth/login', '\App\Modules\Auth\Controllers\AuthController::login');
    $routes->get('auth/login-options', '\App\Modules\Auth\Controllers\AuthController::loginOptions');
    $routes->post('auth/cookie', '\App\Modules\Auth\Controllers\AuthController::cookie');
    $routes->post('auth/logout', '\App\Modules\Auth\Controllers\AuthController::logout');

    // Queue API for legacy UPT (Secured via API Key filter / Service Token, not JWT user session)
    $routes->post('konsolidasi/upload', '\App\Modules\Queue\Controllers\QueueController::upload');
    $routes->get('konsolidasi/waktu',   '\App\Modules\Queue\Controllers\QueueController::waktu');
    $routes->get('queue/status/(:segment)', '\App\Modules\Queue\Controllers\QueueController::status/$1');
    $routes->post('queue/status',           '\App\Modules\Queue\Controllers\QueueController::bulkStatus');

    // changescompress: Pusat menyediakan data perubahan ke UPT / Kanwil
    // GET  → UPT/Kanwil menarik paket perubahan dari Pusat
    // POST → UPT/Kanwil menandai sudah berhasil di-restore
    $routes->get('konsolidasi/changes/upt/(:segment)',                         '\App\Modules\Queue\Controllers\KonsolidasiController::changesUpt/$1');
    $routes->get('konsolidasi/changes/kanwil/(:segment)',                      '\App\Modules\Queue\Controllers\KonsolidasiController::changesKanwil/$1');
    $routes->post('konsolidasi/changes/upt_readed/(:segment)/(:segment)',      '\App\Modules\Queue\Controllers\KonsolidasiController::uptReaded/$1/$2');
    $routes->post('konsolidasi/changes/kanwil_readed/(:segment)/(:segment)',   '\App\Modules\Queue\Controllers\KonsolidasiController::kanwilReaded/$1/$2');
    $routes->post('konsolidasi/changes/upt_readed_clear_temp/(:segment)',      '\App\Modules\Queue\Controllers\KonsolidasiController::uptClearTemp/$1');
    $routes->post('konsolidasi/changes/kanwil_readed_clear_temp/(:segment)',   '\App\Modules\Queue\Controllers\KonsolidasiController::kanwilClearTemp/$1');

    // Legacy CI2 REST API endpoints mapped to CI4 (Pusat)
    $routes->group('changescompress', static function (RouteCollection $routes) {
        $routes->get('upt/(:segment)',                         '\App\Modules\Queue\Controllers\KonsolidasiController::changesUpt/$1');
        $routes->get('kanwil/(:segment)',                      '\App\Modules\Queue\Controllers\KonsolidasiController::changesKanwil/$1');
        $routes->post('upt_readed/(:segment)/(:segment)',      '\App\Modules\Queue\Controllers\KonsolidasiController::uptReaded/$1/$2');
        $routes->post('kanwil_readed/(:segment)/(:segment)',   '\App\Modules\Queue\Controllers\KonsolidasiController::kanwilReaded/$1/$2');
        $routes->post('upt_readed_clear_temp/(:segment)',      '\App\Modules\Queue\Controllers\KonsolidasiController::uptClearTemp/$1');
        $routes->post('kanwil_readed_clear_temp/(:segment)',   '\App\Modules\Queue\Controllers\KonsolidasiController::kanwilClearTemp/$1');
    });

    $routes->group('', ['filter' => 'jwt'], static function (RouteCollection $routes) {
        $routes->get('auth/me', '\App\Modules\Auth\Controllers\AuthController::me');
        $routes->post('auth/working-scope', '\App\Modules\Auth\Controllers\AuthController::workingScope');
        $routes->post('auth/supervisor', '\App\Modules\Auth\Controllers\AuthController::supervisor');
        $routes->get('lookups/(:segment)', '\App\Modules\Lookup\Controllers\LookupController::group/$1');

        $routes->group('', ['filter' => 'menu:10'], static function (RouteCollection $routes) {
            $routes->get('identitas', '\App\Modules\Identitas\Controllers\IdentitasSearchController::index');
            $routes->get('identitas/export', '\App\Modules\Identitas\Controllers\IdentitasSearchController::export');
            $routes->get('identitas/form', '\App\Modules\Identitas\Controllers\IdentitasDetailController::createForm');
            $routes->post('identitas', '\App\Modules\Identitas\Controllers\IdentitasDetailController::create');
            $routes->get('identitas/(:segment)/documents', '\App\Modules\Dokumen\Controllers\IdentitasDokumenController::index/$1');
            $routes->post('identitas/(:segment)/documents', '\App\Modules\Dokumen\Controllers\IdentitasDokumenController::create/$1');
            $routes->get('identitas/(:segment)/form', '\App\Modules\Identitas\Controllers\IdentitasDetailController::form/$1');
            $routes->put('identitas/(:segment)', '\App\Modules\Identitas\Controllers\IdentitasDetailController::update/$1');
            $routes->delete('identitas/(:segment)', '\App\Modules\Identitas\Controllers\IdentitasDetailController::destroy/$1');
            $routes->get('identitas/(:segment)', '\App\Modules\Identitas\Controllers\IdentitasDetailController::show/$1');
            $routes->get('documents/(:segment)/download', '\App\Modules\Dokumen\Controllers\DokumenController::download/$1');
            $routes->get('documents/(:segment)/view', '\App\Modules\Dokumen\Controllers\DokumenController::view/$1');
            $routes->put('documents/(:segment)', '\App\Modules\Dokumen\Controllers\DokumenController::update/$1');
            $routes->delete('documents/(:segment)', '\App\Modules\Dokumen\Controllers\DokumenController::destroy/$1');
        });
    });
});
