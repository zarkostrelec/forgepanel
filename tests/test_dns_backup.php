<?php

declare(strict_types=1);

use ForgePanel\Agent\Operations\BackupRestore;
use ForgePanel\Agent\System\BindConf;
use ForgePanel\Agent\ValidationException;

// DNS zone text
$records = [
    ['name' => '@', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'prio' => null],
    ['name' => 'www', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'prio' => null],
    ['name' => '@', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10],
    ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 a mx ~all', 'ttl' => 3600, 'prio' => null],
];
$zone = BindConf::zoneText('example.com', $records, 2026061101, 'ns1.panel.hr', 'ns2.panel.hr');
T::assert(str_contains($zone, 'IN SOA ns1.panel.hr.'), 'zona sadrži SOA');
T::assert(str_contains($zone, '@ 3600 IN A 203.0.113.10'), 'zona sadrži A zapis');
T::assert(str_contains($zone, '@ 3600 IN MX 10 mail.example.com.'), 'MX dobiva prio i točku');
T::assert(str_contains($zone, '"v=spf1 a mx ~all"'), 'TXT je quotan');

// TXT > 255 znakova se dijeli na chunkove (DKIM ključevi)
$long_txt = [['name' => 'x', 'type' => 'TXT', 'content' => str_repeat('A', 300), 'ttl' => 300, 'prio' => null]];
$zone2 = BindConf::zoneText('example.com', $long_txt, 1, 'ns1.p.hr', 'ns2.p.hr');
T::assert(substr_count($zone2, '"') === 4, 'dugi TXT podijeljen u 2 quotana stringa');

// Validacija sadržaja
$bad = static fn (string $type, string $content) => BindConf::zoneText(
    'example.com',
    [['name' => '@', 'type' => $type, 'content' => $content, 'ttl' => 300, 'prio' => null]],
    1,
    'ns1.p.hr',
    'ns2.p.hr'
);
T::assertThrows(ValidationException::class, fn () => $bad('A', 'nije.ip.adresa'), 'A odbija ne-IP');
T::assertThrows(ValidationException::class, fn () => $bad('A', '::1'), 'A odbija IPv6');
T::assertThrows(ValidationException::class, fn () => $bad('AAAA', '203.0.113.1'), 'AAAA odbija IPv4');
T::assertThrows(ValidationException::class, fn () => $bad('CNAME', 'bez tld'), 'CNAME odbija ne-FQDN');
T::assertThrows(ValidationException::class, fn () => $bad('A', "1.2.3.4\nzlo IN A 6.6.6.6"), 'odbija newline injection');
T::assertThrows(ValidationException::class, fn () => BindConf::zoneText(
    'example.com',
    [['name' => 'zlo)evil(', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 300, 'prio' => null]],
    1,
    'ns1.p.hr',
    'ns2.p.hr'
), 'odbija neispravno ime zapisa');

// Backup path guard
if (is_dir('/var/backups') || @mkdir('/var/backups', 0o755, true)) {
    @mkdir('/var/backups/forgepanel/test-' . getmypid(), 0o700, true);
    $inside = '/var/backups/forgepanel/test-' . getmypid();
    T::assertSame($inside, BackupRestore::backupPath($inside), 'backupPath prihvaća path unutar roota');
    T::assertThrows(ValidationException::class, fn () => BackupRestore::backupPath('/etc'), 'backupPath odbija /etc');
    T::assertThrows(ValidationException::class, fn () => BackupRestore::backupPath($inside . '/../../..'), 'backupPath odbija .. bijeg');
    rmdir($inside);
} else {
    echo "  (preskačem backupPath testove)\n";
}
