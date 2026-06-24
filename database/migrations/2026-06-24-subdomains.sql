-- Poddomene kao prvorazredni vhostovi (Plesk-style "Dodaj poddomenu") + per-plan limit.
-- Poddomena = vhost s vlastitim docrootom/FPM poolom/SSL-om, vezan na matični vhost
-- (parent_vhost_id) i pretplatu matičnog. Limit broja poddomena ide po pretplati (planu).
-- Primijeniti na postojeću instalaciju:
--   mysql forgepanel < database/migrations/2026-06-24-subdomains.sql
ALTER TABLE vhosts
    ADD COLUMN parent_vhost_id INT UNSIGNED NULL AFTER subscription_id,
    ADD KEY idx_parent_vhost (parent_vhost_id);

ALTER TABLE plans
    ADD COLUMN max_subdomains INT UNSIGNED NOT NULL DEFAULT 10 AFTER max_domains;
