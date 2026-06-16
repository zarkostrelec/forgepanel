-- Legacy PHP (Docker) backend — proširenje web_backend enuma.
-- Primijeniti na postojeću instalaciju (fresh install već ima novu vrijednost iz schema.sql):
--   mysql forgepanel < database/migrations/2026-06-16-web_backend-php_legacy.sql
ALTER TABLE vhosts
    MODIFY web_backend ENUM('nginx','nginx_apache','php_legacy') NOT NULL DEFAULT 'nginx';
