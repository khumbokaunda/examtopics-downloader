<?php

declare(strict_types=1);

// Front controller: the only PHP file the web server may execute. Everything else
// (app/, config/, storage/, vendor/) lives outside this directory.

// PHP's built-in server (development/tests only) serves existing static files itself.
if (PHP_SAPI === 'cli-server') {
    $static = realpath(__DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if ($static !== false && str_starts_with($static, __DIR__ . '/assets/') && is_file($static)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Kernel;
use App\Core\NativeSession;

ini_set('display_errors', '0');
ini_set('expose_php', '0');
header_remove('X-Powered-By');

$app = App::boot();
$request = Request::fromGlobals((string) $app->config()->get('base_path', ''));

$session = new NativeSession();
$session->start($app->config(), $request->isHttps());

$router = new Router();
(require dirname(__DIR__) . '/routes/web.php')($router);

(new Kernel($app, $session, $router))->handle($request)->send();
