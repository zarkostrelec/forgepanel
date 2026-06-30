<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Git deploy (Plesk-style): repo + deploy key + webhook + auto-deploy na push. */
final class GitController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts/{id}/git', $this->show(...));
        $router->add('POST', '/api/v1/vhosts/{id}/git', $this->configure(...));
        $router->add('POST', '/api/v1/vhosts/{id}/git/deploy', $this->deploy(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}/git', $this->remove(...));
        // Webhook: bez Bearer auth — autentikacija je tajna u URL-u (per repo)
        $router->add('POST', '/api/v1/git/webhook/{secret}', $this->webhook(...));
    }

    private function show(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'git');
        $repo = $this->app->db->one(
            'SELECT id, repo_url, branch, deploy_key, webhook_secret, last_commit, last_deploy_at, post_deploy
             FROM git_repos WHERE vhost_id = ?',
            [$vhost['id']]
        );
        Response::ok($repo);
    }

    private function configure(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'git');

        $repo_url = trim($request->str('repo_url') ?? '');
        $branch = trim($request->str('branch') ?? 'main');
        if (!preg_match('#^(https://|git://|ssh://|git@[\w.-]+:)#', $repo_url) || strlen($repo_url) > 512) {
            throw new HttpException(422, 'invalid_repo_url');
        }
        if (!preg_match('#^[\w][\w./-]{0,127}$#', $branch) || str_contains($branch, '..')) {
            throw new HttpException(422, 'invalid_branch');
        }
        $post_deploy = $request->body['post_deploy'] ?? [];
        if (!is_array($post_deploy) || array_diff($post_deploy, ['composer_install']) !== []) {
            throw new HttpException(422, 'invalid_post_deploy');
        }

        // Deploy key (javni dio za prikaz; privatni ostaje na serveru)
        $key = $this->app->agent->call('git.keygen', ['vhost_id' => (int) $vhost['id']], timeout_s: 30);

        $existing = $this->app->db->one('SELECT id, webhook_secret FROM git_repos WHERE vhost_id = ?', [$vhost['id']]);
        $webhook_secret = $existing['webhook_secret'] ?? bin2hex(random_bytes(24));

        if ($existing === null) {
            $this->app->db->run(
                'INSERT INTO git_repos (vhost_id, repo_url, branch, deploy_key, webhook_secret, post_deploy)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$vhost['id'], $repo_url, $branch, $key['public_key'], $webhook_secret, json_encode(array_values($post_deploy))]
            );
        } else {
            $this->app->db->run(
                'UPDATE git_repos SET repo_url = ?, branch = ?, deploy_key = ?, post_deploy = ? WHERE id = ?',
                [$repo_url, $branch, $key['public_key'], json_encode(array_values($post_deploy)), $existing['id']]
            );
        }

        $panel_fqdn = $this->app->config->get('panel_fqdn', (string) gethostname());
        $this->app->audit->log($ctx->user_id, $ctx->email, 'git.configure', ['domain' => $vhost['domain'], 'repo' => $repo_url], $request->ip);
        Response::ok([
            'public_key' => $key['public_key'],
            'webhook_url' => "https://{$panel_fqdn}:8443/api/v1/git/webhook/{$webhook_secret}",
        ], 201);
    }

    private function deploy(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'git');
        $task_id = $this->enqueueDeploy((int) $vhost['id'], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'git.deploy', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function remove(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'git');
        $this->app->db->run('DELETE FROM git_repos WHERE vhost_id = ?', [$vhost['id']]);
        Response::ok();
    }

    /** Auto-deploy na push — GitHub/GitLab/Gitea zovu ovaj URL. */
    private function webhook(Request $request): never
    {
        $secret = $request->param('secret');
        if (!preg_match('/^[a-f0-9]{48}$/', $secret)) {
            throw new HttpException(404, 'not_found');
        }
        $repo = $this->app->db->one('SELECT vhost_id FROM git_repos WHERE webhook_secret = ?', [$secret]);
        if ($repo === null) {
            throw new HttpException(404, 'not_found');
        }
        // Bez gomilanja: ako deploy za ovaj vhost već čeka, ne dodaje se novi
        $pending = $this->app->db->one(
            "SELECT 1 FROM tasks WHERE op = 'git.deploy' AND status = 'pending'
             AND JSON_EXTRACT(params, '$.vhost_id') = ?",
            [$repo['vhost_id']]
        );
        $task_id = $pending !== null ? null : $this->enqueueDeploy((int) $repo['vhost_id'], null);
        $this->app->audit->log(null, 'webhook', 'git.webhook', ['vhost_id' => $repo['vhost_id']]);
        Response::ok(['queued' => $task_id !== null]);
    }

    private function enqueueDeploy(int $vhost_id, ?int $user_id): int
    {
        $vhost = $this->app->db->one('SELECT domain FROM vhosts WHERE id = ?', [$vhost_id])
            ?? throw new HttpException(404, 'not_found');
        $repo = $this->app->db->one('SELECT * FROM git_repos WHERE vhost_id = ?', [$vhost_id])
            ?? throw new HttpException(409, 'git_not_configured');

        return $this->app->tasks->enqueue('git.deploy', [
            'vhost_id' => $vhost_id,
            'domain' => $vhost['domain'],
            'repo_url' => $repo['repo_url'],
            'branch' => $repo['branch'],
            'post_deploy' => json_decode((string) ($repo['post_deploy'] ?? '[]'), true) ?: [],
        ], $user_id);
    }
}
