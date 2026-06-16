#!/usr/bin/env bash
#
# ForgePanel installer — Ubuntu Server 26.04 LTS (Resolute Raccoon), goli minimal server.
#   curl -fsSL https://get.forgepanel.io | bash
#   ili iz klona repoa: bash installer/install.sh [--unattended --email x@y.hr ...]
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
FP_REPO_URL="${FP_REPO_URL:-https://github.com/zarkostrelec/forgepanel.git}"
UBUNTU_SUITE="resolute"
FALLBACK_SUITE="noble"

# Mothership (master) za licence + ažuriranja. Public installeri pokazuju ovamo →
# auto-trial (7 dana) + potpisana ažuriranja. Prazno = self-host (master, bez locka).
# UPDATE_PUBKEY je javni ed25519 ključ mastera (iz: Server → Distribucija → Generiraj ključ).
UPDATE_SERVER="${UPDATE_SERVER:-https://elite.hostforge.net}"
UPDATE_PUBKEY="${UPDATE_PUBKEY:-}"

# ---------------------------------------------------------------- flagovi
UNATTENDED=0
ADMIN_EMAIL=""
HOSTNAME_FLAG=""
DB_ENGINE="mariadb"
COMPONENTS="web"
DO_UNINSTALL=0
PANEL_PHP=""   # odabire se u install_panel_stack (8.4 iz ondreja ili distro verzija)

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

# MariaDB 11.x iz mariadb.org više ne garantira 'mysql' symlink — koristi pravi klijent
sql() {
    if command -v mariadb >/dev/null 2>&1; then mariadb "$@"; else mysql "$@"; fi
}

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

# Odgovori (email, db engine) se čuvaju — nastavak prekinute instalacije ih ne smije izgubiti
save_env() {
    {
        echo "ADMIN_EMAIL=$(printf '%q' "$ADMIN_EMAIL")"
        echo "DB_ENGINE=$(printf '%q' "$DB_ENGINE")"
        echo "COMPONENTS=$(printf '%q' "$COMPONENTS")"
        echo "PANEL_FQDN=$(printf '%q' "${PANEL_FQDN:-}")"
        echo "PANEL_PHP=$(printf '%q' "${PANEL_PHP:-}")"
    } > "${FP_STATE}.env"
    chmod 600 "${FP_STATE}.env"
}
# shellcheck disable=SC1090
[[ -f "${FP_STATE}.env" ]] && source "${FP_STATE}.env"

# ---------------------------------------------------------------- uninstall
uninstall() {
    log "Deinstalacija ForgePanela…"
    if [[ $UNATTENDED -eq 0 ]]; then
        read -rp "Ovo briše panel (hostani sadržaj u /var/www/vhosts OSTAJE). Nastaviti? [da/NE] " answer
        [[ "$answer" == "da" ]] || die "Prekinuto."
    fi
    systemctl disable --now forge-agentd 2>/dev/null || true
    rm -f /etc/systemd/system/forge-agentd.service /etc/nginx/conf.d/forgepanel-panel.conf
    rm -rf "$FP_HOME" "$FP_ETC" "$FP_STATE" "${FP_STATE}.env" "${FP_STATE}.suites"
    systemctl daemon-reload 2>/dev/null || true
    log "ForgePanel uklonjen. Baza 'forgepanel' i /var/www/vhosts namjerno NISU obrisani."
    exit 0
}
[[ $DO_UNINSTALL -eq 1 ]] && uninstall

# ---------------------------------------------------------------- 1. provjere (uvijek se izvršavaju)
preflight() {
    log "→ preflight provjere"
    [[ $EUID -eq 0 ]] || die "Installer mora biti pokrenut kao root."

    . /etc/os-release
    if [[ "${FORGEPANEL_SKIP_OS_CHECK:-0}" != "1" ]]; then
        [[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "26.04" ]] \
            || die "Podržan je isključivo Ubuntu Server 26.04 LTS (nađeno: ${ID:-?} ${VERSION_ID:-?})."
    fi

    # Čist sustav: postojeći panel/web/db stack → abort s objašnjenjem.
    # Provjera se preskače čim je prošao bootstrap (repair/nastavak instalacije).
    local conflict
    if ! checkpoint_done "bootstrap_repos"; then
        for conflict in apache2 nginx mysqld mariadbd; do
            pgrep -x "$conflict" >/dev/null 2>&1 \
                && die "Pronađen pokrenut '$conflict'. ForgePanel zahtijeva potpuno gol server."
        done
    fi
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
        hostnamectl set-hostname "$HOSTNAME_FLAG" 2>/dev/null || hostname "$HOSTNAME_FLAG" 2>/dev/null || true
    fi
    PANEL_FQDN="${HOSTNAME_FLAG:-$(hostname -f 2>/dev/null || hostname)}"
    [[ "$PANEL_FQDN" == *.* ]] || die "Hostname '$PANEL_FQDN' nije FQDN — postavi ga ili koristi --hostname panel.example.com."
    # hostname -f mora raditi (svježi cloud image često nema /etc/hosts zapis)
    if ! hostname -f >/dev/null 2>&1 || [[ "$(hostname -f 2>/dev/null)" != "$PANEL_FQDN" ]]; then
        grep -q "127.0.1.1.*${PANEL_FQDN}" /etc/hosts \
            || echo "127.0.1.1 ${PANEL_FQDN} ${PANEL_FQDN%%.*}" >> /etc/hosts
    fi

    # DNS resolver
    getent hosts archive.ubuntu.com >/dev/null || die "DNS resolver ne radi."

    # Točno vrijeme
    if ! timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -q yes; then
        log "UPOZORENJE: vrijeme nije NTP-sinkronizirano — uključujem NTP."
        timedatectl set-ntp true 2>/dev/null || true
    fi

    if [[ -z "$ADMIN_EMAIL" ]]; then
        [[ $UNATTENDED -eq 1 ]] && die "--unattended zahtijeva --email."
        read -rp "Admin e-mail adresa: " ADMIN_EMAIL
    fi
    [[ "$ADMIN_EMAIL" == *@*.* ]] || die "Neispravan e-mail: $ADMIN_EMAIL"
    [[ "$DB_ENGINE" == "mariadb" || "$DB_ENGINE" == "mysql" ]] || die "--db mora biti mariadb ili mysql."
    save_env
}

# ---------------------------------------------------------------- izvor koda
# Radi i iz klona repoa i kroz `curl | bash` (tada se repo klonira).
resolve_repo_root() {
    local script_dir=""
    if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]:-}" ]]; then
        script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    fi
    if [[ -n "$script_dir" && -d "$script_dir/agent" && -d "$script_dir/web" ]]; then
        REPO_ROOT="$script_dir"
        return
    fi
    log "Lokalni repo nije pronađen — kloniram $FP_REPO_URL"
    export DEBIAN_FRONTEND=noninteractive
    command -v git >/dev/null 2>&1 || apt-get install -y -q git
    rm -rf /opt/forgepanel-src
    git clone --depth 1 "$FP_REPO_URL" /opt/forgepanel-src
    REPO_ROOT="/opt/forgepanel-src"
}

# ---------------------------------------------------------------- 2. repoi (deb822)
suite_for() {
    # Fallback suite logika: ako repo nema resolute, koristi noble (binarno kompatibilan)
    local base_url="$1"
    if [[ -n "${FORGEPANEL_FORCE_SUITE:-}" ]]; then
        echo "$FORGEPANEL_FORCE_SUITE"
        return
    fi
    if curl -fsI --max-time 15 "${base_url}/dists/${UBUNTU_SUITE}/Release" >/dev/null 2>&1; then
        echo "$UBUNTU_SUITE"
    elif curl -fsI --max-time 15 "${base_url}/dists/${FALLBACK_SUITE}/Release" >/dev/null 2>&1; then
        echo "$FALLBACK_SUITE"
    else
        echo ""
    fi
}

pick_mirror() {
    # Geo-redirectori (deb.mariadb.org) preusmjeravaju SVAKI zahtjev iznova i znaju
    # poslati apt na mrtav mirror usred downloada — zato se bira prvi KONKRETAN
    # mirror koji ima resolute (ili noble) suite, redirector je tek zadnja opcija.
    local uri
    for uri in "$@"; do
        if curl -fsI --max-time 15 "${uri}/dists/${UBUNTU_SUITE}/Release" >/dev/null 2>&1 \
        || curl -fsI --max-time 15 "${uri}/dists/${FALLBACK_SUITE}/Release" >/dev/null 2>&1; then
            echo "$uri"
            return 0
        fi
        # na stderr — stdout ove funkcije se hvata u command substitution
        log "  mirror nedostupan, preskačem: $uri" >&2
    done
    return 1
}

add_repo() {
    # add_repo <ime> <uri> <key_url> <components>
    local name="$1" uri="$2" key_url="$3" components="$4"
    local key_path="/etc/apt/keyrings/${name}.gpg"

    if [[ ! -f "$key_path" ]]; then
        curl -fsSL "$key_url" | gpg --dearmor -o "$key_path"
        chmod 644 "$key_path"
    fi
    local suite; suite=$(suite_for "$uri")
    [[ -n "$suite" ]] || die "Repo $name nije dostupan ni za $UBUNTU_SUITE ni za $FALLBACK_SUITE ($uri)."
    log "  repo $name → suite: $suite"

    cat > "/etc/apt/sources.list.d/forgepanel-${name}.sources" <<EOF
Types: deb
URIs: ${uri}
Suites: ${suite}
Components: ${components}
Signed-By: ${key_path}
EOF
    grep -qxF "$name=$suite" "${FP_STATE}.suites" 2>/dev/null || echo "$name=$suite" >> "${FP_STATE}.suites"
}

bootstrap_repos() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -q
    apt-get install -y -q --no-install-recommends \
        curl gnupg ca-certificates lsb-release software-properties-common
    install -d -m 755 /etc/apt/keyrings

    # universe komponenta — deb822 nativno (add-apt-repository zna biti slomljen na minimal image-ima)
    if [[ -f /etc/apt/sources.list.d/ubuntu.sources ]]; then
        grep -q '^Components:.*universe' /etc/apt/sources.list.d/ubuntu.sources \
            || sed -i 's/^Components:.*/Components: main restricted universe multiverse/' /etc/apt/sources.list.d/ubuntu.sources
    else
        add-apt-repository -y universe
    fi

    # ondrej PPA: noble fallback NE radi za PHP/Apache pakete — buildovi ovise o
    # noble libovima (libicu74, libzip4t64, stari libxml2) kojih u 26.04 nema, a
    # apt bi ih preferirao i blokirao i distro PHP. Bez pravog suite-a PPA se NE
    # dodaje: panel ide na distro PHP, suite watcher (modul updates) dodaje PPA
    # kad ondrej objavi resolute.
    if [[ "$(suite_for 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu')" == "$UBUNTU_SUITE" ]]; then
        add_repo "ondrej-php" \
            "https://ppa.launchpadcontent.net/ondrej/php/ubuntu" \
            "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x71daeaab4ad4cab6" \
            "main"
        add_repo "ondrej-apache2" \
            "https://ppa.launchpadcontent.net/ondrej/apache2/ubuntu" \
            "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x71daeaab4ad4cab6" \
            "main"
    else
        log "  ondrej PPA još nema '$UBUNTU_SUITE' suite — preskačem (panel koristi distro PHP)"
    fi
    add_repo "nginx" \
        "https://nginx.org/packages/mainline/ubuntu" \
        "https://nginx.org/keys/nginx_signing.key" \
        "nginx"
    if [[ "$DB_ENGINE" == "mariadb" ]]; then
        local mariadb_uri
        mariadb_uri=$(pick_mirror \
            "https://mirror.netcologne.de/mariadb/repo/11.8/ubuntu" \
            "https://ftp.nluug.nl/db/mariadb/repo/11.8/ubuntu" \
            "https://mirror.kumi.systems/mariadb/repo/11.8/ubuntu" \
            "https://deb.mariadb.org/11.8/ubuntu") \
            || die "Nijedan MariaDB mirror nije dostupan — provjeri izlaz prema internetu."
        add_repo "mariadb" \
            "$mariadb_uri" \
            "https://mariadb.org/mariadb_release_signing_key.pgp" \
            "main"
    fi
    if [[ ",$COMPONENTS," == *",docker,"* ]]; then
        add_repo "docker" \
            "https://download.docker.com/linux/ubuntu" \
            "https://download.docker.com/linux/ubuntu/gpg" \
            "stable"
    fi

    apt-get update -q
}

# Uvijek se izvršava (i kod repair/nastavka): starije verzije installera su ondrej
# dodale s noble fallbackom — ti paketi su neinstalabilni na 26.04 i blokiraju apt
# resolver (apt preferira višu sury verziju pa i distro PHP postane neinstalabilan).
repo_sanity() {
    local want="${FORGEPANEL_FORCE_SUITE:-$UBUNTU_SUITE}" f changed=0
    for f in /etc/apt/sources.list.d/forgepanel-ondrej-*.sources; do
        [[ -f "$f" ]] || continue
        if ! grep -qxF "Suites: ${want}" "$f"; then
            log "  uklanjam $f (suite nije '${want}' — neinstalabilni paketi)"
            rm -f "$f"; changed=1
        fi
    done
    [[ $changed -eq 1 ]] && apt-get update -q
    return 0
}

# ---------------------------------------------------------------- 4. panel stack
install_database() {
    export DEBIAN_FRONTEND=noninteractive
    if [[ "$DB_ENGINE" == "mariadb" ]]; then
        apt-get install -y -q mariadb-server mariadb-client
        systemctl enable --now mariadb 2>/dev/null || systemctl start mariadb || true
    else
        apt-get install -y -q mysql-server mysql-client
        systemctl enable --now mysql 2>/dev/null || true
    fi
    # Pričekaj da server primi konekcije
    local i
    for i in $(seq 1 30); do
        sql -e "SELECT 1" >/dev/null 2>&1 && return 0
        sleep 1
    done
    die "Baza se nije podigla nakon instalacije."
}

install_panel_code() {
    install -d "$FP_HOME"
    cp -a "$REPO_ROOT/agent" "$REPO_ROOT/web" "$REPO_ROOT/database" "$FP_HOME/"
    chown -R root:root "$FP_HOME"
    chmod +x "$FP_HOME/agent/bin/forge-agentd"
}

setup_panel_db() {
    # Zaseban DB 'forgepanel' s vlastitim userom — NIKAD dijeliti s hostanim bazama.
    # Agent treba globalni grant (kreira hostane baze i usere); razdvajanje
    # privilegija web sloja (append-only audit_log na DB razini) dolazi u Fazi 3.
    local db_pass; db_pass=$(openssl rand -hex 24)
    sql <<SQL
CREATE DATABASE IF NOT EXISTS forgepanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'forgepanel'@'localhost' IDENTIFIED BY '${db_pass}';
ALTER USER 'forgepanel'@'localhost' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON *.* TO 'forgepanel'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
    # Shema se učitava samo jednom (idempotentnost)
    if ! sql forgepanel -e "SELECT 1 FROM roles LIMIT 1" >/dev/null 2>&1; then
        sql forgepanel < "$FP_HOME/database/schema.sql"
    fi

    # fpanel mora moći ući u direktorij (web.ini), ali agent.ini ostaje samo za root
    install -d -m 750 -g fpanel "$FP_ETC"
    cat > "$FP_ETC/agent.ini" <<EOF
db_dsn = "mysql:host=localhost;dbname=forgepanel;charset=utf8mb4"
db_user = "forgepanel"
db_pass = "${db_pass}"
socket_group = "fpanel"
acme_email = "${ADMIN_EMAIL}"
panel_fqdn = "${PANEL_FQDN}"
EOF
    chmod 600 "$FP_ETC/agent.ini"

    local app_secret; app_secret=$(openssl rand -hex 32)
    cat > "$FP_ETC/web.ini" <<EOF
db_dsn = "mysql:host=localhost;dbname=forgepanel;charset=utf8mb4"
db_user = "forgepanel"
db_pass = "${db_pass}"
acme_email = "${ADMIN_EMAIL}"
panel_fqdn = "${PANEL_FQDN}"
app_secret = "${app_secret}"
EOF
    chown root:fpanel "$FP_ETC/web.ini"
    chmod 640 "$FP_ETC/web.ini"

    # Javna IPv4 servera — koristi se za auto-generiranje DNS zona
    local server_ip
    server_ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -1)
    if [[ -n "$server_ip" ]]; then
        sql forgepanel <<SQL
INSERT INTO settings (\`key\`, value) VALUES ('server_ipv4', '"${server_ip}"')
ON DUPLICATE KEY UPDATE value = '"${server_ip}"';
SQL
    fi
    # Spoji na mothership (licence + ažuriranja) ako su zadani — public install dobiva auto-trial
    if [[ -n "$UPDATE_SERVER" && -n "$UPDATE_PUBKEY" ]]; then
        sql forgepanel <<SQL
INSERT INTO settings (\`key\`, value) VALUES ('update_server', '"${UPDATE_SERVER}"')
ON DUPLICATE KEY UPDATE value = '"${UPDATE_SERVER}"';
INSERT INTO settings (\`key\`, value) VALUES ('update_pubkey', '"${UPDATE_PUBKEY}"')
ON DUPLICATE KEY UPDATE value = '"${UPDATE_PUBKEY}"';
SQL
    fi
}

install_panel_stack() {
    export DEBIAN_FRONTEND=noninteractive
    # Panelov vlastiti PHP — update hostanih PHP-ova ne dira panel.
    # Preferira se 8.4 (ondrej PPA); ako ondrej još nema suite za 26.04,
    # distro PHP (resolute nosi 8.5). apt -s simulacija = provjera instalabilnosti.
    if [[ -z "$PANEL_PHP" ]]; then
        local v
        for v in 8.4 8.5; do
            if apt-get -s install "php${v}-cli" "php${v}-fpm" >/dev/null 2>&1; then
                PANEL_PHP="$v"; break
            fi
        done
        [[ -n "$PANEL_PHP" ]] || die "Ni PHP 8.4 ni 8.5 nisu instalabilni — provjeri apt repoe ($FP_LOG)."
        save_env
    fi
    log "  panel PHP verzija: ${PANEL_PHP}"
    apt-get install -y -q "php${PANEL_PHP}-cli" "php${PANEL_PHP}-fpm" "php${PANEL_PHP}-mysql" \
        "php${PANEL_PHP}-curl" "php${PANEL_PHP}-mbstring" "php${PANEL_PHP}-xml" \
        "php${PANEL_PHP}-zip" "php${PANEL_PHP}-intl"
    # Stabilan interpreter path — agent servis i skripte ne ovise o verziji u imenu
    ln -sf "/usr/bin/php${PANEL_PHP}" /usr/local/bin/forgepanel-php
    apt-get install -y -q nginx
    # Git deploy modul treba git + ssh klijent
    apt-get install -y -q git openssh-client

    # Neprivilegirani panel user
    id fpanel &>/dev/null || useradd --system --shell /usr/sbin/nologin --home-dir "$FP_HOME" fpanel

    # PHP-FPM pool panela (zaseban, user fpanel)
    cat > "/etc/php/${PANEL_PHP}/fpm/pool.d/forgepanel.conf" <<'EOF'
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
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 68M
php_admin_value[open_basedir] = /opt/forgepanel/web:/opt/forgepanel/modules:/opt/forgepanel/phpmyadmin:/etc/forgepanel/web.ini:/tmp
php_admin_value[session.save_path] = /tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
EOF
    "php-fpm${PANEL_PHP}" -t
    install -d /run/php
    systemctl enable --now "php${PANEL_PHP}-fpm" 2>/dev/null || true
    systemctl reload "php${PANEL_PHP}-fpm" 2>/dev/null || true

    # Panel nginx na :8443 (self-signed do AutoSSL-a)
    install -d -m 700 "$FP_ETC/ssl/panel"
    if [[ ! -f "$FP_ETC/ssl/panel/fullchain.pem" ]]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
            -keyout "$FP_ETC/ssl/panel/privkey.pem" \
            -out "$FP_ETC/ssl/panel/fullchain.pem" \
            -subj "/CN=${PANEL_FQDN}" -addext "subjectAltName=DNS:${PANEL_FQDN}"
    fi
    install -d /etc/nginx/forgepanel/vhosts /etc/nginx/forgepanel/snippets /var/www/forgepanel-acme /var/www/vhosts

    # nginx.org default vhost na :80 se miče — porte 80/443 kontroliraju hostani vhostovi
    rm -f /etc/nginx/conf.d/default.conf

    cat > /etc/nginx/forgepanel/snippets/security.conf <<'EOF'
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
EOF

    # IPv6 listen samo ako sustav ima IPv6 (minimal VM-ovi ga znaju imati isključen)
    local LISTEN_V6="" LISTEN_V6_80=""
    if [[ -s /proc/net/if_inet6 ]]; then
        LISTEN_V6="    listen [::]:8443 ssl;"
        LISTEN_V6_80="    listen [::]:80 default_server;"
    fi

    cat > /etc/nginx/conf.d/forgepanel-panel.conf <<EOF
# ACME HTTP-01 catch-all — bez ovoga nginx prije prvog vhosta uopće ne sluša
# na :80 pa Let's Encrypt ne može validirati panel hostname. Vhostovi imaju
# vlastite server blokove (specifičan server_name pobjeđuje default_server).
server {
    listen 80 default_server;
${LISTEN_V6_80}
    server_name _;
    location /.well-known/acme-challenge/ { root /var/www/forgepanel-acme; }
    location / { return 404; }
}
# ForgePanel — panel UI/API na :8443, izoliran od hostanih stranica
server {
    listen 8443 ssl;
${LISTEN_V6}
    http2 on;
    server_name _;

    ssl_certificate     $FP_ETC/ssl/panel/fullchain.pem;
    ssl_certificate_key $FP_ETC/ssl/panel/privkey.pem;

    root $FP_HOME/web/public;
    index index.php;

    client_max_body_size 68m;

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
    # phpMyAdmin auto-login most (signed one-time token) — stvarni file, izvan SPA front controllera
    location = /pma-signon.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/fpm-forgepanel.sock;
    }
    # phpMyAdmin (auth_type=signon) — servira ga panelov izolirani stack
    location ^~ /pma/ {
        alias /opt/forgepanel/phpmyadmin/;
        index index.php;
        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$request_filename;
            fastcgi_pass unix:/run/php/fpm-forgepanel.sock;
        }
        location ~* \.(js|css|png|gif|svg|ico|woff2?)$ { expires 7d; }
    }
    location ~ \.php$ { return 404; }
}
# Include hostanih vhostova
include /etc/nginx/forgepanel/vhosts/*.conf;
EOF
    nginx -t
    systemctl enable --now nginx 2>/dev/null || true
    systemctl reload nginx 2>/dev/null || true
}

install_agent() {
    cp "$FP_HOME/agent/systemd/forge-agentd.service" /etc/systemd/system/forge-agentd.service
    systemctl daemon-reload 2>/dev/null || true
    systemctl enable --now forge-agentd 2>/dev/null || true
    # repair s novim kodom: agent mora učitati nove operacije/scheduler
    systemctl try-restart forge-agentd 2>/dev/null || true
}

install_phpmyadmin() {
    # phpMyAdmin u panelovom izoliranom stacku (/pma/ na :8443), auth_type=signon —
    # login ISKLJUČIVO kroz panelov signed one-time token, nikad ručni unos credentialsa.
    local pma_dir="/opt/forgepanel/phpmyadmin" tarball="/tmp/forgepanel-pma.tar.gz"
    if [[ ! -f "$pma_dir/index.php" ]]; then
        log "  preuzimam phpMyAdmin (latest, phpmyadmin.net)"
        if ! curl -fsSL --retry 3 -o "$tarball" \
            "https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz"; then
            log "UPOZORENJE: phpMyAdmin download nije uspio — panel radi bez njega, ponovi installer kasnije (repair mod)."
            return 0
        fi
        install -d /opt/forgepanel
        local tmp_dir; tmp_dir=$(mktemp -d)
        tar -xzf "$tarball" -C "$tmp_dir"
        rm -rf "$pma_dir"
        mv "$tmp_dir"/phpMyAdmin-* "$pma_dir"
        rm -rf "$tmp_dir" "$tarball"
    fi

    local blowfish; blowfish=$(openssl rand -base64 32 | head -c 32)
    cat > "$pma_dir/config.inc.php" <<EOF
<?php
/* ForgePanel — phpMyAdmin signon konfiguracija (NE uređivati ručno) */
declare(strict_types=1);
\$cfg['blowfish_secret'] = '${blowfish}';
\$cfg['Servers'][1]['auth_type'] = 'signon';
\$cfg['Servers'][1]['SignonSession'] = 'FPpmaSignon';
\$cfg['Servers'][1]['SignonURL'] = '/pma-signon.php';
\$cfg['Servers'][1]['host'] = 'localhost';
\$cfg['Servers'][1]['AllowNoPassword'] = false;
\$cfg['AllowArbitraryServer'] = false;
\$cfg['PmaAbsoluteUri'] = '/pma/';
\$cfg['TempDir'] = '${pma_dir}/tmp';
\$cfg['ShowPhpInfo'] = false;
\$cfg['VersionCheck'] = false;
EOF
    install -d -o fpanel -g fpanel -m 700 "$pma_dir/tmp"
    chown root:fpanel "$pma_dir/config.inc.php"
    chmod 640 "$pma_dir/config.inc.php"
    # Zastavica u DB — web sloj (open_basedir) ne može stat-ati /opt/forgepanel/phpmyadmin
    sql forgepanel -e "INSERT INTO components (name, status, packages) VALUES ('phpmyadmin', 'installed', '[]') ON DUPLICATE KEY UPDATE status='installed';" 2>/dev/null || true
    log "  phpMyAdmin spreman na https://${PANEL_FQDN}:8443/pma/ (auto-login iz panela)"
}

create_admin() {
    local admin_pass admin_hash
    admin_pass=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
    admin_hash=$(/usr/local/bin/forgepanel-php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID, ["memory_cost" => 65536, "time_cost" => 4, "threads" => 2]);' "$admin_pass")

    sql forgepanel <<SQL
INSERT INTO users (email, password_hash, role_id, lang)
SELECT '${ADMIN_EMAIL}', '${admin_hash}', id, 'hr' FROM roles WHERE name = 'admin'
ON DUPLICATE KEY UPDATE email = email;

-- Globalni "Admin" plan + aktivna pretplata: bez toga admin ne može kreirati
-- prvi resurs (model plan→pretplata→resurs). Idempotentno (repair ne duplicira).
INSERT INTO plans (owner_user_id, name, disk_bytes, max_domains, max_mailboxes,
                   max_databases, php_versions, features, cpu_quota_pct, memory_max_bytes, tasks_max)
SELECT NULL, 'Admin', 1099511627776, 10000, 10000, 10000,
       '["8.1","8.2","8.3","8.4","8.5"]', '{}', 400, 4294967296, 1024
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plans WHERE name = 'Admin' AND owner_user_id IS NULL);

INSERT INTO subscriptions (user_id, plan_id, status)
SELECT u.id, p.id, 'active'
FROM users u, plans p
WHERE u.email = '${ADMIN_EMAIL}' AND p.name = 'Admin' AND p.owner_user_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'active');
SQL

    cat > "$FP_CREDS" <<EOF
ForgePanel ${FP_VERSION} — pristupni podaci
URL:      https://${PANEL_FQDN}:8443
E-mail:   ${ADMIN_EMAIL}
Lozinka:  ${admin_pass}
Kreirano: $(date '+%d.%m.%Y. %H:%M')
EOF
    chmod 600 "$FP_CREDS"
    ADMIN_PASS_OUT="$admin_pass"
}

# ---------------------------------------------------------------- 5. opcionalne komponente
# --components=web,dns,ftp — mail/DNS/FTP su opcionalni, mogu se dodati i naknadno
optional_components() {
    export DEBIAN_FRONTEND=noninteractive
    if [[ ",$COMPONENTS," == *",dns,"* ]]; then
        log "  komponenta: dns (BIND9)"
        apt-get install -y -q bind9 bind9utils
        install -d /etc/bind/forgepanel
        systemctl enable --now named 2>/dev/null || true
        sql forgepanel -e "INSERT INTO components (name, status, packages) VALUES ('bind9', 'installed', '[\"bind9\"]') ON DUPLICATE KEY UPDATE status='installed';"
        ufw allow 53/tcp 2>/dev/null || true
        ufw allow 53/udp 2>/dev/null || true
    fi
    if [[ ",$COMPONENTS," == *",mail,"* ]]; then
        log "  komponenta: mail (Postfix + Dovecot + Rspamd — instalira agent kroz task)"
        sql forgepanel -e "INSERT INTO tasks (op, params) VALUES ('mail.setup', '{}');"
        for port in 25 143 465 587 993 995 110; do
            ufw allow "${port}/tcp" 2>/dev/null || true
        done
    fi
    if [[ ",$COMPONENTS," == *",ftp,"* ]]; then
        log "  komponenta: ftp (ProFTPD, TLS obavezan)"
        apt-get install -y -q proftpd-basic
        sql forgepanel -e "INSERT INTO components (name, status, packages) VALUES ('proftpd', 'installed', '[\"proftpd-basic\"]') ON DUPLICATE KEY UPDATE status='installed';"
        ufw allow 21/tcp 2>/dev/null || true
        ufw allow 49152:50192/tcp 2>/dev/null || true
    fi
}

# ---------------------------------------------------------------- 6. hardening
hardening() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y -q ufw fail2ban

    ufw allow 22/tcp
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw allow 8443/tcp
    ufw --force enable || log "UPOZORENJE: ufw enable nije uspio (container?) — provjeri ručno."

    cat > /etc/fail2ban/jail.d/forgepanel.conf <<'EOF'
[sshd]
enabled = true
EOF
    systemctl enable --now fail2ban 2>/dev/null || true

    # sysctl preset (umjereno, bez egzotike)
    cat > /etc/sysctl.d/90-forgepanel.conf <<'EOF'
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
kernel.kptr_restrict = 1
EOF
    sysctl --system >/dev/null 2>&1 || true

    # SSH: PasswordAuthentication NE diramo — korisnik se ne smije zaključati.

    # OS sloj: unattended-upgrades samo za security (panel orkestrira ostalo)
    apt-get install -y -q unattended-upgrades
    cat > /etc/apt/apt.conf.d/52forgepanel-unattended.conf <<'EOF'
Unattended-Upgrade::Allowed-Origins { "${distro_id}:${distro_codename}-security"; };
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
}

register_components() {
    local name suite
    while IFS='=' read -r name suite; do
        sql forgepanel <<SQL
INSERT INTO components (name, repo_suite, status, packages)
VALUES ('${name}', '${suite}', 'installed', '[]')
ON DUPLICATE KEY UPDATE repo_suite = '${suite}';
SQL
    done < "${FP_STATE}.suites"
}

# Panel AutoSSL odmah (scheduler bi ga svejedno pokrenuo unutar 15 min) — samo
# ako je trenutni cert još self-signed i task već ne čeka. Bez checkpointa:
# idempotentno, repair smije ponoviti.
enqueue_panel_ssl() {
    local crt="$FP_ETC/ssl/panel/fullchain.pem" subj issuer
    [[ -f "$crt" ]] || return 0
    subj=$(openssl x509 -in "$crt" -noout -subject 2>/dev/null) || return 0
    issuer=$(openssl x509 -in "$crt" -noout -issuer 2>/dev/null) || return 0
    [[ "${subj#subject=}" == "${issuer#issuer=}" ]] || return 0
    log "→ panel AutoSSL task (Let's Encrypt za ${PANEL_FQDN})"
    sql forgepanel -e "INSERT INTO tasks (op, params)
        SELECT 'ssl.panel_issue', '{}' FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM tasks WHERE op = 'ssl.panel_issue' AND status IN ('pending','running'));" \
        || log "UPOZORENJE: enqueue panel SSL taska nije uspio — scheduler će ga svejedno pokrenuti."
}

# ---------------------------------------------------------------- health check
verify_install() {
    local fail=0
    /usr/local/bin/forgepanel-php -v >/dev/null || { log "FAIL: panel PHP (forgepanel-php)"; fail=1; }
    nginx -t >/dev/null 2>&1 || { log "FAIL: nginx config"; fail=1; }
    sql forgepanel -e "SELECT COUNT(*) FROM users" >/dev/null || { log "FAIL: panel baza"; fail=1; }
    if systemctl is-active --quiet forge-agentd 2>/dev/null; then
        log "  forge-agentd: aktivan"
    else
        log "  forge-agentd: NIJE aktivan (provjeri: journalctl -u forge-agentd)"
    fi
    # API mora vratiti smislen odgovor (login bez parametara = 400), ne 5xx
    local code
    code=$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' \
        -X POST -d '{}' "https://127.0.0.1:8443/api/v1/auth/login" 2>/dev/null || echo 000)
    if [[ "$code" == "400" ]]; then
        log "  panel API: odgovara na :8443"
    else
        log "FAIL: panel API na :8443 vraća HTTP $code (očekivano 400)"; fail=1
    fi
    (( fail == 0 )) || die "Health check pao — vidi $FP_LOG."
}

# ---------------------------------------------------------------- tijek
main() {
    log "ForgePanel ${FP_VERSION} installer — početak"
    preflight
    resolve_repo_root
    step "bootstrap_repos"     bootstrap_repos
    repo_sanity
    step "install_database"    install_database
    # namjerno bez checkpointa: idempotentni koraci — repair s novijim kodom mora
    # osvježiti /opt/forgepanel, panelov nginx/FPM config i agent servis (apt na
    # već instaliranim paketima je no-op; self-signed cert se NE regenerira)
    log "→ install_panel_code (uvijek se osvježava)"
    install_panel_code
    log "→ install_panel_stack (uvijek se osvježava)"
    install_panel_stack
    step "setup_panel_db"      setup_panel_db
    log "→ install_agent (uvijek se osvježava)"
    install_agent
    step "install_phpmyadmin"  install_phpmyadmin
    step "create_admin"        create_admin
    step "optional_components" optional_components
    step "hardening"           hardening
    step "register_components" register_components
    enqueue_panel_ssl
    verify_install

    log ""
    log "═══════════════════════════════════════════════════"
    log " ForgePanel instaliran!"
    log " URL:     https://${PANEL_FQDN}:8443"
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
