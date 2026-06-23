-- Redirect vhost — domena koja samo radi 301/302 redirect na postojeću stranicu
-- (bez zasebnog FPM poola/docroota). Primijeniti na postojeću instalaciju:
--   mysql forgepanel < database/migrations/2026-06-23-vhost-redirect.sql
ALTER TABLE vhosts
    MODIFY web_backend ENUM('nginx','nginx_apache','php_legacy','redirect') NOT NULL DEFAULT 'nginx',
    ADD COLUMN redirect_target VARCHAR(255) NULL AFTER web_backend,
    ADD COLUMN redirect_code SMALLINT UNSIGNED NOT NULL DEFAULT 301 AFTER redirect_target;
