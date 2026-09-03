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
    $routes->get('queue/status/(:segment)', '\App\Modules\Queue\Controllers\QueueController::status/$1');

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
