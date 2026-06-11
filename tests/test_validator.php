<?php

declare(strict_types=1);

use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

// FQDN
T::assertSame('example.com', Validator::fqdn('Example.COM'), 'fqdn normalizira lowercase');
T::assertSame('sub.domena-x.hr', Validator::fqdn('sub.domena-x.hr'), 'fqdn prihvaća subdomenu');
T::assertThrows(ValidationException::class, fn () => Validator::fqdn('nema_tocke'), 'fqdn odbija bez TLD-a');
T::assertThrows(ValidationException::class, fn () => Validator::fqdn('-x.hr'), 'fqdn odbija vodeću crticu');
T::assertThrows(ValidationException::class, fn () => Validator::fqdn('a.hr; rm -rf /'), 'fqdn odbija injection');
T::assertThrows(ValidationException::class, fn () => Validator::fqdn(123), 'fqdn odbija ne-string');
T::assertThrows(ValidationException::class, fn () => Validator::fqdn(str_repeat('a', 250) . '.hr'), 'fqdn odbija predugo');

// identifier
T::assertSame('moja_baza1', Validator::identifier('moja_baza1', 'name'), 'identifier prihvaća snake_case');
T::assertThrows(ValidationException::class, fn () => Validator::identifier('1pocinje_brojem', 'name'), 'identifier odbija broj na početku');
T::assertThrows(ValidationException::class, fn () => Validator::identifier('ime-s-crticom', 'name'), 'identifier odbija crticu');
T::assertThrows(ValidationException::class, fn () => Validator::identifier("x'; DROP TABLE--", 'name'), 'identifier odbija SQL injection');

// phpVersion
T::assertSame('8.4', Validator::phpVersion('8.4'), 'phpVersion prihvaća 8.4');
T::assertThrows(ValidationException::class, fn () => Validator::phpVersion('7.4'), 'phpVersion odbija 7.4');
T::assertThrows(ValidationException::class, fn () => Validator::phpVersion('8.4; whoami'), 'phpVersion odbija injection');

// vhostPath — realpath provjera protiv symlink trikova (treba /var/www/vhosts)
$can_setup = is_dir('/var/www/vhosts') || @mkdir('/var/www/vhosts', 0o755, true);
if ($can_setup) {
    $inside = '/var/www/vhosts/test-' . getmypid();
    mkdir($inside);
    try {
        T::assertSame($inside, Validator::vhostPath($inside), 'vhostPath prihvaća path unutar roota');
        T::assertSame($inside . '/novi/file.txt', Validator::vhostPath($inside . '/novi/file.txt'), 'vhostPath prihvaća nepostojeći path s postojećim roditeljem unutra');
        T::assertThrows(ValidationException::class, fn () => Validator::vhostPath('/etc/passwd'), 'vhostPath odbija /etc/passwd');
        T::assertThrows(ValidationException::class, fn () => Validator::vhostPath($inside . '/../../../etc'), 'vhostPath odbija .. bijeg');
        T::assertThrows(ValidationException::class, fn () => Validator::vhostPath("$inside/\0x"), 'vhostPath odbija null byte');

        // Symlink trik: link unutar vhosta pokazuje van → realpath ga mora uhvatiti
        symlink('/etc', $inside . '/zli_link');
        T::assertThrows(ValidationException::class, fn () => Validator::vhostPath($inside . '/zli_link/passwd'), 'vhostPath odbija symlink bijeg');
        unlink($inside . '/zli_link');
    } finally {
        rmdir($inside);
    }
} else {
    echo "  (preskačem vhostPath testove — /var/www/vhosts nije dostupan)\n";
}

// oneOf / positiveInt
T::assertSame('reload', Validator::oneOf('reload', ['reload', 'restart'], 'action'), 'oneOf prihvaća');
T::assertThrows(ValidationException::class, fn () => Validator::oneOf('stop', ['reload', 'restart'], 'action'), 'oneOf odbija');
T::assertSame(5, Validator::positiveInt(5, 'id'), 'positiveInt prihvaća');
T::assertThrows(ValidationException::class, fn () => Validator::positiveInt('5', 'id'), 'positiveInt odbija string');
T::assertThrows(ValidationException::class, fn () => Validator::positiveInt(0, 'id'), 'positiveInt odbija nulu');
