<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Legacy PHP runtime preko Dockera (npr. PHP 7.2.34 za stare stranice).
 * Nativni apt PHP 7.x ne postoji za Ubuntu 26.04 (ondrej ne builda), pa legacy
 * ide isključivo kroz izolirani FPM container. DB ('localhost') radi preko
 * mountanog MariaDB socketa — bez diranja aplikacijskog koda (wp-config i sl.).
 */
final class LegacyPhp
{
    public const PHP_VERSION = '7.2.34';
    public const IMAGE = 'forgepanel/php-legacy:7.2.34';
    private const CONF_DIR = '/etc/forgepanel/legacy';
    private const DB_SOCKET = '/run/mysqld/mysqld.sock';

    public static function containerName(int $vhost_id): string
    {
        return "fp-{$vhost_id}-php72";
    }

    /** Deterministički loopback port FPM containera (nginx fastcgi_pass). */
    public static function port(int $vhost_id): int
    {
        return 20000 + ($vhost_id % 40000);
    }

    /** Sagradi custom image s ekstenzijama (idempotentno — preskoči ako postoji). */
    public static function ensureImage(?\Closure $log = null): void
    {
        DockerCli::ensureInstalled($log);
        if (Proc::run(['docker', 'image', 'inspect', self::IMAGE])->ok()) {
            return;
        }
        $log?->__invoke('Gradim legacy PHP image ' . self::IMAGE . " (jednokratno, par minuta)\n");
        $dir = sys_get_temp_dir() . '/fp-php72-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/Dockerfile", self::dockerfile());
        try {
            Proc::mustRun(['docker', 'build', '-t', self::IMAGE, $dir], timeout_s: 2400, on_line: $log);
        } finally {
            @unlink("$dir/Dockerfile");
            @rmdir($dir);
        }
    }

    /** Pokreni (ili ponovno kreiraj) FPM container za vhost; vraća loopback port. */
    public static function up(int $vhost_id, string $domain, string $vhost_root, ?\Closure $log = null): int
    {
        $name = self::containerName($vhost_id);
        $port = self::port($vhost_id);
        $sys_user = 'vh_' . $vhost_id;
        $pw = posix_getpwnam($sys_user);
        if ($pw === false) {
            throw new \RuntimeException("Sistemski user $sys_user ne postoji");
        }
        $uid = (int) $pw['uid'];
        $gid = (int) $pw['gid'];

        if (!is_dir(self::CONF_DIR)) {
            mkdir(self::CONF_DIR, 0o755, true);
        }
        $pool = self::CONF_DIR . "/{$vhost_id}-www.conf";
        file_put_contents($pool, self::poolConf());

        Proc::run(['docker', 'rm', '-f', $name]); // recreate (ignoriraj ako ne postoji)

        $argv = ['docker', 'run', '-d', '--name', $name,
            '--restart', 'unless-stopped',
            '--user', "{$uid}:{$gid}",
            '-v', "{$vhost_root}:{$vhost_root}",
            '-v', "{$pool}:/usr/local/etc/php-fpm.d/www.conf:ro",
        ];
        if (file_exists(self::DB_SOCKET)) {
            // 'localhost' u legacy aplikaciji → host MariaDB preko socketa
            $argv[] = '-v';
            $argv[] = self::DB_SOCKET . ':' . self::DB_SOCKET;
        }
        $argv[] = '-p';
        $argv[] = "127.0.0.1:{$port}:9000";
        array_push($argv, '--memory', '512m', '--pids-limit', '256', self::IMAGE);

        $log?->__invoke("docker run {$name} (PHP " . self::PHP_VERSION . " → 127.0.0.1:{$port})\n");
        Proc::mustRun($argv, timeout_s: 180);
        return $port;
    }

    /** Zaustavi i ukloni legacy container (sigurno i ako ne postoji / Docker nije instaliran). */
    public static function down(int $vhost_id, ?\Closure $log = null): void
    {
        Proc::run(['docker', 'rm', '-f', self::containerName($vhost_id)]);
        @unlink(self::CONF_DIR . "/{$vhost_id}-www.conf");
        $log?->__invoke('Legacy PHP container uklonjen za vhost ' . $vhost_id . "\n");
    }

    private static function poolConf(): string
    {
        return <<<'CONF'
        [www]
        listen = 9000
        listen.backlog = 256
        pm = ondemand
        pm.max_children = 12
        pm.process_idle_timeout = 30s
        pm.max_requests = 500
        clear_env = no
        catch_workers_output = yes
        php_admin_value[memory_limit] = 256M
        php_admin_value[upload_max_filesize] = 64M
        php_admin_value[post_max_size] = 64M
        php_admin_value[mysqli.default_socket] = /run/mysqld/mysqld.sock
        php_admin_value[pdo_mysql.default_socket] = /run/mysqld/mysqld.sock
        php_admin_value[opcache.enable] = 1
        php_admin_value[opcache.memory_consumption] = 128
        php_admin_value[opcache.max_accelerated_files] = 10000
        CONF;
    }

    private static function dockerfile(): string
    {
        // buster je arhiviran → apt prebacujemo na archive.debian.org prije instalacije
        return <<<'DOCKER'
        FROM php:7.2.34-fpm-buster
        RUN set -eux; \
            sed -i -e 's|deb.debian.org|archive.debian.org|g' -e 's|security.debian.org|archive.debian.org|g' -e '/buster-updates/d' /etc/apt/sources.list; \
            apt-get -o Acquire::Check-Valid-Until=false update; \
            apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev; \
            docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/; \
            docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd mbstring zip exif bcmath opcache; \
            rm -rf /var/lib/apt/lists/*
        DOCKER;
    }
}
