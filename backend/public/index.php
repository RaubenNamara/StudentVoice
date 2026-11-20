<?php

declare(strict_types=1);

/**
 * The API's single entry point: every /api/... request comes here (see the root .htaccess).
 */

require __DIR__ . '/../bootstrap.php';

use StudentVoice\Auth\Auth;
use StudentVoice\Config;
use StudentVoice\Http\Request;

ini_set('display_errors', Config::isDebug() ? '1' : '0');
error_reporting(E_ALL);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (Config::isProduction()) {
    header('Strict-Transport-Security: max-age=31536000');
}

$request = Request::capture();
Auth::startSession();

$router = require __DIR__ . '/../routes.php';
$router->dispatch($request);
