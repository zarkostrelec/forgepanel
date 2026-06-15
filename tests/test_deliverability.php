<?php

declare(strict_types=1);

use ForgePanel\Agent\System\CpanelImport;
use ForgePanel\Agent\System\Deliverability;
use ForgePanel\Agent\System\DmarcIngest;
use ForgePanel\Agent\System\MailQueue;

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

// DMARC ingest — izvlačenje XML-a iz raznih MIME omotača
$plain_email = "From: dmarc@google.com\r\nSubject: report\r\nContent-Type: text/xml\r\n\r\n" . $dmarc_xml;
$extracted = DmarcIngest::extractReports($plain_email);
T::assertSame(1, count($extracted), 'DMARC ingest: plain text/xml izvučen');
T::assert(str_contains($extracted[0], '<feedback'), 'DMARC ingest: sadrži feedback');

// gzip prilog (base64) u multipart poruci
$gz = base64_encode((string) gzencode($dmarc_xml));
$mime = "Content-Type: multipart/mixed; boundary=\"BND\"\r\n\r\n"
    . "--BND\r\nContent-Type: text/plain\r\n\r\nReport attached.\r\n"
    . "--BND\r\nContent-Type: application/gzip; name=\"report.xml.gz\"\r\n"
    . "Content-Transfer-Encoding: base64\r\n"
    . "Content-Disposition: attachment; filename=\"report.xml.gz\"\r\n\r\n$gz\r\n--BND--\r\n";
$extracted_gz = DmarcIngest::extractReports($mime);
T::assertSame(1, count($extracted_gz), 'DMARC ingest: gzip prilog raspakiran');
T::assert(str_contains($extracted_gz[0], 'google.com'), 'DMARC ingest: gzip sadržaj parsiran');

// Poruka bez izvještaja → ništa
T::assertSame(0, count(DmarcIngest::extractReports("Content-Type: text/plain\r\n\r\nbok")), 'DMARC ingest: nema priloga');

// Postfix queue parser
$queue_json = '{"queue_name":"deferred","queue_id":"A1B2C3D4","arrival_time":1718000000,"message_size":2048,"sender":"a@example.com","recipients":[{"address":"x@dest.com","delay_reason":"connection refused"}]}' . "\n"
    . "garbage line\n"
    . '{"queue_name":"active","queue_id":"FFEE99","sender":"b@example.com","message_size":512,"recipients":[{"address":"y@dest.com"}]}';
$parsed_q = MailQueue::parseQueue($queue_json);
T::assertSame(2, count($parsed_q), 'MailQueue: 2 poruke, smeće preskočeno');
T::assertSame('A1B2C3D4', $parsed_q[0]['queue_id'], 'MailQueue: queue_id');
T::assertSame('connection refused', $parsed_q[0]['reason'], 'MailQueue: razlog deferrala');
T::assertSame('deferred', $parsed_q[0]['queue_name'], 'MailQueue: queue_name');
T::assertSame(['x@dest.com'], $parsed_q[0]['recipients'], 'MailQueue: primatelji');

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
