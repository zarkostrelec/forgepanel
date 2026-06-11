<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** docker.proxy_map — domena → container port jednim klikom (nginx reverse proxy). */
final class DockerProxyMap extends Operation
{
    public function validate(array $params): void
    {
        Validator::fqdn($params['domain'] ?? null);
        $port = $params['port'] ?? 0;
        if (!is_int($port) || $port < 1024 || $port > 65535) {
            throw new ValidationException('port mora biti 1024–65535');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $port = (int) $params['port'];

        $v6_80 = '';
        $v6_443 = '';
        if (is_readable('/proc/net/if_inet6') && trim((string) file_get_contents('/proc/net/if_inet6')) !== '') {
            $v6_80 = 'listen [::]:80;';
            $v6_443 = 'listen [::]:443 ssl;';
        }

        $conf = <<<NGINX
        # ForgePanel vhost — {$domain} (nginx → Docker container :{$port})
        server {
            listen 80;
            {$v6_80}
            server_name {$domain} www.{$domain};
            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }
            location / {
                return 301 https://\$host\$request_uri;
            }
        }
        server {
            listen 443 ssl;
            {$v6_443}
            http2 on;
            server_name {$domain} www.{$domain};

            ssl_certificate     /etc/forgepanel/ssl/{$domain}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$domain}/privkey.pem;

            location / {
                proxy_pass http://127.0.0.1:{$port};
                proxy_set_header Host \$host;
                proxy_set_header X-Real-IP \$remote_addr;
                proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto https;
                proxy_http_version 1.1;
                proxy_set_header Upgrade \$http_upgrade;
                proxy_set_header Connection "upgrade";
            }
        }
        NGINX;

        NginxConf::writeAndReload(NginxConf::VHOST_CONF_DIR . "/$domain.conf", $conf);
        return ['domain' => $domain, 'port' => $port];
    }
}
