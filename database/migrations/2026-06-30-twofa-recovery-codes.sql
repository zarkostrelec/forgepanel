-- Backend-enforced 2FA + recovery (backup) kodovi.
-- Obavezna 2FA (admin uvijek; ostale role po settings politici 'twofa_enforce_roles')
-- više se ne smije zaobići kroz /api/v1: korisnik bez 2FA dobiva ograničenu setup-sesiju.
-- Recovery kodovi su anti-lockout kad korisnik izgubi TOTP uređaj (jednokratni, hashirani).
-- Primijeniti na postojeću instalaciju:
--   mysql forgepanel < database/migrations/2026-06-30-twofa-recovery-codes.sql
CREATE TABLE IF NOT EXISTS twofa_recovery_codes (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    code_hash       CHAR(64) NOT NULL,                      -- sha256 normaliziranog koda
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_code (user_id, code_hash),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opcionalno: uključi obaveznu 2FA i za resellere (admin je uvijek obavezan):
--   INSERT INTO settings (`key`, value) VALUES ('twofa_enforce_roles', '["reseller"]')
--     ON DUPLICATE KEY UPDATE value = VALUES(value);
