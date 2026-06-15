<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\PleskImport;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/**
 * migrator.plesk_parse — raspakira Plesk backup (tar/tgz/zip) i parsira XML dump.
 * Dry-run friendly: vraća što bi se kreiralo, ništa ne mijenja.
 */
final class MigratorPleskParse extends Operation
{
    public const UPLOAD_DIR = '/var/lib/forgepanel/migrations';

    public function validate(array $params): void
    {
        $path = $params['archive_path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, self::UPLOAD_DIR . '/') || str_contains($path, '..')) {
            throw new ValidationException('archive_path mora biti unutar ' . self::UPLOAD_DIR);
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $archive = (string) $params['archive_path'];
        if (!is_file($archive)) {
            throw new ValidationException('Arhiva ne postoji');
        }

        $extract_dir = self::UPLOAD_DIR . '/extract-' . bin2hex(random_bytes(6));
        mkdir($extract_dir, 0o700, true);

        $context->output('Raspakiravam Plesk backup');
        $context->progress(20);
        if (str_ends_with($archive, '.zip')) {
            Proc::mustRun(['unzip', '-o', '-q', $archive, '-d', $extract_dir], timeout_s: 1800);
        } else {
            $flags = str_ends_with($archive, '.gz') || str_ends_with($archive, '.tgz') ? '-xzf' : '-xf';
            Proc::mustRun(['tar', $flags, $archive, '-C', $extract_dir], timeout_s: 1800);
        }
        $context->progress(60);

        $context->output('Parsiram XML dump');
        $parsed = PleskImport::parse($extract_dir);
        $parsed['extract_dir'] = $extract_dir;

        $context->progress(100);
        $context->output(sprintf(
            'Pronađeno: %d domena, %d baza, %d mailboxa',
            count($parsed['domains']),
            count($parsed['databases']),
            count($parsed['email_accounts'])
        ));
        return $parsed;
    }
}
