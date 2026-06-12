# ForgePanel

Web hosting control panel za **Ubuntu Server 26.04 LTS** — nginx (+ opcionalno Apache), multi-PHP 8.1–8.4, MariaDB/MySQL, mail stack (Postfix + Dovecot + Rspamd + Roundcube), DNS (BIND9), AutoSSL (Let's Encrypt), phpMyAdmin s auto-loginom, backup, Git deploy, Docker, malware skener + WAF, auto-update orkestrator s rollbackom. Multi-tenant: admin / reseller (white-label) / klijent.

Panel radi u **vlastitom izoliranom stacku** (port 8443, neprivilegirani user `fpanel`); sve sistemske akcije idu kroz root agent (`forge-agentd`) s whitelistom operacija — web sloj nikad ne izvršava shell komande.

---

## 1. Preduvjeti

### Server

| Zahtjev | Minimum |
|---|---|
| OS | **Ubuntu Server 26.04 LTS** — isključivo, installer odbija sve drugo |
| Stanje | **Potpuno svjež minimal install** — bez postojećeg nginxa, Apachea, MySQL-a ili drugog panela (installer to provjerava i prekida ako nađe konflikt) |
| RAM | 2 GB |
| Disk | 20 GB slobodno |
| Pristup | root (SSH) |
| Mreža | radan DNS resolver, izlaz na internet (apt repoi, Let's Encrypt) |

> Ako provider ima vanjski firewall/security-grupe, otvori portove **22, 80, 443, 8443** (i mail portove ako instaliraš mail — vidi §5).

### DNS — postavi PRIJE instalacije

AutoSSL izdaje pravi Let's Encrypt certifikat za panel odmah po instalaciji, ali samo ako hostname javno resolva na server.

Kod svog DNS providera (ili registrara) dodaj:

```
panel.tvojadomena.hr.    A     <IP_SERVERA>
```

Pričekaj da se zapis propagira (provjera: `dig +short panel.tvojadomena.hr` mora vratiti IP servera). Bez toga instalacija prolazi, ali panel ostaje na privremenom self-signed certifikatu dok DNS ne proradi.

---

## 2. Instalacija

### Korak 1 — spoji se na server

```bash
ssh root@<IP_SERVERA>
```

### Korak 2 — dohvati kod

```bash
apt-get update && apt-get install -y git
git clone https://github.com/zarkostrelec/forgepanel.git /opt/forgepanel-src
cd /opt/forgepanel-src
```

> Privatni repo? Kloniraj s tokenom (`https://<token>@github.com/...`) ili prebaci kod na server sa `scp`/`rsync` — installer radi iz bilo kojeg direktorija koji sadrži `agent/` i `web/`.

### Korak 3 — pokreni installer

**Interaktivno** (pita samo za admin e-mail):

```bash
bash installer/install.sh --hostname panel.tvojadomena.hr
```

**Bez pitanja** (preporučeno za automatizaciju):

```bash
bash installer/install.sh --unattended \
    --hostname panel.tvojadomena.hr \
    --email ti@tvojadomena.hr \
    --db mariadb \
    --components web
```

#### Svi flagovi

| Flag | Vrijednosti | Default | Opis |
|---|---|---|---|
| `--hostname` | FQDN | postojeći `hostname -f` | Panel hostname; installer ga postavlja i upisuje u `/etc/hosts` |
| `--email` | e-mail | — (interaktivni upit) | Admin račun + ACME kontakt; obavezan uz `--unattended` |
| `--db` | `mariadb` \| `mysql` | `mariadb` | Database engine (MariaDB 11.x LTS ili MySQL 8.4 LTS) |
| `--components` | `web,dns,mail,ftp,docker` | `web` | Što instalirati odmah; sve se može dodati i naknadno (§5) |
| `--unattended` | — | — | Bez interaktivnih pitanja |
| `--uninstall` | — | — | Uklanjanje ForgePanela (s potvrdom) |

Za prvi test preporučujem samo `--components web` — mail, DNS i FTP dodaš kasnije jednim klikom iz panela.

### Korak 4 — pričekaj (5–15 min)

Installer redom, s checkpointom nakon svakog koraka:

1. **Preflight** — OS 26.04, root, čist sustav, ≥2 GB RAM / ≥20 GB disk, FQDN, DNS resolver, NTP
2. **Repoi** — universe + nginx.org, ondrej/php, ondrej/apache2, mariadb.org (deb822 format, ključevi u `/etc/apt/keyrings/`); ako repo još nema `resolute` suite: nginx/mariadb/docker padaju na `noble` (binarno kompatibilni), a **ondrej PPA se preskače** (noble PHP buildovi nisu instalabilni na 26.04) — panel tada koristi distro PHP 8.5, a PPA se dodaje automatski kad objavi `resolute`
3. **Baza** — MariaDB/MySQL + zasebna `forgepanel` baza s vlastitim userom
4. **Panel stack** — kod u `/opt/forgepanel`, vlastiti PHP-FPM pool (user `fpanel`; PHP 8.4 iz ondrej PPA ako ima suite za 26.04, inače distro PHP 8.5), panel nginx na :8443 (privremeni self-signed cert)
5. **Agent** — `forge-agentd` systemd servis (root, hardening direktive, UNIX socket)
6. **phpMyAdmin** — u panelov stack na `/pma/`, login isključivo kroz panelov jednokratni token
7. **Admin račun** — generirana lozinka
8. **Opcionalne komponente** prema `--components`
9. **Hardening** — ufw (22/80/443/8443 + portovi komponenti), fail2ban, sysctl preset, `unattended-upgrades` (samo security); **SSH config se NE dira**
10. **Health check** — PHP, nginx config, baza, agent, API proba na :8443

Cijeli tijek se loggira u `/var/log/forgepanel-install.log`.

### Korak 5 — prijava

Na kraju installer ispiše:

```
═══════════════════════════════════════════════════
 ForgePanel instaliran!
 URL:     https://panel.tvojadomena.hr:8443
 E-mail:  ti@tvojadomena.hr
 Lozinka: <generirana>
 Podaci spremljeni u /root/.forgepanel-credentials (chmod 600)
═══════════════════════════════════════════════════
```

1. Otvori URL u browseru (ako AutoSSL još nije prošao, prihvati privremeni self-signed cert — pravi stiže automatski čim DNS radi)
2. Prijavi se generiranim podacima
3. **Odmah:** klik na svoj e-mail u sidebaru → **Profil i sigurnost** → uključi **2FA** (TOTP aplikacija) i/ili registriraj **sigurnosni ključ** (YubiKey, otisak prsta) — 2FA je za admina obavezan
4. Promijeni generiranu lozinku

---

## 3. Prva domena (smoke test)

1. **Websites → Nova domena** — unesi domenu, odaberi PHP verziju → panel kreira vhost (vlastiti sistemski user, izolirani FPM pool, nginx config) i AutoSSL task
2. DNS domene usmjeri na server (A zapis) — bez toga SSL task čeka
3. **Databases → Nova baza** → dodaj DB usera → gumb **phpMyAdmin** otvara bazu bez unosa lozinke
4. **File manager** (na detalju domene) → uploadaj `index.php` → otvori domenu u browseru

Svaki pozadinski posao vidiš živo u **task trayu** (ikona aktivnosti gore desno), uključujući točno koje je operacije agent izvršio.

---

## 4. Lokacije na serveru

| Što | Gdje |
|---|---|
| Panel kod | `/opt/forgepanel` |
| Panel config | `/etc/forgepanel/web.ini`, `/etc/forgepanel/agent.ini` |
| Credentialsi (nakon instalacije) | `/root/.forgepanel-credentials` |
| Install log | `/var/log/forgepanel-install.log` |
| Checkpoint state | `/var/lib/forgepanel/install-state` |
| Vhostovi (siteovi) | `/var/www/vhosts/<domena>/` |
| nginx confovi vhostova | `/etc/nginx/forgepanel/vhosts/` |
| SSL certifikati | `/etc/forgepanel/ssl/<hostname>/` |
| phpMyAdmin | `/opt/forgepanel/phpmyadmin` |
| Agent servis | `systemctl status forge-agentd`, logovi: `journalctl -u forge-agentd` |

---

## 5. Komponente naknadno

Sve što nisi instalirao odmah dodaješ iz panela (admin):

- **Mail** (Postfix + Dovecot + Rspamd): stranica **Mail → Instaliraj** — pokreće task; nakon toga **Instaliraj webmail** podiže Roundcube na zasebnom vhostu (npr. `webmail.tvojadomena.hr` — treba A zapis). Otvori i mail portove ako nisu: `25, 110, 143, 465, 587, 993, 995`
- **DNS** (BIND9), **FTP** (ProFTPD): ponovno pokreni installer s proširenim `--components` (vidi §6 — repair mod preskače gotove korake) ili instaliraj iz panela
- **Docker**: stranica **Docker** — engine se instalira automatski pri prvoj upotrebi (repo mora biti dodan: `--components docker` ili naknadno)

---

## 6. Repair / nastavak / uklanjanje

- **Prekinuta instalacija?** Pokreni installer ponovno — checkpointi preskaču završene korake, nastavlja se gdje je stalo.
- **Nešto se pokvarilo?** Isti princip: ponovno pokretanje installera = repair mod.
- **Uklanjanje:**

```bash
bash installer/install.sh --uninstall
```

---

## 7. Troubleshooting

| Simptom | Provjera |
|---|---|
| Installer odbija start | Pročitaj poruku — preflight točno kaže što ne valja (OS, RAM, FQDN, konflikt...) |
| Instalacija stala | `tail -50 /var/log/forgepanel-install.log` |
| apt ne može skinuti pakete (`Unable to connect to …`) | Mirror je pao — zamijeni `URIs:` u `/etc/apt/sources.list.d/forgepanel-*.sources` drugim mirrorom, `apt-get update`, pa ponovno pokreni installer (checkpointi nastavljaju gdje je stalo) |
| Panel ne odgovara na :8443 | `systemctl status nginx 'php*-fpm'` · `curl -k https://127.0.0.1:8443` lokalno · vanjski firewall providera? |
| Taskovi stoje u "pending" | `systemctl status forge-agentd` · `journalctl -u forge-agentd -n 50` |
| AutoSSL ne izdaje cert | DNS zapis resolva na server? Port 80 otvoren izvana? Detalji u tasku (SSL stranica) |
| Mail se ne šalje | Provider blokira port 25? (čest slučaj — traži unblock ili koristi relay) |
| Zaboravljena admin lozinka | `cat /root/.forgepanel-credentials`; ako je mijenjana, reset kroz bazu (vidi `docs/`) |

---

## 8. Razvoj i testovi

```bash
php tests/run.php                                  # test suite (bez vanjskih ovisnosti)
php -S 127.0.0.1:8080 -t web/public               # dev server UI-ja (bez agenta — samo UI)
```

- Backend: čisti PHP 8.4, bez frameworka · Frontend: vanilla JS (ES2024), bez build alata
- API-first: sve što UI radi dostupno je na `/api/v1/` (Bearer token sa scopovima) — spec u `docs/openapi.yaml`
- Arhitektura i backup format: `docs/architecture.md`, `docs/backup-format.md`
- Preporučena test okolina za installer: čisti Ubuntu 26.04 u multipass/LXD VM-u

## 9. Sigurnosne napomene

- Panel port 8443 možeš dodatno ograničiti na svoj IP: `ufw allow from <tvoj_ip> to any port 8443` pa `ufw delete allow 8443/tcp`
- SSH installer namjerno **ne dira** — sam postavi ključeve i isključi password login kad si spreman
- Sve panel akcije završavaju u **audit logu** (append-only, ne može se brisati iz UI-ja)
- Backup format je dokumentiran tar + manifest (SHA-256) — restore je izvediv i ručno, bez panela
