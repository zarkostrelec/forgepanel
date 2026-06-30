-- Baze podataka vezane na pojedinu domenu (Plesk-style): baza se kreira i prikazuje
-- u kontekstu domene (detalj domene → "Baze podataka"). vhost_id = NULL znači baza na
-- razini pretplate (bez vezane domene, stari zapisi). ON DELETE SET NULL: brisanje domene
-- NE briše bazu (čuva podatke) — samo je odveže (ostane vidljiva u globalnom popisu baza).
-- Primijeniti na postojeću instalaciju:
--   mysql forgepanel < database/migrations/2026-06-30-db-vhost-link.sql

ALTER TABLE db_databases
    ADD COLUMN vhost_id INT UNSIGNED NULL AFTER subscription_id,
    ADD CONSTRAINT fk_db_databases_vhost FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE SET NULL;
