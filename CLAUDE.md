# CLAUDE.md — ForgePanel

> Ultimativni web hosting control panel — kombinacija najboljeg iz Pleska, cPanela, Webmina/Virtualmina, DirectAdmina i HestiaCP-a, bez njihovih mana. **Cilja isključivo Ubuntu Server 26.04 LTS (Resolute Raccoon)**, instalira se na potpuno "goli" minimal server bez ijednog pred-instaliranog repozitorija ili komponente. Feature-full, Ubuntu-native, bez kompromisa radi prenosivosti.

---

## ⚙️ DEPLOY NA LIVE (elite) — PROČITATI PRVO, NE OTKRIVATI IZNOVA

Server `elite` ima **dvije odvojene putanje** (to je čest izvor zabune):

| | Putanja | Uloga |
|---|---|---|
| **git checkout** | `/opt/forgepanel-src` | ovaj repo (`git pull` ide ovdje) |
| **nginx docroot** | `/opt/forgepanel/web/public` | ono što se STVARNO servira |

`/opt/forgepanel` je **zasebna kopija, NIJE symlink** na `-src`. Zato `git pull` u `-src` sam po sebi **ne** mijenja ono što korisnik vidi — treba sinkronizirati `-src → /opt/forgepanel`. Nginx config: `/etc/nginx/conf.d/forgepanel-panel.conf`.

**Deploy (kao root na serveru):**
```bash
cd /opt/forgepanel-src && ./deploy.sh
```
Skripta: `git reset --hard origin/main` → `rsync -src → /opt/forgepanel` (bez `--delete`) → `systemctl reload nginx`. Statički asseti (`web/public/assets/app.*`) ne trebaju restart, samo **hard refresh (Ctrl+Shift+R)** u browseru. Ako se mijenjao `agent/` ili `web/src` (PHP): `systemctl restart forge-agentd php8.4-fpm`.

Push uvijek na **main** (vidi pravila niže); `origin/main` je istina, lokalni `main` u nekim okruženjima može biti nepovezana grana — tada `git checkout -B main origin/main` prije rada.

---

## 0. ANALIZA POSTOJEĆIH PANELA (temelj odluka)

### Plesk
**Prednosti (preuzimamo):**
- Najbolji UI/UX u industriji — jedan panel za admina i klijenta, kontekstualan, pregledan
- Git auto-deploy integracija
- Ekstenzijski ekosustav (WP Toolkit, Docker, Let's Encrypt, Imunify)
- Service plans / subscriptions model (plan → pretplata → domene)
- Vlastiti izolirani stack za panel — update hostanih komponenti nikad ne ruši panel
- nginx ispred Apachea (reverse proxy) — fleksibilnost .htaccess + brzina nginxa

**Mane (izbjegavamo):**
- Skup licencni model, težak na resursima (interni Java/PostgreSQL servisi), zatvoren kod, ekstenzije nekonzistentne kvalitete

### cPanel/WHM
**Prednosti (preuzimamo):**
- Industrijski standard backup formata (cpmove tar) — naš import ga MORA čitati
- WHM/cPanel separacija: admin razina vs. korisnička razina — jasna podjela ovlasti
- Najbolja dokumentacija i API (UAPI/WHM API) — sve što UI radi, radi i API
- AutoSSL koncept (automatski SSL za sve, bez klikanja)
- EasyApache koncept: više PHP verzija paralelno, per-domena odabir

**Mane (izbjegavamo):**
- Agresivna poskupljenja po broju računa, fragmentiran/arhaičan UI (tri generacije sučelja), monolitna Perl baza teška za održavanje

### Webmin / Virtualmin
**Prednosti (preuzimamo):**
- Besplatan, modularan — sve je modul, lako se dodaje novi
- Dubok pristup sustavu — uređivanje configa iz panela (mi: s validacijom i rollbackom)
- Transparentnost: ne skriva od admina što panel zapravo radi

**Mane (izbjegavamo):**
- UX iz 2005., povijest sigurnosnih propusta (cijeli panel radi kao root!), nekonzistentni moduli

### DirectAdmin
**Prednosti:** lagan (radi na 512 MB RAM-a), brz, stabilan. → Cilj: ForgePanel idle footprint < 300 MB RAM.
**Mane:** siromašan feature set, slabiji UI.

### HestiaCP / CyberPanel / aaPanel
**Prednosti:** one-line instalacija (`curl | bash`), CyberPanel: odlična cache integracija, aaPanel: izvrstan file manager i app-store pristup.
**Mane:** sigurnosni incidenti, slab multi-tenant model, ograničena dubina.

### Sinteza — što ForgePanel uzima od koga
| Komponenta | Uzor |
|---|---|
| UI/UX, service plans, Git deploy, izolirani panel stack | Plesk |
| Backup format, API-first, AutoSSL, multi-PHP | cPanel |
| Transparentnost komandi, config editor s validacijom, modularnost | Webmin |
| Resursna laganost | DirectAdmin |
| One-line installer | HestiaCP |
| File manager, marketplace aplikacija | aaPanel |

---

## 1. SCOPE

1. **Jedina platforma: Ubuntu Server 26.04 LTS** (podržan do 2031.). Bez OS-abstraction sloja, bez podrške za druge distribucije, bez Windowsa. Kod smije i treba koristiti Ubuntu-native mehanizme punim plućima: apt + deb822 sources, systemd (v259, isključivo cgroup v2), ufw, snap se NE koristi za serverske komponente (sve preko apt-a radi determinističkih verzija).
2. Server je na početku **potpuno gol**: minimal install, samo `main`/`restricted` Ubuntu repoi. Installer (poglavlje 12) sam dodaje SVE repozitorije, ključeve i komponente.
3. "Kompatibilnost s drugim panelima" = migracijski importeri (poglavlje 11): čitanje cPanel cpmove backupa, Plesk backup XML-a i generic live migracija preko SSH. Plus izvoz u cPanel-kompatibilnu strukturu (anti vendor-lock).
4. Razvoj po fazama (poglavlje 14). MVP prvo, sve ostalo iterativno — ne pokušavati sve odjednom.

---

## 2. TECH STACK

- **Backend:** čisti PHP 8.4 (`declare(strict_types=1)` svugdje), bez frameworka. Composer paketi za specifične potrebe (phpseclib, ACME klijent, PHPMailer); `vendor/` u repu.
- **Frontend:** vanilla HTML5/CSS3/JS (ES2024+), Web Components za ponavljajuće elemente, bez build alata.
- **Baza panela:** MariaDB/MySQL — zaseban DB `forgepanel` s vlastitim userom, NIKAD dijeliti credentials s hostanim bazama.
- **Realtime:** SSE za log streaming, task progress i monitoring. Bez WebSocket servera.
- **Stil:** snake_case (DB + PHP), PSR-12, europski formati (DD.MM.YYYY., 1.250,00 €).
- **UI jezici:** HR + EN, JSON lang fajlovi, default HR ("selected" = "odabrano").

---

## 3. ARHITEKTURA (najvažnije poglavlje)

Tri sloja, strogo odvojena — rješava Webminovu najveću manu (panel kao root) i preuzima Pleskovu najveću prednost (izolirani panel stack):

```
┌──────────────────────────────────────────────────────┐
│ 1. WEB SLOJ (port 8443, vlastiti nginx + PHP-FPM 8.4)│
│    - radi kao neprivilegirani user `fpanel`          │
│    - UI + REST API (/api/v1/...)                     │
│    - NEMA root, NEMA sudo                            │
│    - sistemske akcije šalje kao TASK agentu          │
├──────────────────────────────────────────────────────┤
│ 2. AGENT (forge-agentd, PHP CLI daemon, systemd      │
│    unit s hardening direktivama, radi kao root)      │
│    - sluša UNIX socket /run/forgepanel/agent.sock    │
│      (root:fpanel, mode 0660)                        │
│    - prima SAMO predefinirane operacije (op-code +   │
│      validirani parametri), NIKAD raw komande        │
│    - svaka operacija = zasebna PHP klasa (Operation) │
│    - sve loggira u audit_log                         │
├──────────────────────────────────────────────────────┤
│ 3. SUSTAV: nginx, Apache, PHP-FPM poolovi, MariaDB/  │
│    MySQL, Postfix, Dovecot, Rspamd, BIND9, Docker,   │
│    ProFTPD, ufw, fail2ban...                         │
└──────────────────────────────────────────────────────┘
```

**Ključna pravila:**
- Web sloj NIKAD ne izvršava shell komande. Sve ide kroz agent socket (JSON: `{"op": "vhost.create", "params": {...}, "task_id": 123}`).
- Agent ima **whitelist operacija**. Nepoznat op-code = odbij + log. Parametri se validiraju (FQDN regex, pathovi moraju biti unutar `/var/www/vhosts/` — realpath provjera protiv symlink trikova) PRIJE izvršavanja. Injection nemoguć po dizajnu: `proc_open` isključivo s array argumentima.
- Dugotrajne operacije (backup, instalacija PHP verzije, migracija) → **task queue** (tablica `tasks`), agent obrađuje asinkrono, UI prati progress preko SSE.
- **Panel ima vlastiti stack:** zaseban nginx config (port 8443) + zaseban PHP-FPM pool s panelovim PHP-om 8.4 (paket iz ondrej PPA, pinnan). Update PHP-a hostanih stranica ne dira panel.
- **API-first:** UI je samo potrošač REST API-ja. Svaka funkcija dostupna na `/api/v1/` s Bearer token autentikacijom. OpenAPI spec u `docs/` se održava uz kod.
- `forge-agentd.service`: `ProtectSystem=strict` + eksplicitni `ReadWritePaths`, `NoNewPrivileges` gdje moguće, `WatchdogSec` + systemd watchdog ping iz daemona.

### Web server model za hostane stranice
- **nginx uvijek prvi** → reverse proxy na **Apache** (per-domena, za .htaccess/WordPress) ILI **direktno nginx + PHP-FPM** (default za nove stranice, brže). Per-domena izbor u UI-ju.
- **Multi-PHP:** 8.1 / 8.2 / 8.3 / 8.4 paralelno iz `ppa:ondrej/php`, svaka domena bira verziju.
- **Izolacija vhosta:** svaki vhost = vlastiti sistemski user `vh_<id>`, vlastiti PHP-FPM pool (ondemand), open_basedir, disable_functions preset, vlastiti `/var/www/vhosts/<domena>/tmp`, PrivateTmp.

---

## 4. MULTI-TENANT MODEL (svaki korisnik upravlja svojim svijetom)

Ovo nije admin-only alat. Tri razine, svaka s vlastitim pogledom na isti panel (Plesk model, ne cPanelov dvostruki UI):

- **Admin** — sve: server, komponente, updatei, svi korisnici, globalne postavke
- **Reseller** — vlastiti paket resursa koji dijeli na svoje klijente, kreira planove i korisnike unutar svojih limita, **white-label**: vlastiti logo, boje (accent), naziv panela i vlastita panel domena (npr. panel.reseller.hr s vlastitim SSL-om) — klijent resellera nikad ne vidi ForgePanel brend
- **Client** — vidi i upravlja ISKLJUČIVO svojim: domenama, subdomenama, mailovima, bazama, FTP-om, cronom, backupima, SSL-om, Git deployem, file managerom, statistikom — sve u granicama svog plana

**Pravila izolacije:**
- Svaki API endpoint i svaki UI ekran filtrira po `subscription_id` — klijent fizički ne može dohvatiti tuđi resurs (provjera vlasništva u middleware-u, ne u svakom endpointu ručno)
- File manager, cron i Git ograničeni na vhost root korisnika (realpath provjera u agentu)
- **Resource kvote per korisnik bez CloudLinuxa** (cPanel za ovo traži plaćeni CloudLinux LVE!): cgroup v2 limiti na FPM poolovima (CPUQuota, MemoryMax, TasksMax), project quota za disk, per-user broj procesa — klijent koji "podivlja" ne ruši ostale
- **Delegirani pristup:** klijent može dati svom developeru pristup samo određenoj domeni (granularno: files+git, ali ne mail/backup) — feature koji Plesk ima polovično, cPanel tek odnedavno
- Per-user API tokeni sa scope-ovima — klijent može automatizirati svoje resurse, ničije druge
- Login notifikacije (novi uređaj/IP), 2FA politika per rola (admin obavezno, klijenti opcionalno/enforced po izboru admina)
- Klijentski dashboard: potrošnja resursa svog plana (disk, mail, baze, CPU/RAM svojih poolova), istek SSL-ova, status svojih stranica — sve SVOJE, ništa tuđe

---

## 5. UBUNTU 26.04 INTEGRACIJA (umjesto OS-abstraction sloja)

Sav sistemski kod smije pretpostaviti Ubuntu 26.04, ali sve sistemske pozive centralizirati u `agent/src/System/` helpere (Apt, Systemd, Ufw, Fs) — radi testabilnosti i jednog mjesta istine, ne radi prenosivosti.

**Repozitoriji — deb822 format isključivo** (`/etc/apt/sources.list.d/*.sources`), GPG ključevi u `/etc/apt/keyrings/` (nikad apt-key):

```
# primjer: /etc/apt/sources.list.d/forgepanel-php.sources
Types: deb
URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu
Suites: resolute
Components: main
Signed-By: /etc/apt/keyrings/ondrej-php.gpg
```

**Pravilo fallback suite-a:** 26.04 je nov LTS — ako third-party repo još nema `resolute` suite (provjera: HTTP HEAD na `dists/resolute/Release`), installer koristi `noble` suite (binarno kompatibilan) i zapisuje to u `components` tablicu; update orkestrator periodički provjerava dostupnost `resolute` suite-a i automatski prebacuje + javlja notifikaciju.

| Komponenta | Repo |
|---|---|
| nginx (mainline/stable izbor) | nginx.org |
| Apache 2.4 najnoviji | ppa:ondrej/apache2 |
| PHP 8.1–8.4 paralelno | ppa:ondrej/php |
| MariaDB 11.x LTS (default) ili MySQL 8.4 LTS | mariadb.org / repo.mysql.com (izbor pri instalaciji) |
| Docker Engine + Compose plugin | download.docker.com |
| Rspamd | rspamd.com |
| Postfix, Dovecot, BIND9, ProFTPD, fail2ban, redis | Ubuntu universe (installer ga uključuje: `add-apt-repository universe`) |

**Systemd specifičnosti 26.04:** systemd 259, samo cgroup v2 (Docker i FPM resource limiti se konfiguriraju kroz v2 sučelje — `MemoryMax`, `CPUQuota` per FPM pool unit drop-inima za resource kvote po vhostu). Per-vhost kvote diska preko projektnih kvota (ext4/xfs project quota), ne starim user kvotama.

**Mreža/firewall:** ufw kao frontend (Ubuntu-native), panel upravlja ufw aplikacijskim profilima; ipset za country/bot blokade.

---

## 6. MODULI (svaki = direktorij u `modules/`)

Svaki modul: `manifest.json` (ime, verzija, ovisnosti, agent operacije koje registrira), `api/`, `ui/`, `operations/`. Webminova modularnost s konzistentnim okvirom.

1. **websites** — vhostovi, subdomene, aliasi, PHP verzija per domena, nginx/Apache mod, custom direktive s obaveznim `nginx -t`/`apachectl configtest` prije reloada + automatski rollback na zadnji ispravan config ako test padne; HTTP/3 (QUIC) toggle; gzip/brotli; per-vhost nginx snippeti iz whiteliste
2. **dns** — BIND9: zone templating, svi record tipovi, DNSSEC (automatsko potpisivanje + CDS/CDNSKEY), AXFR za sekundarne, catch: pri kreiranju domene auto-generiranje kompletne zone (A/AAAA/MX/SPF/DKIM/DMARC/CAA)
3. **mail** — Postfix + Dovecot + Rspamd: mailboxi, aliasi, forwarderi, autoresponderi, catch-all, kvote (Dovecot quota), sieve filteri kroz UI; DKIM ključ per domena automatski; Roundcube webmail na zasebnom vhostu; mail log parser (delivered/bounced/deferred pregled po porukama)
4. **databases** — MariaDB/MySQL: baze, useri, privilegije, remote access toggle (s ufw pravilom), phpMyAdmin s auto-loginom (signed one-time token), per-DB veličina i pregled
5. **ssl** — vlastiti ACME klijent (Let's Encrypt primarni + ZeroSSL fallback): **AutoSSL** — svaki novi vhost/subdomena/mail hostname/panel hostname automatski dobiva cert; obnova 30 dana prije isteka; wildcard preko DNS-01 kad je DNS lokalni (BIND); OCSP stapling; pregled svih certifikata s istekom
6. **filemanager** — aaPanel razina: drag-drop + chunked upload za velike fajlove, editor sa syntax highlightingom, arhiviranje/ekstrakcija (zip/tar/gz), permissions/owner, search; SVE operacije kroz agent s realpath provjerom unutar vhost roota
7. **ftp** — ProFTPD, virtualni useri vezani na vhost path, TLS obavezan
8. **backup** — poglavlje 10
9. **cron** — per-vhost cron, izvršava se kao vhost user (NIKAD root), human-readable scheduler UI, output zadnjih izvršavanja
10. **git** — Plesk-style: repo URL + generiranje deploy keya + webhook endpoint + auto-deploy na push (izbor brancha), post-deploy akcije iz whiteliste (composer install, cache clear)
11. **docker** — Docker Engine: image pull, container CRUD, port mapping, volumes, env vars, live logs (SSE), restart policies; nginx proxy mapping domena → container port jednim klikom; resource limiti (cgroup v2)
12. **firewall** — ufw UI + fail2ban (pregled i upravljanje banovima, jail config), country blocking (ipset + dnevno ažurirane GeoIP liste), gotovi setovi: AI/SEO bot-blocking (nginx map), xmlrpc blok, itd.
13. **monitoring** — CPU/RAM/disk/IO/net grafovi (vlastite tablice s agregacijom minute→sat→dan), per-service status, per-vhost potrošnja (cgroup v2 statistika FPM poolova!), e-mail alarmi na pragove; **eksterni uptime monitoring** (HTTP/HTTPS/TCP probe za svaki vhost s response-time grafom, SSL expiry alarmi klijentu, status stranica u klijentskom dashboardu — panelovi ovo nemaju, svi plaćaju UptimeRobot sa strane); PHP-FPM i opcache statistika per pool; MariaDB slow query log viewer s EXPLAIN gumbom
14. **logs** — centralni viewer: nginx/Apache/PHP/mail/system (journald integracija — `journalctl` JSON output kroz agent), live tail preko SSE, filteri, per-vhost error logovi
15. **users** — poglavlje 4 (admin / reseller / client role) + delegirani pristup
16. **updates** — poglavlje 9 (krunski modul)
17. **migrator** — poglavlje 10
18. **apps** — one-click instalacije: WordPress (instalacija, kloniranje, staging s search-replace URL-ova, auto-update politika per instanca, popis pluginova/tema s ranjivostima), Nextcloud i dr.
19. **cloudflare** — integracija preko Cloudflare API-ja (API token per user/subscription, scoped permisije):
    - **DNS sync:** per-domena izbor — DNS lokalno (BIND) ILI na Cloudflareu; kod CF moda panel piše zapise direktno u CF zonu (kreiranje vhosta/maila/subdomene automatski dodaje A/MX/SPF/DKIM/DMARC zapise u CF), dvosmjerna sinkronizacija s detekcijom konflikata
    - **Proxy toggle** (narančasti oblak) per record iz UI-ja; SSL mode kontrola (Full Strict preporučen + automatski origin certifikat)
    - **DNS-01 ACME preko CF API-ja** — wildcard certifikati i za domene čiji DNS nije lokalni
    - **Cache purge** (cijela zona ili pojedini URL-ovi) iz panela, npr. nakon Git deploya (opcija auto-purge)
    - **Real visitor IP:** automatska nginx konfiguracija `set_real_ip_from` s CF IP rangevima (dnevno ažuriranje liste) — fail2ban i logovi vide prave IP-ove
    - Firewall sinergija: opcija "samo CF smije na 80/443" (ufw allowlist CF rangeva) za domene iza proxyja
20. **security** — integrirani antivirus i WAF (ono što je kod cPanela/Pleska plaćeni Imunify360 add-on, kod nas ugrađeno):
    - **Malware skener:** ClamAV + vlastiti PHP webshell heuristički engine (eval+base64 chainovi, gzinflate/str_rot13 obfuskacija, hex payloadi, sumnjivi fileovi u uploads direktorijima, `.ico`/`.jpg` s PHP kodom) + YARA pravila; zakazani scanovi (per-vhost ili full), real-time scan novih uploada (inotify watch na uploads direktorije)
    - **Karantena:** zaraženi file se premješta (ne briše) + notifikacija vlasniku i adminu + **one-click restore čiste verzije iz zadnjeg backupa prije infekcije** (usporedba checksuma kroz backup povijest — nitko od panela to nema)
    - **WAF:** ModSecurity + OWASP Core Rule Set, per-vhost on/off i paranoia level, pregled blokiranih zahtjeva s one-click whitelist pravilom (rješava glavnu manu CRS-a: false positive horor bez dobrog UI-ja)
    - **Anomaly detection na logovima:** 404 floods, wp-login/xmlrpc brute force, traversal pokušaji, SQLi/XSS patterni → automatski privremeni ban (fail2ban custom jailovi koje modul generira)
    - Integritet WordPress corea: usporedba checksuma s wordpress.org, prikaz modificiranih core fileova

---

## 7. SHEMA BAZE (utf8mb4, InnoDB, snake_case — puni DDL u `database/schema.sql`)

1. `users` (email, password_hash argon2id, role_id, twofa_secret, lang, ...)
2. `roles` — admin/reseller/client, granularne permisije (JSON)
3. `plans` — disk_bytes, max_domains, max_mailboxes, max_databases, php_versions (JSON), features (JSON)
4. `subscriptions` — user ↔ plan, status, expires_at
5. `vhosts` — domain, subscription_id, sys_user, php_version, web_backend ENUM('nginx','nginx_apache'), docroot, status
6. `vhost_aliases`, `subdomains`
7. `dns_zones`, `dns_records`
8. `mail_domains`, `mailboxes`, `mail_aliases`
9. `db_databases`, `db_users`
10. `ssl_certs` — vhost_id, type, expires_at, auto_renew, status
11. `ftp_users`
12. `cron_jobs`
13. `git_repos` — vhost_id, repo_url, branch, deploy_key, webhook_secret, last_commit, last_deploy_at
14. `docker_containers` — registrirani containeri + proxy mapiranja
15. `backups`, `backup_schedules`, `backup_destinations` (local/ftp/sftp/s3)
16. `tasks` — queue: op, params (JSON), status, progress, output, started_at, finished_at
17. `components` — registar komponenti: name, current_version, available_version, repo_suite, policy_id
18. `component_updates` — povijest: from_version, to_version, status, rollback_info (JSON)
19. `update_policies` — per komponenta: mode, maintenance window, delay_days
20. `monitoring_metrics` — agregirano s retencijom
21. `audit_log` — append-only, NE može se brisati iz UI-ja
22. `sessions`, `api_tokens` (scoped)
23. `settings` (key/value JSON)
24. `notifications`
25. `cloudflare_accounts` (user_id, api_token enkriptiran, status), `cloudflare_zones` (vhost_id, zone_id, dns_mode ENUM('local','cloudflare'), proxy_default, ssl_mode, auto_purge_on_deploy)
26. `malware_scans`, `quarantine_items` (path, vhost_id, signature, detected_at, restored_from_backup_id)
27. `waf_rules` (vhost_id, rule_id, action ENUM('block','whitelist'))
28. `uptime_probes` (vhost_id, type, interval_s, last_status, response_ms), `uptime_events`
29. `dmarc_reports` (mail_domain_id, org, date_range, parsed JSON), `rbl_checks`
30. `config_versions` (component, path, version_hash, changed_by, changed_at) — config time-machine
31. `delegated_access` (grantor_user_id, grantee_user_id, vhost_id, permissions JSON)
32. `staging_envs` (source_vhost_id, staging_vhost_id, created_at, last_sync)

Kvote/veličine u INT bajtovima, nikad FLOAT.

---

## 8. SIGURNOST (nulta tolerancija)

- Login: argon2id, rate limiting (5 pokušaja → exponential backoff), TOTP 2FA (obavezan za admin), session binding IP+UA (popustljiv mod opcija)
- CSRF tokeni na svim mutacijama, SameSite=Strict, strogi CSP, puni set security headera
- API: Bearer tokeni sa scopovima (`vhosts:read`, `backup:write`...), expiry, revokacija, rate limiting
- Agent: socket 0660 root:fpanel, op whitelist, validacija parametara, `proc_open` s array argumentima isključivo, systemd hardening direktive
- Panel na :8443 + opcionalno IP allowlist / VPN-only mod
- Vhost izolacija: zaseban user, zaseban FPM pool, open_basedir, disable_functions, vlastiti tmp
- fail2ban jailovi za panel login, mail, ftp, ssh — panel upravlja configima
- Malware scan: ClamAV + vlastiti PHP webshell heuristički set (eval+base64, gzinflate chainovi, hex obfuskacija — iskustvo s brotherssalon backdoorom)
- AppArmor profili za izložene servise (Ubuntu-native, dolazi uz distro)

---

## 9. AUTO-UPDATE ORKESTRATOR (krunski feature)

Modul `updates` upravlja SVIM komponentama iz tablice u poglavlju 5 + panelom samim + OS-om.

**Mehanika:**
1. Agent svaka 4 h: `apt-get update` + parsiranje `apt list --upgradable` → puni `components`; za major verzije dodatno čita upstream release feedove
2. **Politika per komponenta:** `auto_all` / `auto_security_only` / `manual` / `frozen` + **maintenance window** (default ned 03:00–05:00) + stabilizacijska odgoda N dana od release-a (default 3)
3. **Pre-update snapshot:** tar config direktorija komponente + zapis verzija + za MariaDB/MySQL major: obavezan svjež verificirani backup, inače blokiraj
4. Izvršenje: `apt-get install --only-upgrade` ciljanih paketa, output live u task log (SSE)
5. **Post-update health check:** servis running? config test (`nginx -t`, `apachectl configtest`, `php-fpmX.Y -t`)? HTTP probe panela + sample vhosta? SMTP/IMAP port probe ako mail?
6. Health FAIL → **automatski rollback**: apt downgrade na prethodnu pinned verziju (verzije se čuvaju u `component_updates.rollback_info` + lokalni apt cache paketa se NE čisti prije potvrde zdravlja) + restore config snapshota + alarm e-mail + komponenta → `frozen`
7. **Major verzije** (PHP 8.4→8.5, MariaDB 11→12) NIKAD auto — UI wizard s compatibility checkom
8. **Panel self-update:** stable/beta kanal, atomski deploy (novi release u novi dir → symlink switch → DB migracije s down skriptama), signed release manifest
9. **OS sloj:** `unattended-upgrades` konfiguriran za security-only + Livepatch opcija (Ubuntu Pro token unos u UI — kernel patchevi bez reboota) + "reboot required" indikator s zakazivanjem u maintenance window
10. **Suite watcher:** komponente na `noble` fallback suite-u (poglavlje 4) automatski migriraju na `resolute` kad postane dostupan

---

## 10. BACKUP SUSTAV

- Full + inkrementalni: rsync hardlink strategija za fileove, `mariadb-dump --single-transaction` / `mariabackup` za baze
- Per-vhost ili full-server, raspored, retencija (npr. 7 dnevnih + 4 tjedna + 3 mjesečna)
- Destinacije: lokalno, FTP/SFTP, S3-kompatibilno (AWS/Backblaze/Wasabi/Hetzner)
- **Format = dokumentirani tar + manifest.json** (verzija formata, sadržaj, SHA-256 checksumovi) — restore mora biti izvediv i ručno bez panela (transparentnost!)
- Granularni restore: cijeli vhost / samo fileovi / samo baza / samo mail / samo DNS zona
- Enkripcija AES-256 (ključ kod korisnika — jasno upozorenje da bez ključa nema restorea)
- Backup se izvršava s `ionice`/`nice` + cgroup limitom da ne ubije produkciju

---

## 11. MIGRACIJSKI IMPORTERI

- **cPanel:** parsiranje cpmove/full backup tar strukture (homedir, mysql dumpovi, alias fajlovi, DNS zone, Maildir) → mapiranje na ForgePanel entitete
- **Plesk:** backup XML (`backup_info.xml` + content) → isti mapper
- **Generic live migracija:** SSH credentials izvora → rsync fileova + mysqldump kroz tunel + imapsync za mail
- Svaka migracija: **dry-run mod** (pokaži što će se kreirati), kolizijska provjera, završni izvještaj
- **Export u cPanel-kompatibilnu strukturu** — anti vendor-lock kao filozofija

---

## 12. INSTALLER (one-line, goli Ubuntu 26.04)

```
curl -fsSL https://get.forgepanel.io | bash
```

`installer/install.sh` — mora raditi na MINIMAL serveru (cloud image, samo main/restricted):

1. **Provjere:** `lsb_release` == 26.04 (hard fail na sve drugo u v1), root, čist sustav (postojeći apache/nginx/mysql/panel → abort s objašnjenjem), min 2 GB RAM / 20 GB disk, FQDN hostname postavljen (ili `--hostname` flag ga postavlja), radan DNS resolver, točno vrijeme (chrony/systemd-timesyncd)
2. **Bootstrap repoa:** `add-apt-repository universe` → instalacija baznih alata (curl, gnupg, ca-certificates) → dodavanje SVIH third-party repoa u deb822 formatu s ključevima u `/etc/apt/keyrings/` + fallback suite logika (poglavlje 5) → `apt-get update`
3. **Interaktivni ili `--unattended` mod** (flagovi: `--email`, `--hostname`, `--db=mariadb|mysql`, `--components=web,mail,dns,docker,...` — mail i DNS su opcionalni, mogu se preskočiti i naknadno dodati iz panela)
4. **Panel stack:** MariaDB → DB shema → panel nginx instance (:8443) + PHP-FPM 8.4 pool + `forge-agentd` systemd unit + `fpanel` user
5. **Hosting komponente kroz update orkestrator** od prvog dana (sve verzije odmah pod kontrolom, sve u `components` tablici)
6. **Hardening defaulti:** ufw (22, 80, 443, 8443 + mail portovi ako mail), fail2ban, sysctl preset, AppArmor enforce, SSH: PasswordAuthentication ostaje kako je (NE dirati bez pitanja — korisnik se ne smije zaključati!)
7. **Završetak:** generirana admin lozinka, AutoSSL za panel hostname, ispis URL-a + credentialsa + spremanje u `/root/.forgepanel-credentials` (chmod 600)
8. **Idempotentnost:** ponovno pokretanje detektira instalaciju → repair mod; `--uninstall` flag s potvrdom
9. Cijeli tijek se loggira u `/var/log/forgepanel-install.log`; svaki korak ima checkpoint pa se prekinuta instalacija nastavlja, ne kreće ispočetka

---

## 13. UI / DIZAJN

**Prije pisanja frontend koda postaviti dizajn pitanja jedno po jedno** (boje s preporukom, tipografija, layout vibe, reference) — pa tek onda kod.

Preporučeni smjer (potvrditi):
- **Dark-first** (light kao toggle) — admin alat za duge sesije
- Estetika "precision instrument": gusto ali čitko, monospace za sve tehničke vrijednosti (verzije, IP-ovi, pathovi, logovi), bez SaaS gradijenata i ilustracija
- Tipografija: kvalitetan UI font + mono par (npr. Inter + JetBrains Mono, ili zanimljivija kombinacija — funkcionalne reference: Linear, Grafana, Vercel dashboard, ali NE kopirati)
- Layout: collapsible lijevi sidebar + **command palette (Ctrl+K)** — globalna pretraga domena/akcija/postavki; Plesk i cPanel to nemaju = naš differentiator
- Globalni task tray: živi progress svih pozadinskih taskova; status badge svake komponente (running/stopped/updating)
- **Maksimalna responzivnost** — panel potpuno upotrebljiv s mobitela (hitne intervencije s terena)
- Jedan konzistentan ikonski set (Lucide ili custom SVG), bez stock ikona

---

## 14. ŠTO SVIM PANELIMA FALI — FORGEPANEL DIFFERENTIATORI

Rezultat dubinske analize: stvari koje ni Plesk ni cPanel nemaju, imaju polovično, ili naplaćuju kao add-on. Svaki differentiator je punopravni dio spec-a:

1. **Sve uključeno, bez add-on naplate** — malware/WAF (Imunify360 kod njih je 10+ €/mj), WP toolkit pune funkcionalnosti, backup u cloud — kod nas core featuri
2. **AI asistent (Claude API)** — opcionalan modul `assistant` (admin unosi vlastiti Anthropic API ključ): dijagnostika iz konteksta ("zašto je site spor?" → asistent dobiva relevantne metrike, slow query log, FPM status i error logove pa objašnjava), objašnjenje bilo kojeg config bloka ili log retka jednim klikom, prijedlog fail2ban/nginx pravila iz uočenog napada. Asistent SAMO čita i predlaže — izmjene uvijek potvrđuje čovjek, sve kroz postojeće agent operacije
3. **Config time-machine** — svaka promjena bilo kojeg configa koji panel piše (nginx, FPM, Postfix...) verzionira se (interni git repo nad `/etc` pathovima koje panel kontrolira): diff pregled, "tko je i kada promijenio" (veza na audit_log), one-click restore bilo koje prethodne verzije. Webmin i Plesk nemaju ništa slično
4. **Universal staging** — kloniranje BILO KOJE PHP stranice (ne samo WP kao Plesk): kopija fileova + baze na staging subdomenu s automatskim search-replace i zasebnim FPM poolom, pa "push to production" s diff pregledom
5. **E-mail deliverability suite** — najveća rupa svih panela: RBL/blacklist monitor (dnevna provjera server IP-a na 30+ lista + alarm), **DMARC report parser** (rua izvještaji se primaju na panel mailbox, parsiraju i prikazuju kao graf tko šalje u ime domene), SPF/DKIM/DMARC validator s konkretnim uputama, mail queue pregled s razlozima deferrala
6. **Resource kvote bez CloudLinuxa** — cgroup v2 per-user limiti (poglavlje 4) — cPanel ekvivalent zahtijeva plaćeni CloudLinux
7. **Provisioning API za billing** — REST endpointi `account.create/suspend/unsuspend/terminate/changepackage` + webhook eventi → WHMCS/Blesta modul se piše trivijalno (sam WHMCS modul = Faza 5)
8. **Bulk operacije** — masovna promjena PHP verzije, masovni SSL renew, masovni suspend, masovno gašenje xmlrpc-a — preko checkbox selekcije ili API-ja; Plesk/cPanel sve tjeraju klik-po-klik
9. **Node.js runtime** (modul apps) — Node aplikacije kao systemd useri-servisi per vhost + nginx reverse proxy, verzije preko NodeSource repoa; ekvivalent cPanel Application Managera, ali jednostavniji
10. **Sandboxirani web terminal** — admin: puni terminal; klijent (opcionalno, admin uključuje): shell u privremenom containeru s mountanim SAMO svojim vhost direktorijem i svojim userom — prvi panel s per-klijent terminalom koji ne može vidjeti sustav
11. **Command palette (Ctrl+K)** i potpuna mobilna upotrebljivost — poglavlje UI
12. **Transparentnost** — svaki task pokazuje točno koje je operacije agent izvršio; backup format dokumentiran i ručno restorabilan; izvoz u cPanel strukturu = nema vendor locka

---

## 15. FAZE RAZVOJA

**Faza 1 — MVP:** installer (bootstrap repoa + panel stack), agent + task queue, auth/2FA, websites (nginx+FPM, multi-PHP), AutoSSL, databases + phpMyAdmin, file manager, osnovni monitoring, audit log
**Faza 2:** Apache reverse-proxy mod, mail stack, DNS, FTP, cron, backup sustav, eksterni uptime monitoring
**Faza 3:** update orkestrator s politikama, health checkovima i rollbackom (do tada update manual-only kroz panel), Git deploy, Docker modul
**Faza 4:** migrator (cPanel/Plesk import), apps/WP toolkit + universal staging, reseller role s white-labelom, delegirani pristup, **cloudflare modul**, **security modul (malware skener, karantena, WAF, anomaly detection)**, firewall/fail2ban UI, alarmi, country/bot blocking, deliverability suite, config time-machine, bulk operacije
**Faza 5:** plugin SDK za treće strane, multi-server (jedan panel → agenti na više Ubuntu servera), 2FA hardware keys (WebAuthn), AI asistent modul, Node.js runtime, sandboxirani klijentski terminal, WHMCS provisioning modul

Svaka faza: kod + testovi + dokumentacija prije sljedeće. Funkcionalnost UVIJEK provjeriti prije potvrde da je gotovo.

---

## 16. STRUKTURA REPOZITORIJA

```
forgepanel/
├── installer/
│   ├── install.sh
│   └── repos/            # deb822 .sources predlošci + GPG ključevi
├── agent/
│   ├── bin/forge-agentd
│   ├── src/Operations/   # whitelisted op klase
│   ├── src/System/       # Apt, Systemd, Ufw, Fs helperi
│   └── systemd/forge-agentd.service
├── web/
│   ├── public/           # docroot panela
│   ├── src/Api/
│   ├── src/Core/         # router, auth, db, agent client, SSE
│   └── lang/{hr,en}.json
├── modules/<ime>/        # manifest.json, api/, ui/, operations/
├── database/schema.sql + migrations/
├── docs/                 # OpenAPI spec, backup format spec, arhitektura
└── tests/
```

---

## 17. OPĆA PRAVILA RADA (za Claude Code)

- Push isključivo na **main**; najnovija sintaksa svih jezika; maksimalni fokus na responzivnost
- Bez meta-komentara i dugačkih summaryja; nakon deploya samo commit hash + vrijeme
- Raditi samostalno do kraja, razumne odluke donositi bez pitanja (iznimka: dizajn pitanja na početku frontenda)
- Svaki config koji panel piše: syntax test prije reloada + čuvanje prethodne verzije za rollback
- Agent: `proc_open` s array argumentima, nikad string interpolacija u shell
- UI tekstovi na hrvatskom (lang sustav), europski formati
- Sve testirati na čistom Ubuntu 26.04 cloud image-u (multipass/LXD VM kao test okolina) — installer se smatra ispravnim tek kad prođe na netaknutom minimal serveru
