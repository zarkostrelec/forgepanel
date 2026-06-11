<?php

declare(strict_types=1);

/**
 * phpMyAdmin auto-login (signon): panel izda signed one-time token
 * (POST /api/v1/databases/{id}/pma), ova skripta ga potroši i otvori
 * PMA signon session (config.inc.php: auth_type=signon, SignonSession=FPpmaSignon).
 * Servira je panel nginx direktno (location = /pma-signon.php), izvan SPA front controllera.
 */

require dirname(__DIR__) . '/src/Autoloader.php';

ForgePanel\Web\Autoloader::register(dirname(__DIR__) . '/src');

use ForgePanel\Web\Core\Config;
use ForgePanel\Web\Core\Crypto;
use ForgePanel\Web\Core\Db;
use ForgePanel\Web\Core\SignedToken;

$fail = static function (string $reason): never {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("phpMyAdmin auto-login odbijen: $reason\n");
};

$config = Config::load(getenv('FORGEPANEL_CONFIG') ?: '/etc/forgepanel/web.ini');
$db = new Db($config);

$payload = SignedToken::verify((string) ($_GET['token'] ?? ''), $config->get('app_secret', ''));
if ($payload === null) {
    $fail('token neispravan ili istekao');
}

// Jednokratnost: jti mora postojati i briše se odmah (race-safe kroz rowCount)
$consumed = $db->run('DELETE FROM settings WHERE `key` = ?', ['pma_jti_' . $payload['jti']])->rowCount();
if ($consumed === 0) {
    $fail('token već iskorišten');
}

$db_user = $db->one('SELECT username, password_enc FROM db_users WHERE id = ?', [(int) $payload['db_user_id']]);
if ($db_user === null || $db_user['password_enc'] === null) {
    $fail('nepoznat db user');
}

session_name('FPpmaSignon');
session_start();
session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = (string) $db_user['username'];
$_SESSION['PMA_single_signon_password'] = (new Crypto($config))->decrypt((string) $db_user['password_enc']);
$_SESSION['PMA_single_signon_host'] = 'localhost';
session_write_close();

header('Location: /pma/index.php' . (isset($payload['db']) ? '?db=' . rawurlencode((string) $payload['db']) : ''));
exit;
