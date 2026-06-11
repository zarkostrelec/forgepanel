#!/usr/bin/env bash
#
# ForgePanel installer — Ubuntu Server 26.04 LTS (Resolute Raccoon), goli minimal server.
#   curl -fsSL https://get.forgepanel.io | bash
#
# Checkpoint sustav: prekinuta instalacija se nastavlja, ne kreće ispočetka.
# Cijeli tijek se loggira u /var/log/forgepanel-install.log.

set -euo pipefail

FP_VERSION="0.1.0"
FP_HOME="/opt/forgepanel"
FP_ETC="/etc/forgepanel"
FP_LOG="/var/log/forgepanel-install.log"
FP_STATE="/var/lib/forgepanel/install-state"
FP_CREDS="/root/.forgepanel-credentials"
UBUNTU_SUITE="resolute"
FALLBACK_SUITE="noble"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ---------------------------------------------------------------- flagovi
UNATTENDED=0
ADMIN_EMAIL=""
HOSTNAME_FLAG=""
DB_ENGINE="mariadb"
COMPONENTS="web"
DO_UNINSTALL=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --unattended)  UNATTENDED=1 ;;
        --email)       ADMIN_EMAIL="$2"; shift ;;
        --email=*)     ADMIN_EMAIL="${1#*=}" ;;
        --hostname)    HOSTNAME_FLAG="$2"; shift ;;
        --hostname=*)  HOSTNAME_FLAG="${1#*=}" ;;
        --db)          DB_ENGINE="$2"; shift ;;
        --db=*)        DB_ENGINE="${1#*=}" ;;
        --components)  COMPONENTS="$2"; shift ;;
        --components=*) COMPONENTS="${1#*=}" ;;
        --uninstall)   DO_UNINSTALL=1 ;;
        *) echo "Nepoznat flag: $1" >&2; exit 1 ;;
    esac
    shift
done

mkdir -p "$(dirname "$FP_STATE")"
touch "$FP_LOG"; chmod 600 "$FP_LOG"
exec > >(tee -a "$FP_LOG") 2>&1

log()  { echo "[$(date '+%d.%m.%Y. %H:%M:%S')] $*"; }
die()  { log "GREŠKA: $*"; exit 1; }

checkpoint_done() { grep -qxF "$1" "$FP_STATE" 2>/dev/null; }
checkpoint_set()  { echo "$1" >> "$FP_STATE"; }
step() {
    local name="$1"; shift
    if checkpoint_done "$name"; then
        log "✓ $name (već odrađeno, preskačem)"
        return 0
    fi
    log "→ $name"
    "$@"
    checkpoint_set "$name"
}

# ---------------------------------------------------------------- uninstall
uninstall() {
    log "Deinstalacija ForgePanela…"
    if [[ $UNATTENDED -eq 0 ]]; then
        read -rp "Ovo briše panel (hostani sadržaj u /var/www/vhosts OSTAJE). Nastaviti? [da/NE] " answer
        [[ "$answer" == "da" ]] || die "Prekinuto."
    fi
    systemctl disable --now forge-agentd 2>/dev/null || true
    systemctl disable --now forgepanel-web 2>/dev/null || true
    rm -f /etc/systemd/system/forge-agentd.service /etc/nginx/forgepanel/panel.conf
    rm -rf "$FP_HOME" "$FP_ETC" "$FP_STATE"
    systemctl daemon-reload
    log "ForgePanel uklonjen. Baza 'forgepanel' i /var/www/vhosts namjerno NISU obrisani."
    exit 0
}
[[ $DO_UNINSTALL -eq 1 ]] && uninstall

# ---------------------------------------------------------------- 1. provjere
preflight() {
    [[ $EUID -eq 0 ]] || die "Installer mora biti pokrenut kao root."

    . /etc/os-release
    [[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "26.04" ]] \
        || die "Podržan je isključivo Ubuntu Server 26.04 LTS (nađeno: ${ID:-?} ${VERSION_ID:-?})."

    # Čist sustav: postojeći panel/web/db stack → abort s objašnjenjem
    local conflict
    for conflict in apache2 nginx mysqld mariadbd; do
        if pgrep -x "$conflict" >/dev/null 2>&1 && ! checkpoint_done "bootstrap_repos"; then
            die "Pronađen pokrenut '$conflict'. ForgePanel zahtijeva potpuno gol server."
        fi
    done
    for conflict in /usr/local/cpanel /opt/psa /usr/local/directadmin /usr/local/hestia; do
        [[ -e "$conflict" ]] && die "Pronađen postojeći panel: $conflict. Instalacija prekinuta."
    done

    # Resursi: min 2 GB RAM / 20 GB disk
    local mem_kb disk_kb
    mem_kb=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
    (( mem_kb >= 1900000 )) || die "Premalo RAM-a ($(( mem_kb / 1024 )) MB) — minimum je 2 GB."
    disk_kb=$(df -k / | awk 'NR==2 {print $4}')
    (( disk_kb >= 19000000 )) || die "Premalo slobodnog diska — minimum je 20 GB."

    # FQDN hostname
    if [[ -n "$HOSTNAME_FLAG" ]]; then
        hostnamectl set-hostname "$HOSTNAME_FLAG"
    fi
    local fqdn; fqdn=$(hostname -f 2>/dev/null || hostname)
    [[ "$fqdn" == *.* ]] || die "Hostname '$fqdn' nije FQDN — postavi ga ili koristi --hostname panel.example.com."

    # DNS resolver
    getent hosts archive.ubuntu.com >/dev/null || die "DNS resolver ne radi."

    # Točno vrijeme
    timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -q yes \
        || log "UPOZORENJE: vrijeme nije NTP-sinkronizirano, uključujem systemd-timesyncd." \
        && timedatectl set-ntp true || true

    if [[ $UNATTENDED -eq 1 && -z "$ADMIN_EMAIL" ]]; then
        die "--unattended zahtijeva --email."
    fi
    if [[ -z "$ADMIN_EMAIL" ]]; then
        read -rp "Admin e-mail adresa: " ADMIN_EMAIL
    fi
    [[ "$ADMIN_EMAIL" == *@*.* ]] || die "Neispravan e-mail: $ADMIN_EMAIL"
    [[ "$DB_ENGINE" == "mariadb" || "$DB_ENGINE" == "mysql" ]] || die "--db mora biti mariadb ili mysql."
}

# ---------------------------------------------------------------- 2. repoi (deb822)
suite_for() {
    # Fallback suite logika: ako repo nema resolute, koristi noble (binarno kompatibilan)
    local base_url="$1"
    if curl -fsI --max-time 15 "${base_url}/dists/${UBUNTU_SUITE}/Release" >/dev/null 2>&1; then
        echo "$UBUNTU_SUITE"
    else
        echo "$FALLBACK_SUITE"
    fi
}

add_repo() {
    # add_repo <ime> <uri> <key_url> <components> [suite_override]
    local name="$1" uri="$2" key_url="$3" components="$4" suite="${5:-}"
    local key_path="/etc/apt/keyrings/${name}.gpg"

    if [[ ! -f "$key_path" ]]; then
        curl -fsSL "$key_url" | gpg --dearmor -o "$key_path"
        chmod 644 "$key_path"
    fi
    [[ -n "$suite" ]] || suite=$(suite_for "$uri")
    log "  repo $name → suite: $suite"

    cat > "/etc/apt/sources.list.d/forgepanel-${name}.sources" <<EOF
Types: deb
URIs: ${uri}
Suites: ${suite}
Components: ${components}
Signed-By: ${key_path}
EOF
    echo "$name=$suite" >> "${FP_STATE}.suites"
}

bootstrap_repos() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -q
    apt-get install -y -q --no-install-recommends \
        curl gnupg ca-certificates lsb-release software-properties-common
    install -d -m 755 /etc/apt/keyrings

    add-apt-repository -y universe

    add_repo "ondrej-php" \
        "https://ppa.launchpadcontent.net/ondrej/php/ubuntu" \
        "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x71daeaab4ad4cab6" \
        "main"
    add_repo "nginx" \
        "https://nginx.org/packages/mainline/ubuntu" \
        "https://nginx.org/keys/nginx_signing.key" \
        "nginx"
    if [[ "$DB_ENGINE" == "mariadb" ]]; then
        add_repo "mariadb" \
            "https://deb.mariadb.org/11.8/ubuntu" \
            "https://mariadb.org/mariadb_release_signing_key.pgp" \
            "main"
    fi

    apt-get update -q
}

# ---------------------------------------------------------------- 4. panel stack
install_database() {
    export DEBIAN_FRONTEND=noninteractive
    if [[ "$DB_ENGINE" == "mariadb" ]]; then
        apt-get install -y -q mariadb-server mariadb-client
        systemctl enable --now mariadb
    else
        apt-get install -y -q mysql-server-8.4 mysql-client-8.4 || apt-get install -y -q mysql-server mysql-client
        systemctl enable --now mysql
    fi
}

setup_panel_db() {
    # Zaseban DB 'forgepanel' s vlastitim userom — NIKAD dijeliti s hostanim bazama.
    # Agent ima puni grant (CREATE DATABASE za hostane baze); web sloj koristi istog
    # usera u MVP-u, razdvajanje privilegija (append-only audit_log na DB razini)
    # dolazi s update orkestratorom u Fazi 3.
    local db_pass; db_pass=$(openssl rand -hex 24)
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS forgepanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'forgepanel'@'localhost' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON *.* TO 'forgepanel'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
    mysql forgepanel < "$FP_HOME/database/schema.sql" || true

    install -d -m 750 "$FP_ETC"
    cat > "$FP_ETC/agent.ini" <<EOF
db_dsn = "mysql:host=localhost;dbname=forgepanel;charset=utf8mb4"
db_user = "forgepanel"
db_pass = "${db_pass}"
socket_group = "fpanel"
EOF
    chmod 600 "$FP_ETC/agent.ini"

    cat > "$FP_ETC/web.ini" <<EOF
db_dsn = "mysql:host=localhost;dbname=forgepanel;charset=utf8mb4"
db_user = "forgepanel"
db_pass = "${db_pass}"
acme_email = "${ADMIN_EMAIL}"
EOF
    chown root:fpanel "$FP_ETC/web.ini" 2>/dev/null || true
    chmod 640 "$FP_ETC/web.ini"
}

install_panel_stack() {
    export DEBIAN_FRONTEND=noninteractive
    # Panelov vlastiti PHP 8.4 (pinnan) — update hostanih PHP-ova ne dira panel
    apt-get install -y -q php8.4-cli php8.4-fpm php8.4-mysql php8.4-curl php8.4-mbstring \
        php8.4-xml php8.4-zip php8.4-intl php8.4-posix 2>/dev/null \
        || apt-get install -y -q php8.4-cli php8.4-fpm php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-intl
    apt-get install -y -q nginx

    # Neprivilegirani panel user
    id fpanel &>/dev/null || useradd --system --shell /usr/sbin/nologin --home-dir "$FP_HOME" fpanel

    # Kod panela
    install -d "$FP_HOME"
    cp -a "$REPO_ROOT/agent" "$REPO_ROOT/web" "$REPO_ROOT/database" "$FP_HOME/"
    chown -R root:root "$FP_HOME"
    chmod +x "$FP_HOME/agent/bin/forge-agentd"

    # PHP-FPM pool panela (zaseban, user fpanel)
    cat > /etc/php/8.4/fpm/pool.d/forgepanel.conf <<'EOF'
[forgepanel]
user = fpanel
group = fpanel
listen = /run/php/fpm-forgepanel.sock
listen.owner = nginx
listen.group = nginx
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 60s
env[FORGEPANEL_CONFIG] = /etc/forgepanel/web.ini
php_admin_value[open_basedir] = /opt/forgepanel/web:/etc/forgepanel/web.ini:/tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
EOF
    php-fpm8.4 -t
    systemctl enable --now php8.4-fpm
    systemctl reload php8.4-fpm

    # Panel nginx na :8443 (self-signed do AutoSSL-a)
    install -d -m 700 "$FP_ETC/ssl/panel"
    if [[ ! -f "$FP_ETC/ssl/panel/fullchain.pem" ]]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
            -keyout "$FP_ETC/ssl/panel/privkey.pem" \
            -out "$FP_ETC/ssl/panel/fullchain.pem" \
            -subj "/CN=$(hostname -f)"
    fi
    install -d /etc/nginx/forgepanel/vhosts /etc/nginx/forgepanel/snippets /var/www/forgepanel-acme /var/www/vhosts

    cat > /etc/nginx/forgepanel/snippets/security.conf <<'EOF'
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
EOF

    cat > /etc/nginx/conf.d/forgepanel-panel.conf <<EOF
# ForgePanel — panel UI/API na :8443, izoliran od hostanih stranica
server {
    listen 8443 ssl;
    listen [::]:8443 ssl;
    http2 on;
    server_name _;

    ssl_certificate     $FP_ETC/ssl/panel/fullchain.pem;
    ssl_certificate_key $FP_ETC/ssl/panel/privkey.pem;

    root $FP_HOME/web/public;
    index index.php;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }
    location ~ ^/index\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/fpm-forgepanel.sock;
        fastcgi_buffering off;     # SSE
        fastcgi_read_timeout 3600; # SSE stream
    }
    location ~ \.php$ { return 404; }
}
# Include hostanih vhostova
include /etc/nginx/forgepanel/vhosts/*.conf;
EOF
    nginx -t
    systemctl enable --now nginx
    systemctl reload nginx
}

install_agent() {
    cp "$FP_HOME/agent/systemd/forge-agentd.service" /etc/systemd/system/forge-agentd.service
    systemctl daemon-reload
    systemctl enable --now forge-agentd
}

create_admin() {
    local admin_pass admin_hash
    admin_pass=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
    admin_hash=$(php8.4 -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID, ["memory_cost" => 65536, "time_cost" => 4, "threads" => 2]);' "$admin_pass")

    mysql forgepanel <<SQL
INSERT INTO users (email, password_hash, role_id, lang)
SELECT '${ADMIN_EMAIL}', '${admin_hash}', id, 'hr' FROM roles WHERE name = 'admin'
ON DUPLICATE KEY UPDATE email = email;
SQL

    cat > "$FP_CREDS" <<EOF
ForgePanel ${FP_VERSION} — pristupni podaci
URL:      https://$(hostname -f):8443
E-mail:   ${ADMIN_EMAIL}
Lozinka:  ${admin_pass}
Kreirano: $(date '+%d.%m.%Y. %H:%M')
EOF
    chmod 600 "$FP_CREDS"
    ADMIN_PASS_OUT="$admin_pass"
}

# ---------------------------------------------------------------- 6. hardening
hardening() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y -q ufw fail2ban

    ufw allow 22/tcp
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw allow 8443/tcp
    ufw --force enable

    cat > /etc/fail2ban/jail.d/forgepanel.conf <<'EOF'
[sshd]
enabled = true
EOF
    systemctl enable --now fail2ban

    # sysctl preset (umjereno, bez egzotike)
    cat > /etc/sysctl.d/90-forgepanel.conf <<'EOF'
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
kernel.kptr_restrict = 1
EOF
    sysctl --system >/dev/null

    # SSH: PasswordAuthentication NE diramo — korisnik se ne smije zaključati.
}

register_components() {
    while IFS='=' read -r name suite; do
        mysql forgepanel <<SQL || true
INSERT INTO components (name, repo_suite, status, packages)
VALUES ('${name}', '${suite}', 'installed', '[]')
ON DUPLICATE KEY UPDATE repo_suite = '${suite}';
SQL
    done < "${FP_STATE}.suites" 2>/dev/null || true
}

# ---------------------------------------------------------------- tijek
main() {
    log "ForgePanel ${FP_VERSION} installer — početak"
    step "preflight"        preflight
    step "bootstrap_repos"  bootstrap_repos
    step "install_database" install_database
    step "install_panel_stack" install_panel_stack
    step "setup_panel_db"   setup_panel_db
    step "install_agent"    install_agent
    step "create_admin"     create_admin
    step "hardening"        hardening
    step "register_components" register_components

    log ""
    log "═══════════════════════════════════════════════════"
    log " ForgePanel instaliran!"
    log " URL:     https://$(hostname -f):8443"
    log " E-mail:  ${ADMIN_EMAIL}"
    if [[ -n "${ADMIN_PASS_OUT:-}" ]]; then
        log " Lozinka: ${ADMIN_PASS_OUT}"
    else
        log " Lozinka: vidi ${FP_CREDS}"
    fi
    log " Podaci spremljeni u ${FP_CREDS} (chmod 600)"
    log "═══════════════════════════════════════════════════"
}

main
