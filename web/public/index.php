<?php

declare(strict_types=1);

/**
 * ForgePanel — front controller (panel nginx :8443 → PHP-FPM 8.4, user fpanel).
 * Web sloj NEMA root i NIKAD ne izvršava shell komande — sve ide kroz agent socket.
 */

require dirname(__DIR__) . '/src/Autoloader.php';

ForgePanel\Web\Autoloader::register(dirname(__DIR__) . '/src');

use ForgePanel\Web\Core\App;

App::boot(getenv('FORGEPANEL_CONFIG') ?: '/etc/forgepanel/web.ini')->handle();
