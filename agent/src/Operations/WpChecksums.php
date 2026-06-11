<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * apps.wp_checksums — integritet WordPress corea: usporedba s wordpress.org
 * checksumovima, prikaz modificiranih core fileova (čest trag kompromitacije).
 */
final class WpChecksums extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $docroot = Validator::VHOST_ROOT . "/$domain/httpdocs";
        Validator::vhostPath($docroot);

        $version_file = "$docroot/wp-includes/version.php";
        if (!is_file($version_file)) {
            throw new ValidationException('WordPress nije pronađen na ovoj domeni');
        }
        if (!preg_match('/\$wp_version\s*=\s*\'([\d.]+)\'/', (string) file_get_contents($version_file), $m)) {
            throw new ValidationException('Ne mogu pročitati WP verziju');
        }
        $version = $m[1];

        $context->output("WP verzija $version — dohvaćam službene checksumove");
        $api = Proc::mustRun(['curl', '-fsSL',
            "https://api.wordpress.org/core/checksums/1.0/?version=$version&locale=en_US"], timeout_s: 60)->stdout;
        $data = json_decode($api, true);
        $checksums = $data['checksums'] ?? null;
        if (!is_array($checksums)) {
            throw new ValidationException('wordpress.org nije vratio checksumove za tu verziju');
        }
        $context->progress(40);

        $modified = [];
        $missing = [];
        $total = count($checksums);
        $i = 0;
        foreach ($checksums as $rel_path => $expected_md5) {
            $i++;
            if (str_contains($rel_path, '..')) {
                continue;
            }
            $full = "$docroot/$rel_path";
            if (!is_file($full)) {
                $missing[] = $rel_path;
                continue;
            }
            if (md5_file($full) !== $expected_md5) {
                $modified[] = $rel_path;
            }
            if ($i % 200 === 0) {
                $context->progress(40 + (int) (55 * $i / $total));
            }
        }

        $context->progress(100);
        $context->output('Modificiranih core fileova: ' . count($modified) . ', nedostaje: ' . count($missing));
        return [
            'version' => $version,
            'checked' => $total,
            'modified' => array_slice($modified, 0, 200),
            'missing' => array_slice($missing, 0, 200),
            'clean' => $modified === [] && $missing === [],
        ];
    }
}
