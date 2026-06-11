<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * git.deploy — fetch + hard reset na branch u docroot vhosta.
 * Radi i u nepraznom docrootu (init + remote + fetch). Post-deploy
 * akcije isključivo iz whiteliste, izvršavaju se kao vhost user.
 */
final class GitDeploy extends Operation
{
    private const URL_RE = '#^(https://[\w.@:/~+-]+|git://[\w./~+-]+|ssh://[\w.@:/~+-]+|git@[\w.-]+:[\w./~+-]+)$#';
    private const POST_DEPLOY_WHITELIST = ['composer_install'];

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        self::assertRepoUrl($params['repo_url'] ?? null);
        self::assertBranch($params['branch'] ?? null);
        foreach ($params['post_deploy'] ?? [] as $action) {
            if (!in_array($action, self::POST_DEPLOY_WHITELIST, true)) {
                throw new ValidationException("post_deploy akcija nije na whitelisti: $action");
            }
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $repo_url = self::assertRepoUrl($params['repo_url']);
        $branch = self::assertBranch($params['branch']);
        $sys_user = 'vh_' . $vhost_id;
        $docroot = Validator::VHOST_ROOT . "/$domain/httpdocs";
        if (!is_dir($docroot)) {
            throw new ValidationException("Docroot ne postoji: $docroot");
        }

        $key_path = GitKeygen::KEY_DIR . "/vh_{$vhost_id}_deploy";
        $env_ssh = is_file($key_path)
            ? "ssh -i $key_path -o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes"
            : 'ssh -o StrictHostKeyChecking=accept-new';

        // -c safe.directory: root radi s repoom u vlasništvu vhost usera (bez globalnog configa)
        $git = fn (array $args) => Proc::mustRun(
            ['env', "GIT_SSH_COMMAND=$env_ssh", 'git', '-c', "safe.directory=$docroot", '-C', $docroot, ...$args],
            timeout_s: 600,
            on_line: $context->output(...),
        );

        if (!is_dir("$docroot/.git")) {
            $context->output("git init u $docroot");
            $git(['init', '--initial-branch', $branch]);
        }
        $has_origin = Proc::run(
            ['git', '-c', "safe.directory=$docroot", '-C', $docroot, 'remote', 'get-url', 'origin']
        )->ok();
        $git(['remote', $has_origin ? 'set-url' : 'add', 'origin', $repo_url]);

        $context->output("Fetch $branch s $repo_url");
        $context->progress(30);
        $git(['fetch', '--depth', '50', 'origin', $branch]);
        $git(['checkout', '-f', '-B', $branch, 'FETCH_HEAD']);
        $git(['reset', '--hard', 'FETCH_HEAD']);
        $context->progress(60);

        $commit = trim(Proc::mustRun(['git', '-c', "safe.directory=$docroot", '-C', $docroot, 'rev-parse', 'HEAD'])->stdout);
        $context->output("Deploy na commit $commit");

        Proc::mustRun(['chown', '-R', "$sys_user:$sys_user", $docroot]);
        $context->progress(75);

        foreach ($params['post_deploy'] ?? [] as $action) {
            if ($action === 'composer_install' && is_file("$docroot/composer.json")) {
                if (Proc::run(['which', 'composer'])->ok()) {
                    $context->output('composer install (kao vhost user)');
                    Proc::mustRun(
                        ['runuser', '-u', $sys_user, '--', 'composer', 'install',
                            '--no-dev', '--no-interaction', '--working-dir', $docroot],
                        timeout_s: 900,
                        on_line: $context->output(...),
                    );
                } else {
                    $context->output('composer nije instaliran — preskačem composer_install');
                }
            }
        }

        $this->db->run(
            'UPDATE git_repos SET last_commit = ?, last_deploy_at = NOW() WHERE vhost_id = ?',
            [$commit, $vhost_id]
        );
        $context->progress(100);
        $context->output('Deploy završen.');

        return ['commit' => $commit];
    }

    public static function assertRepoUrl(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 512 || !preg_match(self::URL_RE, $url)) {
            throw new ValidationException('repo_url mora biti https://, git://, ssh:// ili git@host:path');
        }
        return $url;
    }

    public static function assertBranch(mixed $branch): string
    {
        if (!is_string($branch) || !preg_match('#^[\w][\w./-]{0,127}$#', $branch) || str_contains($branch, '..')) {
            throw new ValidationException('branch nije ispravan');
        }
        return $branch;
    }
}
