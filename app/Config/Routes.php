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

    $routes->group('', ['filter' => 'jwt'], static function (RouteCollection $routes) {
        $routes->get('auth/me', '\App\Modules\Auth\Controllers\AuthController::me');
        $routes->get('lookups/(:segment)', '\App\Modules\Lookup\Controllers\LookupController::group/$1');

        $routes->group('', ['filter' => 'menu:10'], static function (RouteCollection $routes) {
            $routes->get('identities', '\App\Modules\Identity\Controllers\IdentitySearchController::index');
            $routes->get('identities/export', '\App\Modules\Identity\Controllers\IdentitySearchController::export');
            $routes->get('identities/(:segment)', '\App\Modules\Identity\Controllers\IdentityDetailController::show/$1');
        });
    });
});
