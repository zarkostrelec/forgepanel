<?php

declare(strict_types=1);

use ForgePanel\Agent\System\CertUtil;
use ForgePanel\Agent\ValidationException;

/** CertUtil — validacija ručno uploadanih certifikata, s pravim openssl certovima. */

/** @return array{0: string, 1: string} [cert_pem, key_pem] */
function make_cert(string $cn, array $sans = [], int $days = 90): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $san_line = $sans === [] ? '' : 'subjectAltName = ' . implode(', ', array_map(fn ($s) => "DNS:$s", $sans)) . "\n";
    $cnf_path = tempnam(sys_get_temp_dir(), 'fpcnf');
    file_put_contents($cnf_path, <<<CNF
    [req]
    distinguished_name = dn
    x509_extensions = ext
    [dn]
    [ext]
    {$san_line}basicConstraints = CA:FALSE
    CNF);
    $csr = openssl_csr_new(['CN' => $cn], $key, ['config' => $cnf_path, 'digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, $days, ['config' => $cnf_path, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext']);
    openssl_x509_export($x509, $cert_pem);
    openssl_pkey_export($key, $key_pem);
    unlink($cnf_path);
    return [$cert_pem, $key_pem];
}

[$cert, $key] = make_cert('shop.example.hr', ['shop.example.hr', 'www.shop.example.hr']);

$info = CertUtil::validate($cert, $key, '', 'shop.example.hr');
T::assert(strtotime($info['expires_at']) > time() + 80 * 86400, 'validate vraća expires_at');
T::assertSame(['shop.example.hr', 'www.shop.example.hr'], $info['sans'], 'validate vraća SAN imena');

$www = CertUtil::validate($cert, $key, '', 'www.shop.example.hr');
T::assert($www !== [], 'SAN ime također prolazi');

// krivi ključ
[, $other_key] = make_cert('drugi.example.hr');
T::assertThrows(ValidationException::class, fn () => CertUtil::validate($cert, $other_key, '', 'shop.example.hr'), 'tuđi ključ odbijen');

// kriva domena
T::assertThrows(ValidationException::class, fn () => CertUtil::validate($cert, $key, '', 'nepokrivena.example.hr'), 'nepokrivena domena odbijena');

// istekao cert (negativni days ne rade u openssl_csr_sign → cert od 0 dana je istekao "sada";
// koristimo days=0 koji istječe odmah pa je validTo <= now)
[$old_cert, $old_key] = make_cert('staro.example.hr', [], 0);
sleep(1);
T::assertThrows(ValidationException::class, fn () => CertUtil::validate($old_cert, $old_key, '', 'staro.example.hr'), 'istekao cert odbijen');

// smeće umjesto PEM-a
T::assertThrows(ValidationException::class, fn () => CertUtil::validate('nije pem', $key, '', 'shop.example.hr'), 'ne-PEM cert odbijen');
T::assertThrows(ValidationException::class, fn () => CertUtil::validate($cert, 'nije pem', '', 'shop.example.hr'), 'ne-PEM ključ odbijen');
T::assertThrows(ValidationException::class, fn () => CertUtil::validate($cert, $key, 'nije chain', 'shop.example.hr'), 'ne-PEM chain odbijen');

// valjan chain prolazi (samopotpisani cert kao "CA")
[$ca_cert] = make_cert('Test CA');
$chained = CertUtil::validate($cert, $key, $ca_cert, 'shop.example.hr');
T::assert($chained !== [], 'valjan chain prihvaćen');

// ---------------------------------------------------------------- covers (wildcard pravila)

T::assertSame(true, CertUtil::covers(['*.example.hr'], 'shop.example.hr'), 'wildcard pokriva jednu razinu');
T::assertSame(false, CertUtil::covers(['*.example.hr'], 'a.b.example.hr'), 'wildcard NE pokriva dvije razine');
T::assertSame(false, CertUtil::covers(['*.example.hr'], 'example.hr'), 'wildcard NE pokriva goli apex');
T::assertSame(true, CertUtil::covers(['Example.HR'], 'example.hr'), 'usporedba je case-insensitive');
T::assertSame(false, CertUtil::covers(['zloexample.hr'], 'example.hr'), 'sufiks bez točke ne prolazi');
