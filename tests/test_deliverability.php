<?php

declare(strict_types=1);

use ForgePanel\Agent\System\CpanelImport;
use ForgePanel\Agent\System\Deliverability;

// DMARC report parsing
$dmarc_xml = <<<XML
<?xml version="1.0"?>
<feedback>
  <report_metadata>
    <org_name>google.com</org_name>
    <date_range><begin>1718000000</begin><end>1718086400</end></date_range>
  </report_metadata>
  <record>
    <row>
      <source_ip>203.0.113.10</source_ip>
      <count>5</count>
      <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
    </row>
  </record>
  <record>
    <row>
      <source_ip>198.51.100.20</source_ip>
      <count>2</count>
      <policy_evaluated><disposition>quarantine</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated>
    </row>
  </record>
</feedback>
XML;
$report = Deliverability::parseDmarcReport($dmarc_xml);
T::assertSame('google.com', $report['org'], 'DMARC org parsiran');
T::assertSame(2, count($report['rows']), 'DMARC 2 zapisa');
T::assertSame('203.0.113.10', $report['rows'][0]['source_ip'], 'DMARC source_ip');
T::assertSame(5, $report['rows'][0]['count'], 'DMARC count');
T::assertSame('pass', $report['rows'][0]['dkim'], 'DMARC dkim pass');
T::assertSame('quarantine', $report['rows'][1]['disposition'], 'DMARC disposition quarantine');
T::assertThrows(\RuntimeException::class, fn () => Deliverability::parseDmarcReport('<nije>validan'), 'DMARC odbija loš XML');

// cPanel cpmove parser — sintetička struktura
$tmp = sys_get_temp_dir() . '/cpmove-test-' . getmypid();
mkdir("$tmp/cpmove-testuser/userdata", 0o755, true);
mkdir("$tmp/cpmove-testuser/mysql", 0o755, true);
mkdir("$tmp/cpmove-testuser/cp", 0o755, true);
mkdir("$tmp/cpmove-testuser/homedir/mail/example.com/ivan", 0o755, true);
file_put_contents("$tmp/cpmove-testuser/userdata/main", "main_domain: example.com\naddon_domains:\n  shop.example.org: shop\nsub_domains:\n  blog.example.com: blog\n");
file_put_contents("$tmp/cpmove-testuser/cp/testuser", '');
file_put_contents("$tmp/cpmove-testuser/mysql/testuser_wp.create", 'CREATE DATABASE');
file_put_contents("$tmp/cpmove-testuser/mysql.sql", "CREATE USER 'testuser_dbu'@'localhost';");

$parsed = CpanelImport::parse($tmp);
T::assertSame('example.com', $parsed['main_domain'], 'cpmove main domain');
T::assert(in_array('example.com', $parsed['domains'], true), 'cpmove glavna domena u listi');
T::assert(in_array('shop.example.org', $parsed['domains'], true), 'cpmove addon domena');
T::assert(in_array('testuser_wp', $parsed['databases'], true), 'cpmove baza iz .create');
T::assert(in_array('testuser_dbu', $parsed['db_users'], true), 'cpmove DB user iz mysql.sql');
T::assert(in_array('ivan@example.com', $parsed['email_accounts'], true), 'cpmove email iz maildira');
T::assert($parsed['has_homedir'], 'cpmove ima homedir');
T::assertSame('testuser', $parsed['username'], 'cpmove username iz cp/');

// cleanup
exec('rm -rf ' . escapeshellarg($tmp));
T::assertThrows(\RuntimeException::class, fn () => CpanelImport::parse('/nepostojeci/dir-' . getmypid()), 'cpmove odbija ne-cPanel strukturu');
