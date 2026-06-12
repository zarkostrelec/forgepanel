<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

use ForgePanel\Web\Api;

/** Sastavljanje aplikacije i registracija API ruta. UI je samo potrošač ovog API-ja. */
final class App
{
    public function __construct(
        public readonly Config $config,
        public readonly Db $db,
        public readonly Auth $auth,
        public readonly Audit $audit,
        public readonly AgentClient $agent,
        public readonly TaskQueue $tasks,
        public readonly Router $router,
    ) {
    }

    public static function boot(string $config_path): self
    {
        $config = Config::load($config_path);
        $db = new Db($config);
        $audit = new Audit($db);

        $app = new self(
            config: $config,
            db: $db,
            auth: new Auth($db, $audit),
            audit: $audit,
            agent: new AgentClient(),
            tasks: new TaskQueue($db),
            router: new Router(),
        );
        $app->registerRoutes();
        return $app;
    }

    public function handle(): never
    {
        $request = Request::fromGlobals();
        try {
            $this->router->dispatch($request);
        } catch (HttpException $e) {
            Response::error($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('forgepanel: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::error(500, 'internal_error');
        }
    }

    private function registerRoutes(): void
    {
        $controllers = [
            new Api\V1\AuthController($this),
            new Api\V1\VhostsController($this),
            new Api\V1\DatabasesController($this),
            new Api\V1\FilesController($this),
            new Api\V1\CronController($this),
            new Api\V1\DnsController($this),
            new Api\V1\FtpController($this),
            new Api\V1\BackupsController($this),
            new Api\V1\MailController($this),
            new Api\V1\GitController($this),
            new Api\V1\UpdatesController($this),
            new Api\V1\DockerController($this),
            new Api\V1\SecurityController($this),
            new Api\V1\FirewallController($this),
            new Api\V1\ConfigHistoryController($this),
            new Api\V1\BulkController($this),
            new Api\V1\UsersController($this),
            new Api\V1\BrandingController($this),
            new Api\V1\DelegationController($this),
            new Api\V1\DeliverabilityController($this),
            new Api\V1\StagingController($this),
            new Api\V1\CloudflareController($this),
            new Api\V1\AppsController($this),
            new Api\V1\MigratorController($this),
            new Api\V1\ProvisioningController($this),
            new Api\V1\NodeController($this),
            new Api\V1\AssistantController($this),
            new Api\V1\TerminalController($this),
            new Api\V1\ModulesController($this),
            new Api\V1\ServersController($this),
            new Api\V1\TasksController($this),
            new Api\V1\MonitoringController($this),
            new Api\V1\DashboardController($this),
            new Api\V1\SslController($this),
            new Api\V1\TokensController($this),
        ];
        foreach ($controllers as $controller) {
            $controller->register($this->router);
        }
    }
}
