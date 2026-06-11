<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\CpanelImport;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/**
 * migrator.cpanel_parse — raspakira cpmove tar (ako treba) i parsira strukturu.
 * Dry-run friendly: vraća što bi se kreiralo, ništa ne mijenja. Sinkrono
 * (analyze treba rezultat odmah); za vrlo velike arhive UI pokazuje spinner.
 */
final class MigratorCpanelParse extends Operation
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

        $context->output('Raspakiravam cpmove arhivu');
        $context->progress(20);
        // Podržava .tar.gz i .tar
        $flags = str_ends_with($archive, '.gz') ? '-xzf' : '-xf';
        Proc::mustRun(['tar', $flags, $archive, '-C', $extract_dir], timeout_s: 1800);
        $context->progress(60);

        $context->output('Parsiram strukturu');
        $parsed = CpanelImport::parse($extract_dir);
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
