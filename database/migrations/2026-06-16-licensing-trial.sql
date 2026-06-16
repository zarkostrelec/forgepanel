-- Probni period (7 dana) — master bilježi prvi kontakt svakog nodea po fingerprintu.
-- Primijeniti na MASTER (elite):  mysql forgepanel < database/migrations/2026-06-16-licensing-trial.sql
CREATE TABLE IF NOT EXISTS trials (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    fingerprint     VARCHAR(64) NOT NULL UNIQUE,
    version         VARCHAR(32) NULL,
    ip              VARCHAR(45) NULL,
    first_seen      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
