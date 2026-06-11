<?php

declare(strict_types=1);

/**
 * ForgePanel — front controller (panel nginx :8443 → PHP-FPM 8.4, user fpanel).
 * Web sloj NEMA root i NIKAD ne izvršava shell komande — sve ide kroz agent socket.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// PHP built-in server (dev): postojeće statičke fileove servira sam
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

require dirname(__DIR__) . '/src/Autoloader.php';

ForgePanel\Web\Autoloader::register(dirname(__DIR__) . '/src');

use ForgePanel\Web\Core\App;
use ForgePanel\Web\Core\Response;

// Jezični fajlovi (web/lang nije u docrootu)
if (preg_match('#^/lang/([a-z]{2})\.json$#', $path, $m)) {
    $lang_file = dirname(__DIR__) . "/lang/{$m[1]}.json";
    if (is_file($lang_file)) {
        Response::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        readfile($lang_file);
        exit;
    }
}

// SPA shell — sve što nije API vraća UI (hash routing na klijentu)
if (!str_starts_with($path, '/api/')) {
    Response::securityHeaders();
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    exit;
}

App::boot(getenv('FORGEPANEL_CONFIG') ?: '/etc/forgepanel/web.ini')->handle();
