-- ForgePanel — shema baze panela
-- Baza: forgepanel (utf8mb4, InnoDB), zaseban DB user, NIKAD dijeliti s hostanim bazama.
-- Sve veličine/kvote u INT/BIGINT bajtovima.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_ALL_TABLES';

CREATE TABLE roles (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name            VARCHAR(32) NOT NULL UNIQUE,            -- admin / reseller / client
    permissions     JSON NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,                  -- argon2id
    role_id         INT UNSIGNED NOT NULL,
    reseller_id     INT UNSIGNED NULL,                      -- vlasnik (za klijente resellera)
    twofa_secret    VARCHAR(64) NULL,                       -- TOTP base32, NULL = 2FA isključen
    lang            CHAR(2) NOT NULL DEFAULT 'hr',
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    failed_logins   INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (reseller_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plans (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    owner_user_id   INT UNSIGNED NULL,                      -- NULL = globalni (admin), inače reseller plan
    name            VARCHAR(64) NOT NULL,
    disk_bytes      BIGINT UNSIGNED NOT NULL,
    max_domains     INT UNSIGNED NOT NULL,
    max_subdomains  INT UNSIGNED NOT NULL DEFAULT 10,       -- limit poddomena po pretplati
    max_mailboxes   INT UNSIGNED NOT NULL,
    max_databases   INT UNSIGNED NOT NULL,
    php_versions    JSON NOT NULL,                          -- ["8.1","8.2","8.3","8.4"]
    features        JSON NOT NULL,
    cpu_quota_pct   INT UNSIGNED NOT NULL DEFAULT 100,      -- cgroup v2 CPUQuota po vhostu
    memory_max_bytes BIGINT UNSIGNED NOT NULL DEFAULT 536870912,
    tasks_max       INT UNSIGNED NOT NULL DEFAULT 128,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscriptions (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    plan_id         INT UNSIGNED NOT NULL,
    status          ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active',
    expires_at      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vhosts (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain          VARCHAR(255) NOT NULL UNIQUE,
    subscription_id INT UNSIGNED NOT NULL,
    parent_vhost_id INT UNSIGNED NULL,                      -- poddomena: matični vhost (Dodaj poddomenu)
    sys_user        VARCHAR(32) NOT NULL UNIQUE,            -- vh_<id>
    php_version     VARCHAR(8) NOT NULL DEFAULT '8.4',
    web_backend     ENUM('nginx','nginx_apache','php_legacy','redirect') NOT NULL DEFAULT 'nginx',
    redirect_target VARCHAR(255) NULL,                      -- web_backend='redirect': domena postojeće stranice
    redirect_code   SMALLINT UNSIGNED NOT NULL DEFAULT 301, -- 301 (trajni) ili 302 (privremeni)
    docroot         VARCHAR(512) NOT NULL,
    status          ENUM('active','suspended','creating','error') NOT NULL DEFAULT 'creating',
    disk_bytes      BIGINT UNSIGNED NULL,                  -- du -sb vhost roota (osvježava agent periodički)
    app_type        VARCHAR(32) NULL,                      -- detekcija aplikacije (wordpress/woocommerce/laravel/node/astro/static/php)
    traffic_7d      BIGINT UNSIGNED NULL,                  -- broj zahtjeva (access.log) zadnjih 7 dana
    traffic_spark   TEXT NULL,                             -- JSON: 24 satna bucketa za sparkline
    php_settings    JSON NULL,                             -- per-domena PHP override (memory_limit, upload_max_filesize, ...)
    stats_at        DATETIME NULL,                         -- zadnje osvježavanje statistika
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parent_vhost (parent_vhost_id),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vhost_aliases (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    domain          VARCHAR(255) NOT NULL UNIQUE,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subdomains (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    docroot         VARCHAR(512) NOT NULL,
    php_version     VARCHAR(8) NOT NULL DEFAULT '8.4',
    UNIQUE KEY uq_subdomain (vhost_id, name),
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dns_zones (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain          VARCHAR(255) NOT NULL UNIQUE,
    subscription_id INT UNSIGNED NOT NULL,
    serial          BIGINT UNSIGNED NOT NULL,
    dnssec_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    cf_account_id   INT UNSIGNED NULL,                     -- zadnji CF račun na koji je exportano
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dns_records (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    zone_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    type            VARCHAR(16) NOT NULL,                   -- A/AAAA/MX/TXT/CNAME/SRV/CAA/NS/PTR/TLSA
    content         TEXT NOT NULL,
    ttl             INT UNSIGNED NOT NULL DEFAULT 3600,
    prio            INT UNSIGNED NULL,
    FOREIGN KEY (zone_id) REFERENCES dns_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mail_domains (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain          VARCHAR(255) NOT NULL UNIQUE,
    subscription_id INT UNSIGNED NOT NULL,
    dkim_selector   VARCHAR(32) NOT NULL DEFAULT 'forge',
    dkim_txt        TEXT NULL,                              -- javni DKIM TXT zapis (privatni ključ drži rspamd)
    catchall_target VARCHAR(255) NULL,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mailboxes (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    mail_domain_id  INT UNSIGNED NOT NULL,
    local_part      VARCHAR(128) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    quota_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 1073741824,
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_mailbox (mail_domain_id, local_part),
    FOREIGN KEY (mail_domain_id) REFERENCES mail_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mail_aliases (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    mail_domain_id  INT UNSIGNED NOT NULL,
    source          VARCHAR(255) NOT NULL,
    destination     TEXT NOT NULL,
    FOREIGN KEY (mail_domain_id) REFERENCES mail_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE db_databases (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT UNSIGNED NOT NULL,
    vhost_id        INT UNSIGNED NULL,
    name            VARCHAR(64) NOT NULL UNIQUE,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE db_users (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT UNSIGNED NOT NULL,
    username        VARCHAR(64) NOT NULL UNIQUE,
    database_id     INT UNSIGNED NULL,
    remote_access   TINYINT(1) NOT NULL DEFAULT 0,
    password_enc    TEXT NULL,                              -- sodium secretbox (Crypto) — za phpMyAdmin auto-login
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    FOREIGN KEY (database_id) REFERENCES db_databases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ssl_certs (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NULL,                      -- NULL = panel/servisni cert
    hostname        VARCHAR(255) NOT NULL,
    type            ENUM('letsencrypt','zerossl','custom','selfsigned') NOT NULL,
    cert_path       VARCHAR(512) NOT NULL,
    key_path        VARCHAR(512) NOT NULL,
    expires_at      DATETIME NOT NULL,
    auto_renew      TINYINT(1) NOT NULL DEFAULT 1,
    status          ENUM('active','pending','error','expired') NOT NULL DEFAULT 'pending',
    last_error      TEXT NULL,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ftp_users (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    username        VARCHAR(64) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    home_path       VARCHAR(512) NOT NULL,
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cron_jobs (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    schedule        VARCHAR(64) NOT NULL,                   -- cron izraz
    command         TEXT NOT NULL,
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at     DATETIME NULL,
    last_output     MEDIUMTEXT NULL,
    last_exit_code  INT NULL,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE git_repos (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    repo_url        VARCHAR(512) NOT NULL,
    branch          VARCHAR(128) NOT NULL DEFAULT 'main',
    deploy_key      TEXT NULL,
    webhook_secret  VARCHAR(64) NOT NULL,
    last_commit     VARCHAR(64) NULL,
    last_deploy_at  DATETIME NULL,
    post_deploy     JSON NULL,                              -- akcije iz whiteliste
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE docker_containers (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT UNSIGNED NOT NULL,
    container_id    VARCHAR(64) NOT NULL,
    name            VARCHAR(128) NOT NULL,
    image           VARCHAR(255) NOT NULL,
    proxy_vhost_id  INT UNSIGNED NULL,
    proxy_port      INT UNSIGNED NULL,
    config          JSON NOT NULL,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    FOREIGN KEY (proxy_vhost_id) REFERENCES vhosts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backup_destinations (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    owner_user_id   INT UNSIGNED NOT NULL,
    name            VARCHAR(64) NOT NULL,
    type            ENUM('local','ftp','sftp','s3') NOT NULL,
    config          JSON NOT NULL,                          -- credentials enkriptirani
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backup_schedules (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT UNSIGNED NULL,                      -- NULL = full server
    destination_id  INT UNSIGNED NOT NULL,
    schedule        VARCHAR(64) NOT NULL,
    retention       JSON NOT NULL,                          -- {"daily":7,"weekly":4,"monthly":3}
    encrypt         TINYINT(1) NOT NULL DEFAULT 0,
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    FOREIGN KEY (destination_id) REFERENCES backup_destinations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backups (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT UNSIGNED NULL,
    destination_id  INT UNSIGNED NOT NULL,
    type            ENUM('full','incremental') NOT NULL,
    path            VARCHAR(512) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    manifest        JSON NULL,
    status          ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    FOREIGN KEY (destination_id) REFERENCES backup_destinations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tasks (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    op              VARCHAR(64) NOT NULL,
    params          JSON NOT NULL,
    user_id         INT UNSIGNED NULL,
    status          ENUM('pending','running','done','failed','cancelled') NOT NULL DEFAULT 'pending',
    progress        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    output          MEDIUMTEXT NULL,
    error           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at      DATETIME NULL,
    finished_at     DATETIME NULL,
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE components (
    id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name              VARCHAR(64) NOT NULL UNIQUE,          -- nginx, php8.4, mariadb...
    current_version   VARCHAR(64) NULL,
    available_version VARCHAR(64) NULL,
    repo_suite        VARCHAR(32) NULL,                     -- resolute / noble (fallback)
    policy_id         INT UNSIGNED NULL,
    status            ENUM('installed','not_installed','updating','frozen','error') NOT NULL DEFAULT 'not_installed',
    security_update   TINYINT(1) NOT NULL DEFAULT 0,        -- dostupni update dolazi iz security pocketa
    packages          JSON NOT NULL                         -- apt paketi komponente
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE update_policies (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    component_id    INT UNSIGNED NOT NULL,
    mode            ENUM('auto_all','auto_security_only','manual','frozen') NOT NULL DEFAULT 'manual',
    window_start    TIME NOT NULL DEFAULT '03:00:00',
    window_end      TIME NOT NULL DEFAULT '05:00:00',
    window_days     JSON NOT NULL,                          -- [7] = nedjelja
    delay_days      INT UNSIGNED NOT NULL DEFAULT 3,
    FOREIGN KEY (component_id) REFERENCES components(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE component_updates (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    component_id    INT UNSIGNED NOT NULL,
    from_version    VARCHAR(64) NOT NULL,
    to_version      VARCHAR(64) NOT NULL,
    status          ENUM('running','done','failed','rolled_back') NOT NULL,
    rollback_info   JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (component_id) REFERENCES components(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE monitoring_metrics (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    scope           VARCHAR(64) NOT NULL,                   -- server / vhost:<id> / service:<name>
    metric          VARCHAR(32) NOT NULL,                   -- cpu_pct, mem_bytes, disk_bytes, io_r, io_w, net_rx, net_tx
    resolution      ENUM('minute','hour','day') NOT NULL DEFAULT 'minute',
    value           DOUBLE NOT NULL,
    ts              DATETIME NOT NULL,
    INDEX idx_lookup (scope, metric, resolution, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL,
    actor           VARCHAR(64) NOT NULL,                   -- email / 'agent' / 'system'
    action          VARCHAR(128) NOT NULL,
    detail          JSON NULL,
    ip              VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- audit_log je append-only: panel DB user nema DELETE/UPDATE privilegiju na ovoj tablici (postavlja installer).

CREATE TABLE sessions (
    id              CHAR(64) PRIMARY KEY,                   -- hash session tokena
    user_id         INT UNSIGNED NOT NULL,
    ip              VARCHAR(45) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    twofa_passed    TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE api_tokens (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(64) NOT NULL,
    token_hash      CHAR(64) NOT NULL UNIQUE,               -- sha256
    scopes          JSON NOT NULL,                          -- ["vhosts:read","backup:write",...]
    expires_at      DATETIME NULL,
    last_used_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE webauthn_credentials (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(64) NOT NULL,
    credential_id   VARCHAR(1400) NOT NULL,                 -- base64url, sirovo do 1023 bajta
    public_key      TEXT NOT NULL,                          -- PEM (ES256/RS256) ili base64 raw (Ed25519)
    alg             SMALLINT NOT NULL,                      -- COSE: -7 ES256, -257 RS256, -8 Ed25519
    sign_count      INT UNSIGNED NOT NULL DEFAULT 0,        -- clone detekcija
    transports      JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    UNIQUE KEY uq_credential (credential_id(255)),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2FA recovery (backup) kodovi — anti-lockout kad korisnik izgubi TOTP uređaj.
-- Visoko-entropijski kodovi → sha256 hash dovoljan; jednokratni (used_at).
CREATE TABLE twofa_recovery_codes (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    code_hash       CHAR(64) NOT NULL,                      -- sha256 normaliziranog koda
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_code (user_id, code_hash),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
    `key`           VARCHAR(128) PRIMARY KEY,
    value           JSON NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    severity        ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    title           VARCHAR(255) NOT NULL,
    body            TEXT NULL,
    read_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloudflare_accounts (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(64) NOT NULL DEFAULT 'Cloudflare',  -- nadjenuto ime (više računa po useru)
    api_token       TEXT NOT NULL,                          -- enkriptiran (libsodium secretbox)
    status          ENUM('active','invalid') NOT NULL DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloudflare_zones (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    zone_id         VARCHAR(64) NOT NULL,
    dns_mode        ENUM('local','cloudflare') NOT NULL DEFAULT 'local',
    proxy_default   TINYINT(1) NOT NULL DEFAULT 1,
    ssl_mode        VARCHAR(32) NOT NULL DEFAULT 'full_strict',
    auto_purge_on_deploy TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES cloudflare_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE malware_scans (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NULL,                      -- NULL = full scan
    status          ENUM('running','done','failed') NOT NULL,
    files_scanned   INT UNSIGNED NOT NULL DEFAULT 0,
    threats_found   INT UNSIGNED NOT NULL DEFAULT 0,
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at     DATETIME NULL,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quarantine_items (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    path            VARCHAR(1024) NOT NULL,
    signature       VARCHAR(255) NOT NULL,
    detected_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_from_backup_id INT UNSIGNED NULL,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE,
    FOREIGN KEY (restored_from_backup_id) REFERENCES backups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE waf_rules (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    rule_id         VARCHAR(32) NOT NULL,
    action          ENUM('block','whitelist') NOT NULL,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE uptime_probes (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    vhost_id        INT UNSIGNED NOT NULL,
    type            ENUM('http','https','tcp') NOT NULL DEFAULT 'https',
    target          VARCHAR(255) NOT NULL,
    interval_s      INT UNSIGNED NOT NULL DEFAULT 60,
    last_status     ENUM('up','down','unknown') NOT NULL DEFAULT 'unknown',
    response_ms     INT UNSIGNED NULL,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE uptime_events (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    probe_id        INT UNSIGNED NOT NULL,
    event           ENUM('up','down') NOT NULL,
    detail          VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (probe_id) REFERENCES uptime_probes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dmarc_reports (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    mail_domain_id  INT UNSIGNED NOT NULL,
    org             VARCHAR(255) NOT NULL,
    date_range      VARCHAR(64) NOT NULL,
    parsed          JSON NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mail_domain_id) REFERENCES mail_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rbl_checks (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    ip              VARCHAR(45) NOT NULL,
    rbl             VARCHAR(128) NOT NULL,
    listed          TINYINT(1) NOT NULL,
    checked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE config_versions (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    component       VARCHAR(64) NOT NULL,
    path            VARCHAR(512) NOT NULL,
    version_hash    CHAR(40) NOT NULL,                      -- git commit hash internog repoa
    changed_by      VARCHAR(64) NOT NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delegated_access (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    grantor_user_id INT UNSIGNED NOT NULL,
    grantee_user_id INT UNSIGNED NOT NULL,
    vhost_id        INT UNSIGNED NOT NULL,
    permissions     JSON NOT NULL,                          -- ["files","git"] bez "mail","backup"...
    FOREIGN KEY (grantor_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (grantee_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE servers (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name            VARCHAR(64) NOT NULL UNIQUE,
    hostname        VARCHAR(255) NOT NULL,
    enroll_token    TEXT NULL,                              -- enkriptiran mTLS bootstrap token
    status          ENUM('online','offline','pending','error') NOT NULL DEFAULT 'pending',
    last_seen_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE staging_envs (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    source_vhost_id INT UNSIGNED NOT NULL,
    staging_vhost_id INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sync       DATETIME NULL,
    FOREIGN KEY (source_vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE,
    FOREIGN KEY (staging_vhost_id) REFERENCES vhosts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 33. releases — distribucija panel updatea (mothership): potpisani release-ovi koje nodovi povlače
CREATE TABLE releases (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    version         VARCHAR(32) NOT NULL,
    channel         ENUM('stable','beta') NOT NULL DEFAULT 'stable',
    package_url     VARCHAR(512) NOT NULL,                 -- URL tarballa (https)
    sha256          CHAR(64) NOT NULL,                     -- checksum paketa
    signature       TEXT NOT NULL,                         -- ed25519 detached potpis kanonskog manifesta (base64)
    notes           TEXT NULL,
    min_version     VARCHAR(32) NULL,                      -- najniža verzija s koje je dozvoljen skok
    published_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_version_channel (version, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 34. schema_migrations — praćenje primijenjenih DB migracija (self-update iz paketa)
CREATE TABLE schema_migrations (
    filename        VARCHAR(255) PRIMARY KEY,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 35. licenses — licencni model (master prodaje/izdaje licence za korištenje panela)
CREATE TABLE licenses (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    license_key     VARCHAR(64) NOT NULL UNIQUE,
    tier            VARCHAR(32) NOT NULL DEFAULT 'standard',
    status          ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    customer        VARCHAR(128) NULL,
    expires_at      DATETIME NULL,
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 36. license_activations — koji su paneli (fingerprint) aktivirali licencu
CREATE TABLE license_activations (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    license_id      INT UNSIGNED NOT NULL,
    fingerprint     VARCHAR(64) NOT NULL,
    version         VARCHAR(32) NULL,
    ip              VARCHAR(45) NULL,
    last_seen       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_license_fp (license_id, fingerprint),
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 37. trials — master bilježi prvi kontakt svakog nodea (fingerprint) za 7-dnevni probni period
CREATE TABLE trials (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    fingerprint     VARCHAR(64) NOT NULL UNIQUE,
    version         VARCHAR(32) NULL,
    ip              VARCHAR(45) NULL,
    first_seen      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Početni podaci
INSERT INTO roles (name, permissions) VALUES
    ('admin',    '{"*": true}'),
    ('reseller', '{"plans": true, "clients": true, "white_label": true}'),
    ('client',   '{"own_resources": true}');
