# ForgePanel — analiza konkurencije i prijedlozi nadogradnji

> Generirano orkestriranom analizom: 14 agenata mapiralo stvarni kod ForgePanela, 8 istraživalo aktualne (2025/2026) značajke konkurentskih panela preko weba, 14 sintetiziralo praznine, + roadmap + completeness kritičar koji je tvrdnje provjeravao protiv repozitorija.

**Obuhvaćeno:** 14 domena · 8 klastera konkurenata · **116 identificiranih praznina**.


**Ključni nalaz:** najveća vrijednost nije u novim featureima nego u zatvaranju jaza između *specifikacije/marketinga* i *stvarne implementacije*. Nekoliko deklariranih differentiatora trenutno je 'iluzija' — postoji shema/UI ali ne i izvršni kod (off-site backup, cgroup kvote, DNSSEC, delegacija, staging koji tiho korumpira WP baze, obavezna 2FA koja se zaobilazi kroz API).


---

## 1. TOP rangirane nadogradnje

| # | Nadogradnja | Effort | Impact | Faza |
|---|---|---|---|---|
| 1 | Off-site backup destinacije (S3/SFTP/FTP) + zakazani backupi + enkripcija | L | high | Faza 2 |
| 2 | Disk project quota (ext4/xfs) + per-vhost cgroup izolacija (zaseban slice) | L | high | Faza 1 |
| 3 | Delegirane permisije i provisioning oversell — zatvaranje sigurnosnih rupa | S | high | Faza 4 |
| 4 | Atomski (zero-downtime) Git deploy s release dirovima i jednoklik rollbackom | M | high | Faza 3 |
| 5 | DNSSEC potpisivanje zona (auto-signing + CDS/CDNSKEY) | L | high | Faza 2 |
| 6 | Anomaly detection na logovima + dinamicki fail2ban jailovi | L | high | Faza 4 |
| 7 | Per-vhost monitoring metrike — ozivljavanje mrtve cgroup read putanje | M | high | Faza 1 |
| 8 | OCSP stapling + TLS hardening (ssl_protocols/ciphers/dhparam) per-vhost | M | high | Faza 1 |
| 9 | Sieve filteri + autoresponder + Dovecot quota enforcement + mail SSL | M | high | Faza 2 |
| 10 | File manager: rename/move/copy + arhiviranje/ekstrakcija + chunked upload | M | high | Faza 1 |
| 11 | Live kernel patching (Ubuntu Pro Livepatch) + reboot-required detekcija | M | high | Faza 3 |
| 12 | Wildcard AutoSSL preko lokalnog BIND DNS-01 | M | high | Faza 2 |
| 13 | Per-vhost cache stack: nginx FastCGI page cache + Redis object cache | L | high | Faza 4 |
| 14 | Centralni log viewer (nginx/Apache/PHP/mail/system, journald, live tail SSE) | L | high | Faza 2 |
| 15 | Universal staging: serialized-aware search-replace + push-to-production | M | high | Faza 4 |
| 16 | Multi-server remote agent dispatch (mTLS bridge) | L | high | Faza 5 |
| 17 | Plugin SDK end-to-end: agent dinamicki ucitava module-registrirane op-codove | L | high | Faza 5 |


### 1. Off-site backup destinacije (S3/SFTP/FTP) + zakazani backupi + enkripcija
*Effort: L · Impact: high · Faza 2*

Backup sustav trenutno radi samo lokalne, on-demand backupe: backup_destinations, backup_schedules i enkripcija postoje u shemi ali ih nijedna linija koda ne cita. Implementirati backup.push (S3 SigV4 + phpseclib SFTP, oba vec u repu), scheduler tick u forge-agentd koji evaluira cron izraze iz backup_schedules, i AES-256-GCM enkripciju s korisnickim kljucem prije slanja off-site.

**Zašto sad:** Egzistencijalna rupa: lokalna kopija nije backup. Disaster recovery, ransomware i GDPR svi zahtijevaju off-site. Tablice i config polja vec postoje (backup_destinations.config enkriptiran preko Crypto.php) — fali samo izvrsni sloj. Bez ovoga 'sve ukljuceno' obecanje pada na prvoj ozbiljnoj havariji.

**Konkurenti koji to imaju:** JetBackup, CloudPanel, CyberPanel, Cloudron, SpinupWP, CWP, Acronis

### 2. Disk project quota (ext4/xfs) + per-vhost cgroup izolacija (zaseban slice)
*Effort: L · Impact: high · Faza 1*

Disk kvota se uopce ne enforcea na FS razini, a cgroup drop-in se pise na dijeljeni phpX.Y-fpm.service s fiksnim imenom forgepanel-quota.conf pa ga svaki sljedeci vhost prepisuje — svi vhostovi te PHP verzije dijele jedan limit. Uvesti Fs::setProjectQuota (projid po vhostu) i zaseban systemd slice/FPM service po vhostu s vlastitim CPUQuota/MemoryMax/TasksMax.

**Zašto sad:** Ovo je SAMA SRZ deklariranog differentiatora 'resource kvote bez CloudLinuxa'. Trenutna implementacija je iluzija: jedan klijent koji podivlja rusi sve ostale na istoj PHP verziji, a disk kvota plana se nigdje ne primjenjuje. Multi-tenant izolacija bez ovoga ne postoji — kriticno za bilo kakav reseller/client model.

**Konkurenti koji to imaju:** cPanel (CloudLinux LVE), CyberPanel, DirectAdmin, HestiaCP, ISPConfig, Enhance

### 3. Delegirane permisije i provisioning oversell — zatvaranje sigurnosnih rupa
*Effort: S · Impact: high · Faza 4*

Tri lokalizirana ali kriticna backend propusta: (1) delegated_access primjenjuje samo 'files' jer GitController/CronController/FtpController/MailController/BackupsController/DatabasesController zovu vhostOr404 bez perm argumenta; (2) ProvisioningController::create/changePackage ne zovu assertResellerCapacity i prihvacaju bilo koji plan_id ignorirajuci owner_user_id (oversell + tudji planovi); (3) Auth.php daje punu sesiju adminu bez TOTP-a (UI-side 2FA enforcement zaobilazi se kroz /api/v1).

**Zašto sad:** Sve tri su sigurnosne rupe s malim, lokaliziranim ispravkom i visokim utjecajem. Delegacija je trenutno marketinska iluzija (samo files radi), reseller moze probiti pul preko API-ja, a obavezna admin 2FA se zaobilazi direktnim API pozivom. Nulta tolerancija na sigurnost (poglavlje 8) zahtijeva da ovo bude rijeseno odmah.

**Konkurenti koji to imaju:** cPanel (Team Manager), Plesk, Enhance, Cloudways, GridPane

### 4. Atomski (zero-downtime) Git deploy s release dirovima i jednoklik rollbackom
*Effort: M · Impact: high · Faza 3*

GitDeploy radi 'git reset --hard' direktno u zivi docroot — vidljiv polu-deployan sajt, nula rollbacka. Preraditi na release model: checkout u releases/<timestamp>-<sha>, post-deploy akcije u release diru, atomski 'ln -sfn current', cuvanje N releaseova (symlink switch obrazac vec postoji u PanelSelfUpdate). Nova op git.rollback + tablica git_deploys za trajnu povijest.

**Zašto sad:** Apsolutni standard svih modernih DevOps panela (Forge, RunCloud, Ploi, Coolify) i prirodno prosirenje vec deklarirane config time-machine filozofije na deploye. In-place deploy je danas neprihvatljiv za produkciju. Live SSE output vec postoji, symlink obrazac vec postoji — srednji napor za feature koji DevOps publika ocekuje kao default.

**Konkurenti koji to imaju:** Laravel Forge, RunCloud, Ploi.io, Moss.sh, Dokploy, Coolify, SpinupWP

### 5. DNSSEC potpisivanje zona (auto-signing + CDS/CDNSKEY)
*Effort: L · Impact: high · Faza 2*

dns_zones.dnssec_enabled je mrtav stub, UI badge nefunkcionalan. Implementirati BIND inline-signing (dnssec-policy), generiranje KSK/ZSK, vracanje DS i CDS/CDNSKEY zapisa, endpoint za prikaz DS-a za registrar, automatski upis DS-a preko CF API-ja u CF modu, key rollover kroz zakazani task.

**Zašto sad:** DNSSEC je danas ocekivani standard svakog ozbiljnog BIND panela (cPanel, Plesk, Virtualmin svi imaju). ForgePanel ima vlastiti BIND9 modul i obecava DNSSEC u spec-u (poglavlje 6) ali isporucuje samo prazan toggle. Kredibilitet DNS modula ovisi o ovome.

**Konkurenti koji to imaju:** cPanel/WHM, Plesk, Webmin/Virtualmin

### 6. Anomaly detection na logovima + dinamicki fail2ban jailovi
*Effort: L · Impact: high · Faza 4*

Spec trazi anomaly detection kao core security feature ali postoji samo staticki nginx-forbidden jail u installeru. Dodati Scheduler task 'anomaly_scan' + AnomalyDetector helper koji tail-a access/error logove, agregira po IP-u i detektira 404 floode, wp-login/xmlrpc brute force, traversal, SQLi/XSS patterne; pri prekoracenju praga agent generira custom fail2ban jail i banira IP. Eventi u novu tablicu security_events s one-click whitelistom.

**Zašto sad:** Ovo je ono sto cPanel/Plesk naplacuju kao Imunify360 (10+ EUR/mj) — ugraditi besplatno je glavni differentiator 'sve ukljuceno'. Trenutno security modul nema proaktivnu obranu, samo reaktivni skener. Brute force i bot napadi su svakodnevica svakog hosta.

**Konkurenti koji to imaju:** Imunify360, BitNinja, cPGuard, CSF/LFD (DirectAdmin), Enhance

### 7. Per-vhost monitoring metrike — ozivljavanje mrtve cgroup read putanje
*Effort: M · Impact: high · Faza 1*

Scheduler::collectMetrics vec cita cgroup v2 ali NIKAD ne pise scope='vhost:<id>', pa MonitoringController::history (koji vec dopusta klijentu regex /^vhost:\d+$/) vraca prazno. Mapirati FPM pool cgroup na vhost_id i pisati monitoring_metrics scope='vhost:<id>'. Klijentski grafovi potrosnje (poglavlje 4) postaju zivi bez novih tablica.

**Zašto sad:** Klijentski dashboard potrosnje vlastitih resursa je eksplicitno obecan u poglavlju 4 i cijela read putanja vec postoji — fali samo write strana. Sinergija s per-vhost cgroup izolacijom (rank 2): kad postoje zasebni sliceovi, per-vhost statistika postaje trivijalna. Niski napor, visoka vidljivost za krajnjeg korisnika.

**Konkurenti koji to imaju:** cPanel (CloudLinux LVE), CyberPanel, Coolify Sentinel, ServerPilot

### 8. OCSP stapling + TLS hardening (ssl_protocols/ciphers/dhparam) per-vhost
*Effort: M · Impact: high · Faza 1*

Grep potvrdjuje 0 pogodaka za ssl_stapling/ssl_protocols/ssl_ciphers/ssl_dhparam u cijelom repu — TLS sigurnost hostanih stranica ovisi iskljucivo o nginx defaultima. Dodati u NginxConf vhost predloske i panel server blok: TLSv1.2/1.3, kurirani cipheri, ssl_stapling on + verify, ssl_dhparam, te 'TLS profile' enum (intermediate/modern) per-vhost s nginx -t + rollbackom.

**Zašto sad:** Sigurnosna higijena koju imaju svi konkurenti (Mozilla Intermediate/Modern profili su de-facto standard). Za panel s 'nulta tolerancija' sigurnosnom filozofijom, oslanjanje na nginx default cipher listu je neprihvatljivo. Srednji napor, izravan utjecaj na sigurnosni rejting svih hostanih domena.

**Konkurenti koji to imaju:** Plesk Obsidian, cPanel/WHM, ISPConfig 3, CloudPanel

### 9. Sieve filteri + autoresponder + Dovecot quota enforcement + mail SSL
*Effort: M · Impact: high · Faza 2*

Mail stack ima vise mrtvih grana: nema server-side Sieve/ManageSieve (Roundcube ga vec cilja na :4190), quota plugin nije ucitan iako mailboxes.quota_bytes postoji, catch-all/suspend kolone spremne ali bez kontrolera, a Postfix/Dovecot serviraju panelov cert (snakeoil/TLS mismatch) umjesto per-hostname AutoSSL certa za mail.<domena>. Ozivjeti sve kroz postojecu DB-as-source-of-truth infrastrukturu + ssl.issue za mail hostname.

**Zašto sad:** Vise S/M ispravaka s velikim kumulativnim ucinkom — vecina infrastrukture vec postoji, fali ozicenje. TLS hostname mismatch znaci da svaki mail klijent spojen na mail.<domena> dobiva upozorenje certa, sto je ozbiljan UX i deliverability problem. Quota bez enforcementa znaci da kvote plana ne vrijede za mail.

**Konkurenti koji to imaju:** cPanel/WHM, Plesk Obsidian, CyberPanel, HestiaCP, Froxlor, Cloudron

### 10. File manager: rename/move/copy + arhiviranje/ekstrakcija + chunked upload
*Effort: M · Impact: high · Faza 1*

Tri velike rupe u file manageru: nema rename/move/copy operacija, nema zip/tar arhiviranja/ekstrakcije, a upload cita cijeli file u memoriju i salje base64 kroz agent socket (~33% overhead, OOM rizik, 120s timeout). Dodati fs.rename/move/copy, fs.archive/extract (sa zip-slip zastitom kroz realpath), i fs.write_chunk/finalize_upload — sve unutar postojece vhostPath izolacije.

**Zašto sad:** File manager je obecan na 'aaPanel razini' (poglavlje 6) ali trenutno ne moze ni preimenovati datoteku. Ovo su osnovne operacije koje korisnici ocekuju svaki dan; chunked upload je nuzan za realne GB uploade. Visok svakodnevni utjecaj, srednji napor, sve kroz postojeci path-izolacijski model.

**Konkurenti koji to imaju:** aaPanel, cPanel/WHM, Plesk Obsidian, CyberPanel, HestiaCP, FastPanel

### 11. Live kernel patching (Ubuntu Pro Livepatch) + reboot-required detekcija
*Effort: M · Impact: high · Faza 3*

Update orkestrator radi apt upgrade ali nikad ne kaze da je reboot potreban niti nudi live kernel patching. Dodati os.livepatch_configure (Pro token enkriptiran kao cloudflare_accounts.api_token, pro attach + enable livepatch), os.livepatch_status, te citanje /var/run/reboot-required u UpdatesScan + os.reboot_schedule u maintenance window.

**Zašto sad:** Reboot produkcijskog servera radi kernel CVE-a je glavna operativna bol hostera — Livepatch je nativni Ubuntu odgovor (poglavlje 9.9 ga eksplicitno trazi). Reboot-required detekcija je trivijalna a danas potpuno nedostaje; korisnik ne zna da treba rebootati nakon glibc/kernel updatea. Prirodno se uklapa u krunski update modul.

**Konkurenti koji to imaju:** Imunify360 (KernelCare), Ubuntu Pro (Livepatch), Webdock, cPanel ekosustav

### 12. Wildcard AutoSSL preko lokalnog BIND DNS-01
*Effort: M · Impact: high · Faza 2*

Jedini Dns01Provider je CloudflareDns — bez CF veze AutoSSL pada na http-01 koji ne moze wildcard. Napisati BindDns01Provider koji upisuje/brise _acme-challenge TXT u lokalnu zonu (rndc reload vec se koristi), SslIssue::resolveProvider bira lokalni BIND kad je zona dns_mode='local'. Omogucuje *.domena cert za sve domene s lokalnim DNS-om.

**Zašto sad:** Spec (poglavlje 5/6) eksplicitno trazi 'wildcard preko DNS-01 kad je DNS lokalni (BIND)' ali wildcard trenutno radi SAMO za Cloudflare domene. Panel ima vlastiti BIND i DNSSEC — apsurd je da ne moze izdati wildcard za vlastite zone. Sinergija s DNSSEC (rank 5) jer oba diraju isti zone-write put.

**Konkurenti koji to imaju:** Plesk Obsidian, cPanel/WHM, DirectAdmin, ISPConfig 3

### 13. Per-vhost cache stack: nginx FastCGI page cache + Redis object cache
*Effort: L · Impact: high · Faza 4*

Redis je u stacku ali samo kao monitorirani servis — nula cache integracije. Dodati cache.toggle per vhost: nginx FastCGI page cache (zona po vhostu, skip pravila za WP admin/cookie), dedicirani Redis socket po vhostu (izolacija + cgroup MemoryMax) + automatski object-cache.php drop-in za WP preko wp-cli. Cache purge endpoint povezan s Git auto-deployem.

**Zašto sad:** 2025/2026 standard svih performansnih panela (RunCloud, SpinupWP, GridPane 3-slojni cache) i izravna podrska ForgePanel cilju performansi + 'sve ukljuceno bez add-ona'. Dramaticno ubrzava WordPress. Per-vhost socket izolacija cini ga multi-tenant sigurnim. Veze se na universal staging i WP toolkit.

**Konkurenti koji to imaju:** RunCloud, SpinupWP, GridPane, CyberPanel, Cloudways, CloudPanel, aaPanel

### 14. Centralni log viewer (nginx/Apache/PHP/mail/system, journald, live tail SSE)
*Effort: L · Impact: high · Faza 2*

Modul 'logs' je potpuno neimplementiran. Agent op logs.tail (journalctl --output=json za whitelistane unite + tail whitelistanih file logova s realpath provjerom), LogsController s filterima (servis/severity/raspon/regex), live tail preko postojeceg Sse.php. Per-vhost error log filtriran po subscription_id. Obrazac citanja logova vec dokazan u docker logs i WafRule::tail.

**Zašto sad:** Log pregled je temeljni alat za dijagnostiku 'zasto je site spor/pao' i preduvjet za AI asistent (poglavlje 14.2 — asistent dobiva error logove). Svi paneli to imaju; ForgePanel modul postoji u spec-u ali nula koda. Visok svakodnevni utjecaj za podrsku i debugiranje.

**Konkurenti koji to imaju:** Plesk, cPanel/WHM, aaPanel, CyberPanel, Coolify, Dokploy, Easypanel

### 15. Universal staging: serialized-aware search-replace + push-to-production
*Effort: M · Impact: high · Faza 4*

StagingClone koristi naivni str_replace(source_domain, staging_domain) koji KORUMPIRA WP serializirane vrijednosti (PHP serialize nosi duljinu stringa). Zamijeniti s 'wp search-replace --all-tables' za WP; dodati staging.push (rsync staging->produkcija + serialized-aware replace + obavezni backup prije) i staging.resync, s rsync --dry-run diff pregledom prije potvrde.

**Zašto sad:** Universal staging je deklarirani differentiator, ali trenutna implementacija TIHO korumpira WordPress baze pri kloniranju (najcesci use-case). To nije gap nego aktivni bug u flagship featureu. Push-to-production zatvara petlju (Plesk Smart Update razina) i cini staging stvarno korisnim umjesto jednosmjernim.

**Konkurenti koji to imaju:** Plesk WP Toolkit, cPanel WP Toolkit, CyberPanel, ServerAvatar, GridPane, Cloudways, Softaculous, Enhance

### 16. Multi-server remote agent dispatch (mTLS bridge)
*Effort: L · Impact: high · Faza 5*

servers tablica + enroll_token postoje ali AgentClient se spaja iskljucivo na lokalni UNIX socket — nijedan task se ne moze poslati na remote node. Implementirati: agent enrollment endpoint (mTLS TCP bridge), AgentClient dobiva server_id i routira lokalno ILI kroz remote tunel, tasks dobiva server_id kolonu, per-server role flagovi (web/db/mail/dns) i ruting taska po roli.

**Zašto sad:** Multi-server je strateski iskorak iz 'jedan server panel' u platformu koja konkurira Enhance (1->10.000+ servera) i ISPConfigu. Temelj (servers tablica, enroll token) vec postoji. Ovo otvara enterprise/hosting trziste i mijenja kategoriju proizvoda — ali ispravno je Faza 5 jer zahtijeva zreo single-server temelj prvo.

**Konkurenti koji to imaju:** Enhance, ISPConfig 3, Coolify, Dokploy, Plesk 360, cPanel/WHM

### 17. Plugin SDK end-to-end: agent dinamicki ucitava module-registrirane op-codove
*Effort: L · Impact: high · Faza 5*

ModuleRegistry (web sloj) validira manifeste i izlaze registeredOperations, ALI agent OperationRegistry je hardkodirani const array koji se nigdje ne puni iz ModuleRegistry — operacija modula se NE moze izvrsiti. Implementirati: agent pri startu skenira modules/*/manifest.json i dinamicki registrira validirane op klase, UI loader za module ui/ isjecke, op-i module.install/enable/disable, + 1 referentni modul kao dokaz.

**Zašto sad:** Cijela 'sve je modul' (Webmin) arhitektura je trenutno polovicna: web sloj zna za module ali ih agent ne moze izvrsiti, a modules/ dir je prazan. Plugin SDK pretvara ForgePanel u platformu za trece strane (Plesk ekosustav model) — strateski temelj za rast izvan core tima. Faza 5 jer prvo treba stabilizirati core op-kodove.

**Konkurenti koji to imaju:** Plesk Obsidian, Webmin/Virtualmin, ISPConfig 3, aaPanel, Webuzo

---

## 2. Brze pobjede (postojeći kod, mali napor)

- Auto-purge Cloudflare cachea nakon Git deploya — kolona cloudflare_zones.auto_purge_on_deploy i CloudflareClient::purgeCache vec postoje, fali samo poziv u GitController post-deploy (S, high)
- Backend-enforced 2FA — ne postavljati twofa_passed=1 adminu bez TOTP-a, izdati ogranicenu setup-only sesiju i odbiti API rute s 403; zatvara obilazak obavezne admin 2FA kroz /api/v1 (S, high)
- Delegirane permisije ozicenje — proslijediti perm argument u vhostOr404 kroz Git/Cron/Ftp/Mail/Backups/Databases kontrolere; bez ovog mini ispravka cijela delegacija je iluzija (S, high)
- Per-DB veicina — db.sizes read-only op (SELECT iz information_schema) + UPSERT u db_databases.size_bytes (kolona postoji) + prikaz na klijentskom dashboardu; periodicki kroz Scheduler (S, high)
- Per-vhost cgroup metrike write — Scheduler::collectMetrics vec cita cgroup, samo treba pisati scope='vhost:<id>'; read putanja i klijentski regex filter vec postoje (M-ali-najvecim dijelom postojeci kod, high)
- stabilizacijska odgoda delay_days — enqueueAutoUpdates ne cita postojecu delay_days kolonu; dodati available_since timestamp i preskociti enqueue dok ne prodje N dana (S, med)
- Live per-record CF proxy toggle (narancasti oblak) — backend CloudflareClient::setProxied + endpoint vec gotovi, fali samo UI ikona u DNS listi (S, low/med)
- Reboot-required detekcija — UpdatesScan cita /var/run/reboot-required + UI indikator + notifikacija; trivijalno a danas potpuno nedostaje (S, high)
- Slack/Discord alarmni kanali — Notifier vec ima siguran sendWebhook (TLS verify), dodati samo platform-specificne payload formattere (S, med)
- Klijentski sandboxirani terminal UI — backend TerminalExec mode=client (docker --network none --read-only) je gotov, terminalSection() se renderira samo za admina; otkljucati za klijenta kad admin ukljuci toggle (S, med)
- FTP promjena lozinke + chmod/chown u UI — dodati PUT ftp/{id}/password (ftp.sync regenerira iz baze) i izloziti postojeci fs.chmod gumb u editoru (S, med)
- Provisioning endpointi u OpenAPI — dodati /provisioning/* paths sa scope provisioning:write i webhook event sheme; preduvjet za vanjske billing module (S, med)
- Prometheus/OpenMetrics /metrics endpoint — read-only export iz monitoring_metrics + live cgroup, zasticen scope metrics:read; podaci vec postoje, ukljucuje ForgePanel u Grafana stackove (S, med)

## 3. Strateški potezi (transformativno)

- Multi-server orkestracija (remote agent mTLS dispatch + server role) — iskorak iz single-server panela u platformu koja konkurira Enhance/ISPConfigu; mijenja kategoriju proizvoda i otvara enterprise hosting trziste. Temelj (servers tablica, enroll token) vec postoji, fali mTLS bridge i ruting po server_id/roli
- Plugin SDK end-to-end (agent dinamicki ucitava module op-codove + UI loader + module.install/enable/disable) — pretvara ForgePanel u ekstenzijsku platformu za trece strane (Plesk ekosustav model), omogucuje rast izvan core tima; trenutno je arhitektura polovicna (web zna za module, agent ih ne izvrsava)
- Anti-ransomware backup trijada: immutable/WORM off-site (S3 Object Lock) + one-click restore ciste pred-infekcijske verzije iz backup povijesti (usporedba checksuma) + real-time inotify malware scan — kombinacija koju NITKO od panela nema u jezgri; pretvara backup+security u jedinstveni differentiator 'cyber resilience'
- Container-native build pipeline (Nixpacks/Dockerfile detekcija) + per-PR preview deployments s auto-teardownom — ulazak u moderni PaaS prostor (Coolify/Dokploy/Vercel-class) gradeci na postojecem StagingClone + git webhook; killer feature za agencije i dev timove
- MCP server nad ForgePanel resursima (read-only: list_vhosts, get_metrics, tail_log, service_status) — pozicionira ForgePanel u agentsku/AI eru (2025/2026 trend), omogucuje Claude Desktop i eksterne agente da citaju kontekst; niska cijena jer su podaci vec izlozeni kroz API, veliki strateski signal
- PostgreSQL podrska + Adminer + per-vhost Redis — otvara ForgePanel modernim Node/Python/AI (pgvector) aplikacijama koje MySQL-only paneli ne opsluzuju; rastuci segment koji Enhance/ISPConfig/Coolify vec ciljaju
- Event-driven automation rule engine ('kad se desi X, napravi Y' bez koda) — pretvara hardkodirani scheduler u korisnicki konfigurabilnu automatizaciju (service down->restart, disk<15%->cleanup, malware->quarantine); zadrzava agent-whitelist sigurnost jer su akcije samo whitelistani op-kodovi

## 4. Učvrsti i proširi moat (gdje je ForgePanel već ispred)

- Config time-machine je ozicen SAMO iz NginxConf — prosiriti ConfigGit::snapshot na SVE writere (ApacheConf, MailConf, BindConf, ProftpdConf, Fail2ban) preko centralnog snapshotAfter() helpera i vezati commit hash na audit_log (tko/kada). Tek tada je obecanje 'svaka promjena bilo kojeg configa se verzionira' istinito i ForgePanel postaje jasan lider — nitko od konkurenata nema diff+restore configa
- Universal staging differentiator trenutno TIHO korumpira WP serializirane baze (naivni str_replace) — hitno zamijeniti serialized-aware wp search-replace i dodati push-to-production s diff pregledom; pretvoriti flagship feature iz buggy demonstracije u stvarnu prednost koja nadmasuje Plesk Smart Update
- Sandboxirani per-klijent terminal (poglavlje 14.10, 'prvi panel s terminalom koji ne vidi sustav') je backend-kompletan ali bez UI ulaza za klijenta — otkljucati ga (S effort) da se realizira vec napisana prednost; zatim nadograditi na interaktivni PTY/SSE stream za kolaborativni mod (Forge razina)
- AI asistent je trenutno samo rucni hand-off — prosiriti u proaktivni sloj: scheduler 'ai_insights' salje agregirane metrike/trendove asistentu i sprema preporuke, plus 'SmartFix' UX gumb koji predlaze KONKRETNU whitelistanu op (human-in-the-loop). Dovodi ForgePanel na Cloudways Copilot razinu uz zadrzanu 'asistent samo cita i predlaze' filozofiju
- Transparentnost + anti-vendor-lock: isporuciti deklarirani ali neimplementirani export u cPanel-kompatibilnu cpmove strukturu (obrnuti mapper od MigratorCpanelParse) — pretvara filozofsko obecanje u opipljivu prodajnu prednost protiv lock-in straha
- 'Sve ukljuceno bez add-ona' moat siri se svakim besplatno ugradjenim Imunify360-ekvivalentom: anomaly detection, virtualni WP patching, malware karantena s clean-restore — svaki je feature koji konkurenti naplacuju 10+ EUR/mj; prioritizirati ih jer izravno hrane glavni marketinski differentiator
- Resource kvote bez CloudLinuxa su trenutno iluzija (dijeljeni cgroup, nula disk enforcementa) — popraviti per-vhost slice + project quota cini ovaj differentiator STVARNIM; bez toga je najjace marketinsko obecanje neistinito i izlozeno demoliranju u usporedbama

---

## 5. Kategorije koje konkurenti imaju — a izvršni plan podcjenjuje (kritičar)

- Object cache layer specifično za Memcached (ne samo Redis) — roadmap pokriva Redis object cache (rank 13) i per-vhost Redis, ali Memcached kao alternativni backend (koji dio WP/Magento stackova traži) nigdje se ne spominje; manje kritično jer Redis pokriva većinu, ali kategorija 'object cache izbor backenda' je polovična.
- SSO/SAML/OIDC kao IDENTITY PROVIDER za hostane aplikacije I kao SP za panel login — roadmap spominje OIDC samo usput unutar 'team/sub-account' bacanja (Faza 5, gap o team modelu) i kao Cloudron-uzor u istraživanju, ali NEMA samostalne stavke za: (a) SAML/OIDC login U SAM PANEL (enterprise zahtjev — Azure AD/Google Workspace SSO za administratore/resellere), niti (b) ForgePanel kao OIDC provider s upravljanjem klijentima/scope-ovima. Provjereno: 0 saml/oidc/openid pogodaka u PHP kodu. Cijela enterprise SSO kategorija je nepokrivena kao prvorazredni feature.
- HA / clustering / failover / load balancing — roadmap ima multi-server dispatch (rank 16) ali to je orkestracija, NE visoka dostupnost. Nedostaje: floating IP/keepalived failover, master-master ili Galera DB klaster, shared/replicirani storage za vhostove, mail/DNS replikacija između nodova, load balancer rola. ISPConfig (mirror/failover) i Enhance to nude. Provjereno: 0 failover/keepalived/galera/replication pogodaka. Multi-server postoji kao registar+dispatch vizija, ali HA kao kategorija potpuno izostaje.
- CDN / edge integracija izvan Cloudflarea — cijela integracija je hardkodirana na Cloudflare (39 referenci u CloudflareController). Nema apstrakcije za druge CDN/edge providere (Bunny.net, Fastly, KeyCDN, AWS CloudFront), niti generičkog 'CDN origin pull' modela. Za panel koji cilja anti-vendor-lock, vezanost isključivo uz CF je strateška rupa; multi-CDN ili barem Bunny (popularan kod EU hostera) nije ni u roadmapu ni u kodu.
- Email autoconfig/autodiscover/mobileconfig — iako PRAZNINE dokument ima ovu stavku unutar mail domene, NIJEDNA od 17 top_upgrades ni quick_wins je ne uključuje, pa u prioritiziranom roadmapu (phased_plan) efektivno ispada. Provjereno: 0 autodiscover/autoconfig/mobileconfig pogodaka. Automatsko podešavanje mail klijenata je osnovna očekivana značajka koja u izvršnom planu nedostaje.
- Process/queue worker manager (Supervisor UI) za long-running procese — Laravel/Node queue workeri, websocket procesi, daemoni. ServerAvatar/Forge/Ploi to imaju. Roadmap spominje Node/Python runtime ali ne perzistentni worker manager s auto-restartom i log pregledom. Kategorija 'background worker management' nepokrivena.
- Billing-native (ugrađena naplata/fakturiranje) — roadmap ima provisioning API + WHMCS/FOSSBilling adapter (Faza 5), ali to je INTEGRACIJA s vanjskim billingom, ne native billing. Paneli poput Enhance grade prema vlastitom billingu; 'billing-native' kao kategorija (cjenici, fakture, naplata kartica, suspenzija zbog neplaćanja unutar panela) svjesno je izvan scope-a — što je legitimna odluka, ali kategorija ostaje nepokrivena i to treba eksplicitno reći.
- PITR / point-in-time recovery i fizički DB backup (mariabackup + binlog) — PRAZNINE to imaju kao stavku u DB domeni, ali NIJEDAN top_upgrade ni phased_plan stavka je ne uključuje; backup roadmap staje na off-site+inkrementalni+immutable, bez vremenske preciznosti restorea baza. Za ozbiljan DR ovo je rupa koja u izvršnom planu izostaje.
- 2FA recovery/backup kodovi i WebAuthn UI tok — schema ima webauthn_credentials i twofa_secret, roadmap spominje WebAuthn hardware keys (Faza 5) i backend-enforced 2FA (quick win), ali NIGDJE recovery/backup kodove (lockout oporavak kad korisnik izgubi TOTP uređaj). Provjereno: 0 recovery.code/backup.code pogodaka. Bez recovery koda obavezni 2FA = rizik trajnog zaključavanja računa.
- DDoS zaštita / rate limiting na razini edge/L7 (izvan fail2ban) — anomaly detection + fail2ban (rank 6) pokriva log-based banove, ali nema nginx rate limiting zona (limit_req/limit_conn) per-vhost, ni connection flood zaštite na L4/L7. Webdock to reklamira kao uključeno. Kategorija 'volumetrijska/L7 DDoS mitigacija' nepokrivena u kodu i roadmapu.

### Dodatne preporučene nadogradnje (kritičar)

- **SAML/OIDC SSO login U SAM PANEL (admin/reseller enterprise federacija)** — Roadmap tretira OIDC samo kao buduću provider-ulogu za hostane aplikacije, ali potpuno izostavlja federirani LOGIN u sam panel preko Azure AD/Google Workspace/Okta — što je tvrdi zahtjev svakog hostera s timom ili enterprise klijentom. Provjereno: 0 saml/oidc pogodaka u kodu. Visok strateški utjecaj za B2B/reseller prodaju, srednji napor (Core\\SamlSp + OidcRp uz postojeći sessions model), i prirodno se veže na već postojeću 2FA/role infrastrukturu.
- **2FA recovery/backup kodovi (anti-lockout) kao DIO backend-enforced 2FA quick wina** — Roadmap forsira obaveznu admin 2FA (quick win), ali bez recovery kodova to izravno stvara rizik trajnog zaključavanja kad admin izgubi TOTP uređaj — što će u praksi blokirati rollout obavezne 2FA. Trivijalan dodatak (tablica recovery_codes, hash + jednokratna potrošnja), ali kritičan preduvjet da se enforced 2FA uopće smije upaliti. Mora ići u isti paket, ne kao zaseban kasniji feature.
- **Per-vhost nginx rate limiting (limit_req/limit_conn zone) kao temelj L7 anti-abuse** — Anomaly detection (rank 6) reagira POST-FACTUM banom preko fail2ban, ali ne sprječava sam flood u trenutku napada. Nativne nginx limit_req/limit_conn zone per-vhost (whitelisted snippet, isti nginx -t+rollback obrazac kao custom direktive) daju proaktivnu L7 zaštitu uz nizak napor i sinergiju s anomaly modulom. Bez ovoga 'sve uključeno' security priča nema osnovni rate-limit sloj koji svi ozbiljni stackovi imaju.
- **PITR/binlog DB backup uvršten u izvršni (phased) plan, ne samo u sirovi gap popis** — PITR i fizički mariabackup postoje kao PRAZNINE stavka ali su ispali iz svih 17 top_upgrades i iz phased_plana — efektivno nevidljivi u prioritetima. Za panel koji obećava ozbiljan DR i čiji update orkestrator (pogl. 9) traži verificiran backup prije DB major upgradea, gubitak transakcija od zadnjeg logičkog dumpa je realna rupa. Treba ga eksplicitno uvrstiti u Fazu 2/5 backup pravac.
- **CDN apstrakcija (barem Bunny.net uz Cloudflare) za anti-vendor-lock** — Cijela edge/CDN priča je hardkodirana na Cloudflare (39 ref). To proturječi deklariranoj anti-vendor-lock filozofiji panela. Uvođenje tankog CDN-provider sučelja (origin pull + cache purge + real-IP) s Bunny.net kao drugim implementacijom (popularan kod EU hostera, jeftin, jednostavan API) širi differentiator i smanjuje ovisnost o jednom vendoru uz srednji napor.
- **Memcached kao alternativni object-cache backend uz Redis** — Roadmap bira isključivo Redis za object cache (rank 13). Dio realnih WordPress/Magento i legacy stackova traži Memcached (W3TC, neki plugini). Dodavanje Memcached opcije u isti per-vhost cache toggle (zaseban socket, cgroup MemoryMax) je mali inkrement koji pokriva kategoriju 'izbor cache backenda' i izbjegava da klijenti koji traže Memcached odu konkurenciji.
- **Email autoconfig/autodiscover/mobileconfig podignut iz gap popisa u Fazu 2 izvršnog plana** — Automatsko podešavanje mail klijenata (Outlook autodiscover, Thunderbird autoconfig, Apple .mobileconfig) postoji u PRAZNINAMA ali ni u jednom top_upgradeu/quick_winu/phased planu — pa praktički ne postoji u prioritetima. To je osnovna UX značajka koju korisnici očekuju (unesi email+lozinku, klijent se sam podesi) i koja izravno smanjuje support teret; čist PHP (XML generatori + DNS zapisi), nizak napor, treba biti uz mail SSL fix u Fazi 2.
- **Supervisor-style worker manager (Laravel/Node queue, websocket daemoni) u apps/cron modulu** — Node/Python runtime postoji u planu, ali perzistentni background workeri (Laravel queue:work, Node websocket procesi) nemaju UI/menadžer s auto-restartom i log pregledom — što Forge/Ploi/ServerAvatar nude kao standard za dev/agencijsku publiku koju panel cilja Git-deployem. Modelirati kroz per-vhost systemd template servise (već postoji obrazac kod NodeApp), srednji napor, popunjava očekivanu rupu DevOps workflowa.

**Verdikt kritičara:** Roadmap je iznimno temeljit i, što je rijetko, vjerno utemeljen na stvarnom kodu — provjerom repozitorija potvrdio sam ključne tvrdnje (mrtve sheme backup_destinations/backup_schedules, dijeljeni cgroup drop-in s fiksnim imenom, delegacija samo za 'files', odsutnost ssl_stapling/object cache/PostgreSQL/MTA-STS/Nixpacks/SAML-OIDC). Prioritizacija je razborita: kritični 'iluzija differentiatora' popravci (kvote bez CloudLinuxa, delegacija, staging koji tiho korumpira WP baze, backend-enforced 2FA) ispravno su gore. Većina kategorija iz pitanja JE pokrivena: object cache/Redis (rank 13), PostgreSQL (strategic bet), MTA-STS/ARC/BIMI (mail gap), preview deployments + Nixpacks (strategic bet/gap), IaC/blueprints (gap), container registry (docker gap), status pages — ZAPRAVO VEĆ IMPLEMENTIRAN (StatusController postoji, pa je to lažni gap ako ga netko navede kao prazninu). Glavne istinske rupe koje roadmap podcjenjuje ili izostavlja: (1) ENTERPRISE SSO/SAML LOGIN u sam panel — OIDC se spominje samo kao buduća provider-uloga, ne kao federirani admin login; (2) HA/clustering/failover — multi-server je samo dispatch, ne visoka dostupnost; (3) izvršni plan ispušta nekoliko realnih gapova koji postoje u sirovom popisu ali ne u nijednom top_upgradeu/phased planu (PITR/binlog DB backup, autoconfig/autodiscover, 2FA recovery kodovi); (4) CDN je 100% vezan uz Cloudflare što proturječi anti-vendor-lock filozofiji; (5) nema L7 rate limiting/DDoS sloja izvan reaktivnog fail2bana. Memcached i Supervisor worker manager su sekundarne ali legitimne rupe. 'Billing-native' kao kategorija je svjesno izvan scope-a (samo provisioning API) — to treba eksplicitno priznati, ne tiho izostaviti. Ukupno: roadmap je proizvodno upotrebljiv kakav jest; predložene dopune ga zatvaraju prema enterprise/HA segmentu i sprječavaju da nekoliko stvarnih gapova 'iscuri' iz prioriteta u sirovi popis.


---

## 6. Fazni plan (usklađen s CLAUDE.md)


### Faza 1 — MVP (kriticni temelji, vecinom postojeci kod ozivjeti)
- Disk project quota (ext4/xfs) + per-vhost cgroup slice izolacija — srz 'kvote bez CloudLinuxa', trenutno iluzija
- Per-vhost monitoring metrike — pisati scope='vhost:<id>' (read putanja i klijentski filter vec postoje)
- OCSP stapling + TLS hardening per-vhost (intermediate/modern profil) — 0 pogodaka u repu danas
- File manager: rename/move/copy + zip/tar arhiviranje/ekstrakcija + chunked upload (anti-OOM)
- Backend-enforced 2FA — zatvoriti obilazak obavezne admin 2FA kroz /api/v1 (quick win)

### Faza 2 — Mail, DNS, FTP, cron, backup, uptime
- Off-site backup destinacije (S3/SFTP/FTP) + zakazani backupi (scheduler tick) + AES-256 enkripcija
- DNSSEC potpisivanje zona (auto-signing + CDS/CDNSKEY) — mrtav stub danas
- Wildcard AutoSSL preko lokalnog BIND DNS-01 + mail per-hostname SSL (ukloniti snakeoil/TLS mismatch)
- Sieve filteri + autoresponder + Dovecot quota enforcement + catch-all/suspend (mrtve grane ozivjeti)
- Centralni log viewer (journald + file logovi, live tail SSE) — modul 'logs' nula koda
- CRUD za uptime probe + povijest eventa + response-time graf (engine postoji, UI/API ne)
- Inkrementalni backup (rsync --link-dest) + sira granularnost (mail/DNS) + napredna retencija

### Faza 3 — Update orkestrator, Git deploy, Docker
- Atomski (zero-downtime) Git deploy s release dirovima + jednoklik rollback (git_deploys tablica)
- Live kernel patching (Ubuntu Pro Livepatch) + reboot-required detekcija + zakazani reboot u maintenance window
- Funkcionalne post-update probe (HTTP panel/vhost + SMTP/IMAP port) + obavezan verificiran backup prije DB major upgradea
- stabilizacijska odgoda delay_days enforcement (postojeca shema, fali citanje)
- Auto-healing watchdog za kriticne servise (nginx/php-fpm/mariadb/postfix)
- Docker Compose import + named volumes + privatni registry login
- Auto-purge CF cachea nakon deploya (quick win) + Slack/Discord alarmni kanali (quick win)

### Faza 4 — Migrator, security, reseller, cloudflare, deliverability
- Anomaly detection na logovima + dinamicki fail2ban jailovi (Imunify360-ekvivalent besplatno)
- Per-vhost cache stack (nginx FastCGI page + Redis object cache) + WP integracija
- Universal staging: serialized-aware search-replace + push-to-production (popraviti flagship bug)
- WP Toolkit dubina (plugin/tema management, vuln skener, smart auto-update s rollbackom)
- One-click clean-restore iz pred-infekcijskog backupa + real-time inotify malware scan + zakazani scanovi
- CF Real Visitor IP (set_real_ip_from) + CF-only firewall lockdown + dvosmjerna CF sync
- Config time-machine na SVE writere (ApacheConf/MailConf/BindConf/ProftpdConf/Fail2ban)
- Delegirane permisije ozicenje + UI (quick win backend) + provisioning oversell fix
- Deliverability suite: MTA-STS/TLS-RPT/ARC + DMARC parser + RBL monitor + autodiscover/autoconfig
- Bulk operacije + Security Advisor pregled s one-click fix akcijama

### Faza 5 — Platforma: multi-server, plugin SDK, AI, billing
- Multi-server remote agent dispatch (mTLS bridge + per-server role + tasks.server_id ruting)
- Plugin SDK end-to-end (agent dinamicki ucitava module op-codove + UI loader + module.install/enable/disable + 1 referentni modul)
- WHMCS provisioning modul + FOSSBilling adapter + OpenAPI pokritost svih 227 ruta
- Container-native build pipeline (Nixpacks/Dockerfile) + per-PR preview deployments s auto-teardownom
- MCP server nad ForgePanel resursima (read-only) + proaktivni AI insights + SmartFix UX
- Event-driven automation rule engine + IaC blueprint export/apply + forge-cli (PHAR klijent)
- PostgreSQL podrska + Adminer + immutable/WORM off-site backup + full-server backup
- Team/sub-account model + OIDC provider + WebAuthn hardware keys + interaktivni PTY web terminal

---

## 7. Dodatak — sve praznine po domenama (116)


### Web stranice / vhostovi / multi-PHP / web server stack (nginx/Apache/LiteSpeed)

- **Custom nginx/Apache direktive po vhostu iz whiteliste (s nginx -t/configtest validacijom i rollbackom)** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Per-app/per-vhost editor nginx/Apache konfiguracije za rewrites, redirecte, reverse proxy i proizvoljne direktive; CapRover ima custom nginx config override po appu, CloudPanel integrirani Vhost Editor, DirectAdmin/HestiaCP vhost templating, Webmin/Virtualmin direktni config editor. Plesk dopušta 'Additional nginx/Apache directives' po domeni.
  - Tko: CloudPanel, CapRover, DirectAdmin, HestiaCP, Plesk, Webmin/Virtualmin, ISPConfig
  - Prijedlog: Novi agent op 'vhost.directives_set' (params: vhost_id, validirani blok direktiva iz whiteliste regex pravila po direktivi — npr. add_header, location ~ static patterns, client_max_body_size, rewrite). Agent zapisuje u /etc/nginx/forgepanel/snippets/<domain>.custom.conf koji se include-a u server blok PRIJE security.conf, pa nginx -t / apachectl configtest s automatskim rollbackom (mehanizam već postoji u NginxConf::writeAndReload) + ConfigGit snapshot. Tablica config_versions već hvata povijest. UI: monospace editor s prikazom dozvoljenih direktiva. Ovo je istaknuta spec značajka (poglavlje 6: 'custom direktive s rollbackom', 'per-vhost nginx snippeti iz whiteliste') koja trenutno ne postoji.
  - Mapiranje: modules: websites · tables: config_versions, vhosts · operations: vhost.directives_set
- **Ugrađeni page/object cache toggle po vhostu (nginx FastCGI cache + Redis object cache za WordPress)** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Cache na razini web servera (ne samo PHP plugin) s jednim toggleom i automatskom WP integracijom: nginx FastCGI/proxy page cache + Redis object cache + OPcache, često 3-slojno kombinabilno s CDN-om. RunCloud Hub, SpinupWP, GridPane (3 sloja), CyberPanel LSCache, Cloudways Breeze/Relay (object cache u PHP memoriji), aaPanel.
  - Tko: RunCloud, SpinupWP, GridPane, CyberPanel, Cloudways, CloudPanel, aaPanel
  - Prijedlog: Dodati u websites modul 'cache' sekciju po vhostu: (1) nginx FastCGI page cache preset (fastcgi_cache zona po vhostu, skip-cache pravila za WP admin/cookie/query, purge endpoint) preko nove agent op 'vhost.cache_set' (params: mode off/page/page+object, ttl); (2) Redis object cache — agent instalira/konfigurira redis (universe), per-vhost prefix, za WP automatski drop in object-cache.php preko wp-cli. Stupac vhosts.cache_mode + cache_config JSON. Purge cachea jednim klikom (i auto-purge nakon Git deploya, već postoji cloudflare auto_purge pattern). Direktno podupire ForgePanel cilj performansi i 'sve uključeno bez add-ona'.
  - Mapiranje: modules: websites, apps · tables: vhosts · operations: vhost.cache_set, vhost.cache_purge
- **HTTP/3 (QUIC) toggle po vhostu** `[S/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Native HTTP/3 iz kutije ili kao per-vhost toggle. CyberPanel native HTTP/3, ServerPilot HTTP/3 s HTTP/2 fallbackom, RunCloud, CloudPanel (Ubuntu 24/Debian 12), SpinupWP roadmap, CapRover (UDP port mapping).
  - Tko: CyberPanel, ServerPilot, RunCloud, CloudPanel, CapRover
  - Prijedlog: Proširiti NginxConf templates da uz 'http2 on;' dodaju 'listen 443 quic reuseport;' + 'add_header Alt-Svc' kad je vhost.http3=1; otvoriti UDP/443 u ufw profilu kroz postojeću Firewall operaciju. Stupac vhosts.http3 (TINYINT). Toggle u UI Sites detalju. Eksplicitno tražen u spec poglavlju 6 i potvrđen kao must-have kod gotovo svih konkurenata; trenutno 0 pogodaka u kodu.
  - Mapiranje: modules: websites, firewall · tables: vhosts · operations: vhost.create, vhost.backend_set
- **Brotli i gzip kompresija (per-vhost ili globalni preset)** `[S/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Standardna gzip/brotli kompresija odgovora kao default ili toggle; svi ozbiljni stackovi je imaju iz kutije.
  - Tko: Plesk, cPanel, CyberPanel, CloudPanel, DirectAdmin, HestiaCP
  - Prijedlog: U installeru dodati globalni nginx http-blok preset (gzip on s razumnim gzip_types, te brotli on ako je dostupan ngx_brotli modul iz nginx.org dinamičkih modula) u /etc/nginx/forgepanel/compression.conf, include-an u svaki vhost template. Opcionalni per-vhost override (vhosts.compression JSON) za isključivanje. Trivijalan ali vidljiv quick-win; eksplicitno u spec poglavlju 6, trenutno 0 pogodaka.
  - Mapiranje: modules: websites · tables: vhosts · operations: vhost.create
- **Upravljanje vhost aliasima (dodatne domene/server_name na isti vhost)** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Dodavanje više domena/aliasa (ServerAlias / dodatni server_name) na isti vhost iz UI-ja kao prvorazredna funkcija; standardno u svim panelima ('Aliasi'/'Domain aliases'/'Parked domains').
  - Tko: Plesk, cPanel, DirectAdmin, HestiaCP, ISPConfig, Webuzo
  - Prijedlog: Tablica vhost_aliases već postoji i alias_count se prikazuje, ali nema rute ni operacije. Dodati API rute /vhosts/{id}/aliases (GET/POST/DELETE) u VhostsController i novu agent op 'vhost.alias_set' koja regenerira server_name (nginx) / ServerAlias (Apache) iz svih redova vhost_aliases + hardkodirani www, pokrene nginx -t/configtest s rollbackom i okine AutoSSL re-issue da SAN pokrije nove aliase. Time alias_count postaje stvaran umjesto uvijek 0.
  - Mapiranje: modules: websites, ssl · tables: vhost_aliases, ssl_certs · operations: vhost.alias_set, ssl.issue
- **Puni set security headera + strogi CSP/HSTS na hostanim vhostovima (Security Advisor / one-click hardening)** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Plesk Security Advisor daje prioritizirane preporuke i jednoklik hardening (SSL na sve, HTTP/2, headeri); paneli nude HSTS/CSP/Referrer-Policy/Permissions-Policy presete po domeni. ForgePanel spec sam traži 'puni set security headera' i 'strogi CSP'.
  - Tko: Plesk, cPanel, Enhance, aaPanel
  - Prijedlog: Trenutni security.conf ima samo X-Content-Type-Options i X-Frame-Options. Proširiti na konfigurabilni per-vhost security headers preset (HSTS s max-age/preload toggle, Referrer-Policy, Permissions-Policy, opcionalni CSP s report-only modom) preko agent op 'vhost.headers_set' (vhosts.security_headers JSON), s validacijom i rollbackom. Nadograditi u mini 'Security Advisor' pogled koji listira koje domene nemaju HSTS/SSL/HTTP2 i nudi bulk apply (uklapa se u bulk modul). Jača i differentiator 'sve uključeno' i sigurnosnu poziciju.
  - Mapiranje: modules: websites, security, bulk · tables: vhosts · operations: vhost.headers_set
- **Maintenance mode jednim klikom po vhostu (čista stranica umjesto 503/errora)** `[S/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Toggle koji servira čistu 'održavanje u tijeku' stranicu (HTTP 503 s Retry-After) umjesto greške tijekom deploya/updatea; često s allowlistom admin IP-a da vlasnik i dalje vidi pravi site.
  - Tko: RunCloud, Plesk (WP Toolkit), cPanel (WP Toolkit), GridPane
  - Prijedlog: Postoji suspend (503 placeholder) ali to je administrativna blokada, ne korisnički maintenance mode. Dodati agent op 'vhost.maintenance' (params: on/off, opcionalni allow_ips, custom HTML) koja u nginx server blok ubacuje uvjetni return 503 s named location na maintenance.html, uz 'allow' za admin IP-ove. Stupac vhosts.maintenance (TINYINT). Korisno samostalno i kao auto-wrap oko Git deploya (modul git). Razlika od suspenda: vlasnik ga sam pali/gasi, čuva return 503 + Retry-After za SEO.
  - Mapiranje: modules: websites, git · tables: vhosts · operations: vhost.maintenance
- **Atomski (zero-downtime) deploy s keep-N-releases i jednoklik rollbackom** `[L/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Release dir -> symlink switch kao DEFAULT, čuvanje N prethodnih releasea, instant rollback na bilo koji prethodni release, real-time deploy log. Standard kod svih modernih DevOps panela.
  - Tko: Laravel Forge, RunCloud, Ploi.io, Dokploy, Coolify, SpinupWP, Moss.sh
  - Prijedlog: GitDeploy trenutno radi in-place deploy. Nadograditi na atomski model: agent op 'git.deploy' klonira/buildira u releases/<timestamp>, izvrši post-deploy whitelistane akcije, pa atomski prebaci symlink current->release i reload FPM; čuva keep_releases (default 5). Nova op 'git.rollback' (params: release_id) prebacuje symlink natrag. Tablica git_repos dobiva keep_releases; nova tablica git_releases (repo_id, path, commit, deployed_at, active). Deploy log već ide preko SSE TaskQueue. Uklapa se u 'config time-machine' filozofiju proširenu na deploye.
  - Mapiranje: modules: git, websites · tables: git_repos, git_releases · operations: git.deploy, git.rollback
- **Per-verzija PHP ekstenzije i instalacija PHP verzije na zahtjev iz UI-ja** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Globalno upravljanje koje su PHP verzije i ekstenzije instalirane (cPanel EasyApache 4) odvojeno od per-domena dodjele (MultiPHP); ServerAvatar nudi per-PHP-verzija upravljanje ekstenzijama i manualnu instalaciju verzije bez čekanja. TuxCare/ELS pristup za EOL verzije.
  - Tko: cPanel, ServerAvatar, DirectAdmin, Plesk, CWP
  - Prijedlog: Multi-PHP prebacivanje verzije radi, ali nema UI za (1) instalaciju/uklanjanje pojedine PHP verzije na server na zahtjev i (2) upravljanje per-verzija ekstenzijama (php8.x-redis, -imagick, -intl...). Dodati agent op 'php.version_manage' (install/remove verzije iz ondrej PPA preko Apt helpera) i 'php.ext_manage' (apt install/enable po verziji, pa reload poola). Registar u components tablici (već prati pakete). UI: matrica verzija x ekstenzije. Dvoslojni EasyApache-stil model (server-wide enable vs per-domena dodjela) koji konkurenti imaju jasno odvojen.
  - Mapiranje: modules: websites, updates · tables: components, vhosts · operations: php.version_manage, php.ext_manage

### DNS (BIND/DNSSEC) i Cloudflare integracija

- **DNSSEC potpisivanje zona (auto-signing + CDS/CDNSKEY)** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: cPanel nudi puni DNSSEC s NSEC3 bez salta po RFC 9276, generiranje DS/CDS/CDNSKEY zapisa i one-click enable po zoni. Plesk i Virtualmin (BIND) potpisuju zone i izvoze DS zapis za registrar. To je danas očekivani standard kod svakog ozbiljnog BIND panela.
  - Tko: cPanel/WHM, Plesk, Webmin/Virtualmin
  - Prijedlog: Implementirati stvarni DNSSEC: nova agent operacija dns.dnssec_enable/disable koja koristi BIND inline-signing (dnssec-policy default ili dnssec-keygen + dnssec-signzone), generira KSK/ZSK u /var/lib/forgepanel/dnssec/<zona>/, dodaje 'dnssec-policy' u zone clause u BindConf::regenerateConf i puni postojeću kolonu dns_zones.dnssec_enabled (sada mrtav stub). Agent vraća DS i CDS/CDNSKEY zapise; novi DnsController endpoint GET /dns/zones/{id}/dnssec/ds prikazuje DS za kopiranje u registrar, a kod Cloudflare moda zapisuje DS preko CF API-ja automatski. Key rollover preko zakazanog taska. UI badge (app.js:2985) postaje funkcionalan.
  - Mapiranje: modules: dns · tables: dns_zones, tasks, audit_log · operations: dns.dnssec_enable, dns.dnssec_disable, dns.dnssec_rollover
- **Sekundarni (slave) DNS i AXFR sinkronizacija** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: ISPConfig ima zreo distribuirani DNS s automatskim slave zonama i AXFR sinkronizacijom između DNS servera; Webmin/Virtualmin podržava slave zone, allow-transfer i also-notify. Sekundarni NS je preduvjet za redundantni DNS koji hosteri traže.
  - Tko: ISPConfig, Webmin/Virtualmin, cPanel/WHM
  - Prijedlog: Proširiti BindConf::regenerateConf da po zoni piše 'allow-transfer { <slave_ips>; }' i 'also-notify { <slave_ips>; }', te podržati 'type slave' (masters {...}) za zone gdje je ForgePanel sekundarni. Nova kolona dns_zones.zone_role ENUM('master','slave') + dns_zones.transfer_acl (JSON IP liste) + master_ips. Agent operacija dns.slave_add/dns.transfer_set. TSIG ključ po transfer paru za autentificirani AXFR. UI u dns modulu: po zoni 'Sekundarni serveri' s IP listom; za multi-server fazu 5 ovo postaje temelj DNS klastera (Enhance-style DNS rola).
  - Mapiranje: modules: dns, servers · tables: dns_zones, settings · operations: dns.slave_add, dns.transfer_set, dns.zone_write
- **Cloudflare Real Visitor IP (set_real_ip_from) — mrtav kod** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Bilo koji panel s CF integracijom rekonstruira pravi posjetiteljev IP iz CF-Connecting-IP zaglavlja konfiguriranjem nginx set_real_ip_from s CF rangevima, da fail2ban, logovi i WAF vide stvarni IP, a ne CF edge. Bez toga svi banovi i statistika su beskorisni iza proxyja.
  - Tko: CWP (CF sync modul), Plesk (Cloudflare ext), cPanel (CF plugin)
  - Prijedlog: Spojiti postojeći CloudflareClient::ipRanges() (trenutno se nigdje ne poziva) u stvarni workflow: nova agent operacija cf.realip_sync koja dohvaća CF IPv4/IPv6 rangeve, generira /etc/nginx/conf.d/forgepanel-cf-realip.conf (set_real_ip_from po rangu + real_ip_header CF-Connecting-IP), radi nginx -t prije reloada s rollbackom (isti obrazac kao BindConf). Dnevni zakazani task osvježava listu. Per-vhost toggle u cloudflare modulu 'iza CF proxyja' koji uključuje snippet u server block. Tako fail2ban/logs/WAF vide prave IP-ove.
  - Mapiranje: modules: cloudflare, websites, firewall · tables: cloudflare_zones, settings, tasks · operations: cf.realip_sync
- **Firewall sinergija: samo Cloudflare smije na 80/443** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: CF-integrirani paneli nude ufw/iptables allowlist isključivo CF rangeva na portovima 80/443 za proxied domene, čime se origin skriva i blokira direktan napad zaobilaženjem CF-a (origin IP leak zaštita).
  - Tko: CWP, aaPanel, BitNinja (CDN-aware)
  - Prijedlog: Iskoristiti CloudflareClient::ipRanges() i u FirewallController/CountryBlock dodati akciju 'CF-only na 80/443': agent operacija firewall.cf_lockdown koja kreira ufw aplikacijski profil/ipset s CF rangevima i pravilo allow from CF na 80/443 + deny ostalo, s dnevnim refreshom liste (isti task kao realip_sync). Globalni toggle (admin) jer pogađa cijeli server; jasno upozorenje da gasi direktan pristup. Veže se na spec poglavlje 19 (cloudflare modul) koje to eksplicitno traži.
  - Mapiranje: modules: firewall, cloudflare · tables: settings, tasks · operations: firewall.cf_lockdown
- **Wildcard AutoSSL preko DNS-01 nad lokalnim BIND-om** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Plesk SSL It! i cPanel AutoSSL te DirectAdmin/Certbot izdaju wildcard (*.domena) certifikate preko DNS-01 challengea kad je DNS lokalan, bez ovisnosti o Cloudflareu. ForgePanel spec (CLAUDE.md:206) ovo eksplicitno traži.
  - Tko: Plesk, cPanel/WHM, DirectAdmin, ISPConfig
  - Prijedlog: Trenutno jedini Dns01Provider je CloudflareDns; bez CF veze AutoSSL pada na http-01 koji ne može wildcard. Implementirati LocalBindDns01Provider koji preko agenta (dns.acme_challenge_set/clear) upisuje/briše _acme-challenge TXT u lokalnu BIND zonu (privremeni record + named-checkzone + rndc reload), pa SslIssue::resolveDns01Provider bira lokalni BIND kad je zona dns_mode='local' prije fallbacka na http-01. Acme::satisfyDns01 (već postoji za CF) čeka propagaciju. Omogućuje wildcard cert za sve domene s lokalnim DNS-om.
  - Mapiranje: modules: ssl, dns · tables: ssl_certs, dns_zones, dns_records · operations: dns.acme_challenge_set, dns.acme_challenge_clear, ssl.issue
- **Cloudflare SSL mode + automatski Origin certifikat** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: CF-integrirani paneli postavljaju SSL mode na Full (Strict) preko CF API-ja i generiraju CF Origin certifikat na origin serveru, čime se osigurava enkriptirana CF↔origin veza bez ovisnosti o LE renewu na originu (preporučena CF konfiguracija).
  - Tko: Plesk (CF ext), cPanel (CF plugin), Cloudways
  - Prijedlog: Kolona cloudflare_zones.ssl_mode (default 'full_strict') postoji ali je nitko ne postavlja. Dodati CloudflareClient::setSslMode (PATCH /zones/{id}/settings/ssl) i CloudflareClient::createOriginCert (POST /certificates), nove endpointe u CloudflareController (PUT /vhosts/{id}/cloudflare/ssl-mode, POST .../origin-cert). Agent operacija ssl.install_custom (već postoji) instalira generirani origin cert na vhost. UI u cloudflare modulu: dropdown SSL mode + gumb 'Generiraj Origin certifikat (15 god)'. Origin cert sa SslIssue logikom obnove kroz CF API.
  - Mapiranje: modules: cloudflare, ssl · tables: cloudflare_zones, ssl_certs · operations: ssl.install_custom
- **Auto-purge Cloudflare cachea nakon Git deploya** `[S/med]` — status ForgePanel: *none*
  - Konkurenti imaju: RunCloud, ServerAvatar, SpinupWP i GridPane automatski purgaju CF cache (cijela zona ili specifični URL-ovi) odmah nakon git deploya, da posjetitelji vide novu verziju bez ručnog purga. Postao je očekivani dio deploy pipelinea.
  - Tko: RunCloud, ServerAvatar, SpinupWP, GridPane
  - Prijedlog: Kolona cloudflare_zones.auto_purge_on_deploy (schema.sql:416) postoji ali je nitko ne čita. U GitController (ili GitDeploy operaciji) post-deploy: ako je vhost vezan na CF zonu s auto_purge_on_deploy=1, pozvati postojeći CloudflareClient::purgeCache (purge_everything ili lista URL-ova). UI checkbox 'Purge CF cache nakon deploya' na git_repos formi. Trivijalno jer su i purge metoda i kolona već implementirane — fali samo žica između git i cloudflare modula.
  - Mapiranje: modules: git, cloudflare · tables: git_repos, cloudflare_zones · operations: git.deploy
- **Dvosmjerna Cloudflare sinkronizacija s detekcijom konflikata** `[L/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Spec (CLAUDE.md poglavlje 19) traži 'dvosmjernu sinkronizaciju s detekcijom konflikata'. Webmin/Virtualmin i CWP CF moduli mogu povući postojeće zapise iz CF zone u panel; ForgePanel radi samo jednosmjerni push (panel→CF), pa zapisi dodani direktno u CF nisu vidljivi u panelu.
  - Tko: Webmin/Virtualmin, CWP
  - Prijedlog: Dodati CloudflareController::pullZone (GET CF /zones/{id}/dns_records) koji mapira CF zapise u dns_records strukturu i radi diff protiv panela: prikaže zapise koji postoje samo u CF, samo u panelu, i divergentne (isti name|type, različit content) kao konfliktnu listu u UI-ju s per-zapis izborom 'zadrži CF / zadrži panel'. Pohrana zadnjeg sync snapshota u novu kolonu cloudflare_zones.last_sync_hash za detekciju vanjskih promjena. Ne mora biti automatski merge — dovoljan je read+diff+manualno rješavanje, što pokriva spec zahtjev.
  - Mapiranje: modules: cloudflare, dns · tables: cloudflare_zones, dns_records
- **Live per-record proxy (orange cloud) toggle u UI-ju** `[S/low]` — status ForgePanel: *partial*
  - Konkurenti imaju: CF-integrirani paneli imaju klikabilni narančasti oblak po svakom DNS zapisu u listi za uključivanje/isključivanje CF proxyja u hodu. CyberPanel je čak popravljao baš taj toggle (v2.4.4).
  - Tko: CyberPanel, Plesk (CF ext), cPanel (CF plugin)
  - Prijedlog: Backend već postoji: CloudflareClient::setProxied + endpoint PUT /cloudflare/records/{rid}/proxy (CloudflareController::toggleProxy). Fali samo frontend: dodati u DNS records listu (kad je zona u CF modu) per-record ikonu narančastog oblaka koja zove taj endpoint i osvježi stanje, umjesto da se proxy postavlja samo pri sync/provisioningu (app.js:2889). Čisto UI/JS — najjeftiniji zatvaranje funkcionalne rupe koja je već 90% gotova.
  - Mapiranje: modules: cloudflare, dns · tables: cloudflare_zones, dns_records

### Mail stack (Postfix/Dovecot/Rspamd) i e-mail deliverability suite

- **Server-side Sieve filteri + autoresponder/vacation** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Dovecot Pigeonhole sieve s ManageSieve protokolom za server-side filtere; nativni vacation/autoresponder kroz UI s ispravnim envelope senderom da odgovori ne padnu na SPF/DKIM. cPanel je u v134/136 popravio vacation envelope sender; CyberPanel v2.4.5 dodao ManageSieve; HestiaCP/Cloudron imaju autoreply ugrađeno.
  - Tko: cPanel & WHM, CyberPanel, HestiaCP, Cloudron, Plesk Obsidian
  - Prijedlog: U MailConf.php dodati instalaciju dovecot-sieve+dovecot-managesieve, dopuniti protocols line (sieve+lmtp sieve plugin) i pokrenuti managesieve servis na :4190 (Roundcube ga već cilja). Nova tablica mail_sieve_rules (mailbox_id, kind ENUM('filter','vacation'), script TEXT, active) + nova agent op mail.sieve_write koja generira/validira sieve skriptu (sievec test) i piše u ~sieve. Autoresponder = vacation extension s envelope-from = mailbox da prolazi DKIM. UI: panel-native rule builder + vacation forma u MailController.
  - Mapiranje: modules: mail · tables: mail_sieve_rules, mailboxes · operations: mail.sieve_write, mail.setup
- **Per-mail-hostname SNI TLS certifikat (umjesto panel certa za sve)** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Plesk SSL It! od 2026 automatski osigurava MAIL server na fresh installu; Froxlor reuse vhost certa za mail; većina panela vezuje cert na mail.<domena> ili koristi Dovecot local_name SNI da svaka mail domena dobije ispravan TLS cert. ForgePanel servira panelov cert za sve POP3/IMAP/SMTP veze → TLS hostname mismatch za klijente spojene na mail.<domena>.
  - Tko: Plesk Obsidian, cPanel & WHM, Froxlor, ISPConfig 3
  - Prijedlog: Iskoristiti postojeći AutoSSL (ssl modul, op ssl.issue) da svaka mail domena dobije cert za mail.<domena> (već se generiraju mail A/MX zapisi u DnsDefaults). U MailConf.php konfigurirati Dovecot 'local_name mail.<domena> { ssl_cert/ssl_key }' SNI blokove i Postfix smtpd TLS s tlsproxy/SNI mapom umjesto hardkodiranog /etc/forgepanel/ssl/panel/fullchain.pem (linija 137). AutoSSL renewal hook regenerira Dovecot SNI fragmente. Tablica ssl_certs već nosi tip — dodati 'mail' tip.
  - Mapiranje: modules: mail, ssl · tables: ssl_certs, mail_domains · operations: ssl.issue, mail.domain_add, mail.setup
- **Dovecot quota enforcement + catch-all/suspend dostupni korisniku** `[S/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Svi ozbiljni paneli enforce-aju per-mailbox kvotu na IMAP/LMTP razini (Dovecot quota plugin), nude catch-all toggle i suspend mailboxa kroz UI. Froxlor 2.2 ide do per-mailbox spam score; cPanel/Plesk imaju kvotu, catch-all i suspend kao standard.
  - Tko: Plesk Obsidian, cPanel & WHM, HestiaCP, Froxlor, ISPConfig 3
  - Prijedlog: Infrastruktura postoji ali je 'mrtva': (1) u Dovecot 99-forgepanel.conf dodati mail_plugins=quota + quota backend (maildir:User quota) i quota_rule iz mailboxes.quota_bytes preko userdb SQL; (2) MailController dodati setCatchall (piše mail_domains.catchall_target — kolona+Postfix mapa već spremni) i suspendMailbox (mijenja mailboxes.status, Dovecot/Postfix već filtriraju active); (3) UI forme za izmjenu kvote/lozinke postojećeg mailboxa, catch-all polje i suspend gumb. Bez novih agent ops — sve preko postojeće DB-as-source-of-truth.
  - Mapiranje: modules: mail · tables: mailboxes, mail_domains · operations: mail.setup
- **Autodiscover / autoconfig / mobileconfig automatsko podešavanje klijenata** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Automatsko podešavanje mail klijenata: Outlook autodiscover.xml (/autodiscover/autodiscover.xml), Thunderbird autoconfig (/.well-known/autoconfig/mail/config-v1.1.xml), Apple .mobileconfig profil. Cloudron i ozbiljni mail paneli to nude — korisnik unese e-mail i lozinku, klijent sam povuče IMAP/SMTP postavke.
  - Tko: Cloudron, Plesk Obsidian, ISPConfig 3, Mailcow (referentni mail stack)
  - Prijedlog: Nova mala API/UI ruta koja servira autodiscover/autoconfig XML i .mobileconfig generirane iz mail_domains + per-hostname mail cert (IMAPS 993, submission 465/587, SNI host mail.<domena>). Pri kreiranju mail domene (mail.domain_add) auto-dodati DNS zapise: autodiscover CNAME, autoconfig CNAME i _autodiscover._tcp SRV u DnsDefaults. nginx vhost predložak dobiva location /autodiscover i /.well-known/autoconfig handler koji poziva panel endpoint. Generiranje XML-a je čist PHP, nema potrebe za agent op.
  - Mapiranje: modules: mail, websites, dns · tables: mail_domains · operations: mail.domain_add
- **Moderni deliverability standardi: MTA-STS, TLS-RPT, ARC, BIMI** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: MTA-STS (politika obavezne TLS isporuke + _mta-sts TXT i https policy endpoint), TLS-RPT (_smtp._tls TXT za izvještaje o TLS greškama), ARC potpisivanje (čuva DKIM/SPF kroz forwarding), BIMI (brendirani logo + VMC). Virtualmin 7.40 ima punu MTA-STS; cPanel v134/136 ARC za SRS forwarding; Ploi BIMI cert za brendirane mailove.
  - Tko: Webmin / Virtualmin, cPanel & WHM, Ploi.io, Mailcow (referentni mail stack)
  - Prijedlog: Proširiti DnsDefaults da generira _mta-sts TXT, _smtp._tls (TLS-RPT rua), te BIMI _bimi TXT (opcionalno s VMC). Dodati nginx vhost za mta-sts.<domena> koji servira /.well-known/mta-sts.txt politiku (generira panel iz mail postavki). U MailConf.php uključiti Rspamd ARC modul (arc.conf, isti ključ kao DKIM) za potpisivanje forwardane pošte. Deliverability.php validator proširiti provjerom MTA-STS/TLS-RPT/BIMI prisutnosti. Dovecot/Postfix postfix-mta-sts-resolver za odlazni MTA-STS enforcement (opcionalno).
  - Mapiranje: modules: mail, dns · tables: mail_domains, dns_records · operations: mail.domain_add, mail.setup
- **Povijesni mail log parser (delivered/bounced/deferred po porukama)** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Pregled isporuke pojedinačnih poruka kroz povijest — delivered/bounced/deferred status, razlog odbijanja, message-id tracking. Spec ForgePanela (modul mail) to traži, ali kod ima samo trenutni queue (MailQueue), ne povijesni log. cPanel ima mail delivery reports, Plesk mail log browser, Mailcow detaljni delivery log.
  - Tko: cPanel & WHM, Plesk Obsidian, Mailcow (referentni mail stack)
  - Prijedlog: Nova agent op mail.log_search koja čita journald/Postfix log kroz journalctl JSON (već je obrazac u logs modulu) i parsira po queue-id → status (sent/bounced/deferred), recipient, relay, dsn, reason. Rezultat stream kroz SSE. Opcionalno tablica mail_log_index (queue_id, message_id, recipient, status, reason, ts) koju Scheduler periodički puni iz loga za brzu pretragu i per-vhost filtriranje. UI: DeliverabilityController dobiva 'delivery log' tab uz postojeći queue pregled.
  - Mapiranje: modules: mail, logs · tables: mail_log_index · operations: mail.log_search
- **Izbor webmail klijenta + auto-login SSO** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Izbor webmail klijenta per domena (Roundcube ILI SnappyMail) — HestiaCP to ima per domenu; CyberPanel v2.4.5 zamijenio SnappyMail Django webmailom sa SSO (Dovecot master auth) pa korisnik klikne i uđe bez ponovne prijave. ForgePanel je zaključan na Roundcube bez auto-logina.
  - Tko: HestiaCP, CyberPanel, Plesk Obsidian
  - Prijedlog: WebmailSetup.php parametrizirati izborom klijenta (Roundcube default + SnappyMail kao lakša alternativa, oba kroz Apt/zaseban FPM pool kao i sada). Auto-login SSO: signed one-time token (isti pattern kao phpMyAdmin auto-login u Core/SignedToken) → Dovecot master/impersonation user da klijent iz panela uđe u svoj mailbox bez lozinke. Izbor spremiti u settings ili novu kolonu mail_domains.webmail_client.
  - Mapiranje: modules: mail · tables: mail_domains, settings · operations: mail.webmail_setup
- **Per-mailbox/per-domena antispam tuning (Rspamd spam score + greylisting)** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Granularno podešavanje spam politike: per-mailbox ili per-domena spam score prag i greylisting toggle. Froxlor 2.2 ima per-email-account spam score+greylisting (jače od većine koji to rade samo per domena); Rspamd to nativno podržava kroz settings module.
  - Tko: Froxlor, HestiaCP, Mailcow (referentni mail stack)
  - Prijedlog: Iskoristiti Rspamd settings module: nova agent op mail.spam_policy koja generira /etc/rspamd/local.d/settings.conf s per-domena/per-recipient pravilima (reject/add_header score, greylist on/off) iz panel baze. Nova tablica mail_spam_policy (scope ENUM('domain','mailbox'), ref_id, reject_score, add_header_score, greylist BOOL). UI u mail modulu: slideri za spam prag + greylist toggle per domena (admin) i per mailbox (klijent unutar svojih). Validacija 'rspamadm configtest' prije reloada.
  - Mapiranje: modules: mail · tables: mail_spam_policy, mail_domains, mailboxes · operations: mail.spam_policy

### Baze podataka i DB alati

- **Per-DB veličina i resource pregled (size_bytes se ne puni)** `[S/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Svi ozbiljni paneli prikazuju veličinu svake baze i ukupnu DB potrošnju po računu: cPanel (MySQL DB Wizard + Resource Usage), Plesk (per-DB size u Databases), DirectAdmin, HestiaCP (DB usage u kvoti), CloudPanel. Veličina baze ulazi i u kvotu plana i u disk usage računa. Enhance v12.16 čak uključuje DB i email usage u 'veličinu sajta'.
  - Tko: cPanel/WHM, Plesk, DirectAdmin, HestiaCP, CloudPanel, Enhance
  - Prijedlog: Nova read-only agent operacija db.sizes koja čita SELECT table_schema, SUM(data_length+index_length) FROM information_schema.tables GROUP BY table_schema (engine-agnostički preko postojećeg agent/src/Db.php PDO). DatabasesController::index() poziva je i UPSERT-a u db_databases.size_bytes (kolona već postoji, schema.sql l.157). UI app.js pageDatabases() prikazuje veličinu po bazi + sumu po subscriptionu na klijentskom dashboardu (poglavlje 4: 'potrošnja resursa svog plana — baze'). Periodički osvjež preko Scheduler.php (npr. svakih 15 min) da se ne računa na svaki page load.
  - Mapiranje: modules: databases, monitoring · tables: db_databases · operations: db.sizes
- **Remote access toggle ne otvara ufw port 3306 (kozmetička značajka)** `[S/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: cPanel 'Remote MySQL' dodaje host u allow-list i otvara pristup; Plesk 'Allow remote connections' upravlja i mrežnim pristupom; DirectAdmin i HestiaCP otvaraju firewall pravilo. Spec ForgePanela (poglavlje 6) eksplicitno traži 'remote access toggle (s ufw pravilom)'.
  - Tko: cPanel/WHM, Plesk, DirectAdmin, HestiaCP
  - Prijedlog: Proširiti DbUserCreate/DbUserUpdate (ili novu agent op db.remote_toggle) da pri remote_access=true uz host '%' dodaje ciljano ufw pravilo preko postojećeg System/Ufw helpera (allow from <CIDR> to any port 3306, default deny). UI mora tražiti izvorni IP/CIDR (ne 0.0.0.0/0 by default — sigurnosni gate). Pri gašenju toggla povući pravilo. Zapisati pravilo u audit_log i prikazati upozorenje 'baza izložena za <CIDR>'. Bez ovoga je host '%' postavljen ali firewall blokira pa značajka faktički ne radi.
  - Mapiranje: modules: databases, firewall · tables: db_users, audit_log · operations: db.remote_toggle, DbUserCreate, DbUserUpdate
- **Slow query log viewer s EXPLAIN gumbom** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Spec ForgePanela (poglavlje 6 monitoring) sam navodi 'MariaDB slow query log viewer s EXPLAIN gumbom', ali postoji samo u design-mockupima. cPanel/Plesk imaju DB performance uvide; CloudLinux MySQL Governor prati spore upite; Cloudways/aaPanel AI asistenti analiziraju MySQL status i spore upite. To je standardni DBA alat za 'zašto je site spor'.
  - Tko: Plesk, cPanel/WHM (preko add-ona), aaPanel, Cloudways
  - Prijedlog: Agent op db.slow_query_read koja parsira slow query log (putanja iz konfiguracije, čita preko System/Fs s realpath provjerom) i db.explain koja izvršava EXPLAIN <query> preko agent PDO-a u read-only kontekstu nad ciljnom bazom (vlasništvo provjereno po subscription_id). Novi MonitoringController endpoint /monitoring/slow-queries + UI tablica (vrijeme, trajanje, rows, query) s 'EXPLAIN' gumbom koji renderira plan. Idealan ulaz za assistant modul (poglavlje 14.2): asistent dobiva slow log + EXPLAIN pa objašnjava i predlaže indeks. Uvjet: dinamičko paljenje slow_query_log preko SET GLOBAL ili FPM-safe per-vhost praga.
  - Mapiranje: modules: monitoring, databases, assistant · tables: monitoring_metrics · operations: db.slow_query_read, db.explain
- **Per-vhost Redis/Valkey object cache provisioning** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Object cache u PHP/Redisu jednim toggleom je 2025/2026 standard: RunCloud Hub (Redis object+page cache), SpinupWP (Redis na svim stranicama), GridPane (3-slojni cache), CloudPanel (Redis preinstaliran), Cloudways Object Cache Pro + Relay (cache u PHP memoriji). Ubrzava WordPress dramatično. ForgePanel Redis vidi samo kao monitorirani servis.
  - Tko: RunCloud, SpinupWP, GridPane, CloudPanel, CyberPanel, Cloudways
  - Prijedlog: Agent op redis.provision koja kreira dedicirani Redis/Valkey instance per vhost (zaseban unix socket /run/forgepanel/redis/vh_<id>.sock + systemd template unit redis@vh_<id> s MemoryMax preko cgroup v2, requirepass). Upis u novu tablicu redis_instances (vhost_id, socket, maxmemory_bytes, status) ili koloni u vhosts. UI toggle 'Object cache (Redis)' u websites/databases modulu koji ubaci env (WP_REDIS_HOST=socket) i kod WP instalacije automatski instalira/aktivira plugin. Izolacija po vhost socketu = multi-tenant sigurno (klijent ne vidi tuđi cache).
  - Mapiranje: modules: databases, apps, websites · tables: redis_instances, vhosts · operations: redis.provision, redis.delete, redis.flush
- **PostgreSQL podrška (kreiranje baza/usera)** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: PostgreSQL je out-of-the-box u rastućem broju panela: ISPConfig 3.3 (PG za web stranice), Enhance v12.22 (PostgreSQL rola + PostGIS/PGVector default), FastPanel, Webuzo, CWP (backup PG), Froxlor; svi PaaS paneli (Coolify pgvector 18, Easypanel, Dokploy) nude PG u jednom kliku. Moderne PHP/Node/Python aplikacije i pgvector (AI) ga traže.
  - Tko: ISPConfig 3, Enhance, FastPanel, Webuzo, CWP, Froxlor, Coolify, Easypanel, Dokploy
  - Prijedlog: Novi set agent operacija pg.db_create/pg.db_delete/pg.user_create/pg.user_delete (preko psql ili pdo_pgsql, proc_open s array argumentima, CREATE ROLE / CREATE DATABASE OWNER, GRANT). Proširiti db_databases/db_users s kolonom engine ENUM('mysql','postgres') (default 'mysql', migracija). DatabasesController grana po engine; UI dodaje izbor enginea pri kreiranju ako je PG instaliran (komponenta u components tablici, opcionalni installer flag --components=...,postgres). Adminer kao DB alat (vidi zaseban gap) jer phpMyAdmin ne radi s PG. Backup proširiti s pg_dump granom.
  - Mapiranje: modules: databases, backup · tables: db_databases, db_users, components · operations: pg.db_create, pg.db_delete, pg.user_create, pg.user_delete
- **Adminer kao alternativni/lagani DB alat** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: CWP nudi i phpMyAdmin i Adminer; Adminer je jedini realan put za PostgreSQL/SQLite web management (phpMyAdmin je MySQL-only). Lagan je (jedan file) i pokriva više enginea — prirodan komplement uz PG podršku.
  - Tko: CWP, (de-facto kod hostera uz phpMyAdmin)
  - Prijedlog: Agent op adminer.install (download single-file Adminer u izolirani panel stack /opt/forgepanel/adminer, kao i PhpMyAdminInstall). DatabasesController dobiva adminerLogin koji koristi isti SignedToken jednokratni mehanizam (TTL 60s, jti consume) kao pmaLogin, plus izbor enginea (mysql/pgsql) i ciljne baze. UI: izbor alata (phpMyAdmin / Adminer) i, kod više db usera nad istom bazom, izbor identiteta (rješava i postojeću manu pmaLogin LIMIT 1 koji bira prvog usera). Za PG baze nudi se isključivo Adminer.
  - Mapiranje: modules: databases · tables: components, settings · operations: adminer.install
- **phpMyAdmin auto-login bira identitet i koristi control DB** `[S/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: cPanel/Plesk phpMyAdmin auto-login uvijek otvara session u kontekstu izabranog db usera, a control/storage DB omogućuje napredne značajke (bookmarks, query history, designer). ForgePanel pmaLogin uzima PRVOG db usera s pohranjenom lozinkom (LIMIT 1) pa kod više usera nad istom bazom korisnik ne bira identitet; nema control DB-a pa su napredne PMA značajke isključene.
  - Tko: cPanel/WHM, Plesk
  - Prijedlog: DatabasesController::pmaLogin prima db_user_id i otvara signon session za točno tog usera (i dalje vlasništvo po subscription_id). UI prikazuje padajući izbor identiteta kad baza ima više usera. Opcionalno: PhpMyAdminInstall kreira pmadb control storage (tablice iz create_tables.sql) + controluser radi bookmarks/history, vezano uz panel localhost. Mali popravak (identitet) je S; control DB dio je M.
  - Mapiranje: modules: databases · tables: db_users, settings · operations: PhpMyAdminInstall
- **Point-in-time recovery / fizički DB backup (mariabackup + binlog)** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Enterprise backup nudi PITR i fizičke backupe: JetBackup/Acronis immutable + point-in-time, ISPConfig/CWP nocni DB dumpovi, mnogi hosteri mariabackup za velike baze. ForgePanel radi samo logički mariadb-dump --single-transaction (BackupVhostCreate.php l.76) — za velike baze spor restore i bez vremenske preciznosti (gubitak transakcija od zadnjeg dumpa).
  - Tko: JetBackup, Acronis Cyber Protect, ISPConfig 3, CWP
  - Prijedlog: Dodati u backup modul opciju 'fizički DB backup' preko mariabackup (full + inkrementalni LSN lanci) uz postojeći logički dump, izbor po veličini baze. Za PITR: agent op db.binlog_enable (uključi binlog s row formatom) + arhiviranje binlog segmenata u backup destinaciju; restore wizard 'vrati do trenutka T' = restore zadnjeg fulla + binlog replay do timestampa. Nova tablica db_backup_points (database_id, type ENUM('logical','physical'), lsn, binlog_pos, created_at). Uvjet je već u spec poglavlju 9 (MariaDB major update traži svjež verificiran backup).
  - Mapiranje: modules: backup, databases · tables: backups, db_backup_points · operations: db.binlog_enable, db.physical_backup, db.pitr_restore

### SSL/ACME/AutoSSL

- **ZeroSSL fallback i izbor ACME providera (multi-CA)** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Plesk je u 18.0.77 (ožujak 2026) dodao nativni ACME SSL extension koji radi s BILO KOJIM ACME-kompatibilnim CA-om (ne samo Let's Encrypt), s integracijom u SSL It!. cPanel AutoSSL nudi per-account izbor providera (LE primarni, Sectigo, povijesno i drugi). Industrijska praksa je fallback na drugi CA kad primarni padne ili je rate-limitiran.
  - Tko: Plesk Obsidian, cPanel & WHM
  - Prijedlog: Iako Acme.php konstruktor već prima $directory_url, NIJEDAN poziv ne koristi tu mogućnost (svi su 'new Acme()' → uvijek LE). Implementirati stvarni fallback: u SslIssue/PanelSslIssue dodati listu directory URL-ova (LE → ZeroSSL → opcionalno BuyPass/Google Trust) iz settings tablice; na neuspjeh primarnog CA (npr. rate limit, 4xx na newOrder/finalize) pokušati sljedeći i upisati stvarni 'type' (letsencrypt/zerossl) u ssl_certs.type koji već postoji u enumu. ZeroSSL i Google Trust traže EAB (External Account Binding) — dodati polja eab_kid/eab_hmac u settings i podršku za EAB u Acme.php newAccount payload. Admin postavka 'ACME provider redoslijed' + per-subscription override.
  - Mapiranje: modules: ssl, settings · tables: ssl_certs, settings · operations: Ssl(Issue), PanelSslIssue
- **OCSP stapling i TLS hardening (ssl_protocols/ciphers/dhparam) per-vhost** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Plesk SSL It! uključuje OCSP i HTTP/2 toggle; svi ozbiljni paneli (cPanel, ISPConfig, CloudPanel) generiraju nginx/Apache TLS config s modernim ssl_protocols (TLS 1.2/1.3), kuriranim cipher suiteovima, ssl_dhparam i OCSP staplingom (ssl_stapling + ssl_trusted_certificate). Mozilla Intermediate/Modern profili su de-facto standard.
  - Tko: Plesk Obsidian, cPanel & WHM, ISPConfig 3, CloudPanel
  - Prijedlog: Grep potvrđuje 0 pogodaka za ssl_stapling/ssl_protocols/ssl_ciphers/ssl_dhparam u cijelom repu — TLS sigurnost ovisi isključivo o nginx defaultima. U NginxConf.php (vhost predlošci) i installer panel server bloku dodati: ssl_protocols TLSv1.2 TLSv1.3, kurirani ssl_ciphers, ssl_prefer_server_ciphers off, ssl_session_cache/timeout, ssl_stapling on + ssl_stapling_verify on + ssl_trusted_certificate (chain iz izdanog certa), te generiranje/dijeljenje ssl_dhparam.pem pri instalaciji. Uvesti 'TLS profile' enum (intermediate/modern) per-vhost s 'paranoia level' kao security modul, s obaveznim 'nginx -t' prije reloada i rollbackom (već postoji pattern u SslInstallCustom). Dodati stupac tls_profile u vhosts.
  - Mapiranje: modules: websites, ssl · tables: vhosts · operations: Vhost(BackendSet), Ssl(Issue)
- **Wildcard certifikat preko lokalnog BIND DNS-01 (i UI/API tok za *.domena)** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Spec CLAUDE.md eksplicitno traži 'wildcard preko DNS-01 kad je DNS lokalni (BIND)'. Plesk i cPanel izdaju wildcard preko DNS-01 kad upravljaju DNS-om lokalno. ForgePanel ima vlastiti BIND9 modul i DNSSEC, ali wildcard radi ISKLJUČIVO ako je domena na Cloudflareu.
  - Tko: Plesk Obsidian, cPanel & WHM, ISPConfig 3
  - Prijedlog: Jedini Dns01Provider je CloudflareDns.php. Napisati BindDns01Provider (implements Dns01Provider) u agent/src/System/ koji upisuje _acme-challenge TXT u lokalnu zonu (preko BindConf.php / nsupdate ili direktnog zone write + 'rndc reload zone' — rndc reload već se koristi), čeka SOA/propagaciju, pa cleanup TXT-a u finally. SslIssue::resolveProvider() proširiti: ako je vhost na lokalnom DNS-u (dns_zones postoji, nije cloudflare) → koristi BindDns01Provider za dns-01. Dodati eksplicitan UI/API tok 'Izdaj wildcard (*.domena)' u SslController + ssl_certs zapis s SAN [domain, *.domain]; danas AutoSSL uvijek izdaje samo [domain, www.domain].
  - Mapiranje: modules: ssl, dns · tables: ssl_certs, dns_zones, dns_records · operations: Ssl(Issue), Dns(ZoneWrite)
- **ECDSA (P-256) certifikati i dual-cert (RSA+ECDSA) izdavanje** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: ISPConfig 3.3 (2025) dodao ECC (Elliptic Curve) Let's Encrypt certifikate uz auto-cleanup starih. ECDSA P-256 certovi su danas standard — manji, brži handshake; ozbiljni stackovi serviraju dual cert (ECDSA za moderne klijente, RSA fallback).
  - Tko: ISPConfig 3, Plesk Obsidian
  - Prijedlog: Acme.php koristi openssl_pkey_new s OPENSSL_KEYTYPE_RSA 2048 i za account i za domain key (linija 322), bez izbora tipa. Dodati parametar key_type (rsa2048/rsa4096/ecdsa-p256) u CSR generiranje i u SslIssue params; za ECDSA koristiti OPENSSL_KEYTYPE_EC s 'curve_name'=>'prime256v1'. Po želji dual-cert: izdati oba i u nginx postaviti dva ssl_certificate retka (nginx podržava). Account key ostaviti RS256 (LE radi), ali domain key konfigurabilan. Dodati stupac key_type u ssl_certs i default 'ecdsa-p256' za nove vhostove.
  - Mapiranje: modules: ssl · tables: ssl_certs · operations: Ssl(Issue), PanelSslIssue
- **HSTS toggle za hostane stranice + preload registracija** `[S/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Plesk Security Advisor i SSL It! nude HSTS na korisničkim domenama jednim klikom; cPanel/standardni stackovi izlažu HSTS header per-domena s opcijom includeSubDomains/preload. HSTS preload (hstspreload.org) je očekivani hardening korak.
  - Tko: Plesk Obsidian, cPanel & WHM
  - Prijedlog: HSTS je postavljen SAMO za panel (Response.php max-age, bez includeSubDomains/preload). Za hostane vhostove ne postoji HSTS. U NginxConf.php dodati opcionalni 'add_header Strict-Transport-Security' s konfigurabilnim max-age/includeSubDomains/preload, kontroliran per-vhost toggle-om (stupac hsts_mode u vhosts: off/on/preload). UI checkbox uz SSL postavke vhosta s upozorenjem da preload znači trajnu HTTPS obvezu. Opcionalno: helper koji provjerava ispunjava li domena preload kriterije prije nego dopusti 'preload' mod.
  - Mapiranje: modules: websites, ssl · tables: vhosts · operations: Vhost(BackendSet)
- **Mail (Postfix/Dovecot) koristi AutoSSL certifikate umjesto snakeoil** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Plesk 18.0.78 (svibanj 2026) automatski osigurava i MAIL server Let's Encrypt certom na svježoj instalaciji. cPanel AutoSSL pokriva mail hostname. Froxlor reuse-a jedan vhost cert za mail servise. Korisnici očekuju da mail.domena ima valjan cert bez ručne intervencije.
  - Tko: Plesk Obsidian, cPanel & WHM, Froxlor, Cloudron
  - Prijedlog: MailSetup.php nema referencu na /etc/forgepanel/ssl/ cert pathove — mail vjerojatno koristi snakeoil. Ožičiti: nakon ssl.issue za mail hostname (npr. mail.domena ili server mail hostname), zapisati Postfix smtpd_tls_cert_file/key_file i Dovecot ssl_cert/ssl_key na izdani PEM (preko drop-in conf u MailSetup ili nove Mail(TlsApply) operacije), s reloadom Postfix/Dovecot. Scheduler obnova certa mora trigirati i mail reload (renew-hook pattern kao Froxlor). Dodati ssl_certs zapis tipa za mail hostname da se prati istek i auto-renew. AutoSSL na svježoj mail instalaciji izdaje cert za serverski mail FQDN odmah (Plesk model).
  - Mapiranje: modules: mail, ssl · tables: ssl_certs, mail_domains · operations: Mail(Setup), Ssl(Issue)
- **Certificate Transparency / mis-issuance monitoring domena** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Nadzor CT logova (crt.sh / Google CT) za neovlaštene certove izdane na korisničkim domenama je sigurnosna značajka koju deliverability/security suite nude (dio šireg 'domain security' trenda). Nijedan klasični panel to nema ugrađeno — prilika za diferencijaciju u skladu s ForgePanel 'sve uključeno' filozofijom.
  - Tko: (rijetko — sigurnosni add-oni / CT monitoring servisi)
  - Prijedlog: Dodati periodičnu agent operaciju (Scheduler, npr. dnevno) koja za svaku aktivnu domenu upita CT izvor (crt.sh JSON API ili Google CT) i usporedi pronađene izdavatelje/certove s onima koje je ForgePanel izdao (ssl_certs). Nepoznat cert (drugi CA/issuer kojeg panel nije tražio) → notifikacija vlasniku+adminu i zapis u novu tablicu ct_observations (domain, issuer, serial, not_before, seen_at, status). Uklopiti uz postojeći rbl_checks pattern u deliverability/security modul. Niska cijena, visok 'wow' jer nitko od konkurenata to nema u jezgri.
  - Mapiranje: modules: security, ssl · tables: ssl_certs, notifications · operations: DeliverabilityCheck
- **Security Advisor: one-click TLS/SSL hardening pregled s akcijama** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Plesk Security Advisor daje prioritizirane preporuke (SSL na sve, HTTP/2, vulnerability scan) s jednim klikom za primjenu. Bolji UX od pukih checkova — agregira sve SSL/TLS slabosti (domene bez certa, slab TLS profil, istek <30d, nedostaje HSTS/OCSP) u jednu akcijsku listu.
  - Tko: Plesk Obsidian
  - Prijedlog: Dodati 'SSL/TLS Advisor' pregled u security modul koji skenira: vhostove bez aktivnog certa, certove koji ističu, vhostove sa slabim TLS profilom (ovisi o gornjoj TLS-hardening značajki), domene bez HSTS-a, OCSP nedostupan, mješoviti sadržaj. Svaka stavka ima 'Popravi' gumb koji enqueue-a odgovarajući task (ssl.issue, primijeni modern TLS profil, uključi HSTS, masovni renew preko postojećeg BulkController). Reuse postojeće bulk SSL renew logike (BulkController:67). Prikaz prioriteta (high/med/low) i 'osiguraj sve domene' bulk akcije.
  - Mapiranje: modules: security, ssl · tables: ssl_certs, vhosts · operations: Ssl(Issue), Vhost(BackendSet)

### File manager, FTP, web terminal, cron

- **Rename / move / copy datoteka u file manageru** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Svaki ozbiljan file manager (aaPanel, CyberPanel, cPanel File Manager, HestiaCP, Plesk) nudi preimenovanje, premjestanje i kopiranje (single i batch) izravno iz UI-ja; Plesk je 2026. dodao i REST /fs/copy i /fs/move endpointe.
  - Tko: aaPanel, cPanel/WHM, Plesk Obsidian, CyberPanel, HestiaCP, FastPanel
  - Prijedlog: Dodati agent operacije fs.rename, fs.move i fs.copy u OperationRegistry, svaka s dvostrukom path izolacijom kao postojeci Fs ops (Validator::vhostPath na izvor I odrediste, realpath provjera oba unutar VHOST_ROOT, blok premjestanja/brisanja samog vhost roota). Implementirati u novim klasama FsRename/FsMove/FsCopy (atomski rename(); copy preko stream copy uz chown na vhost usera, rekurzivno za direktorije). Izloziti rute files/rename, files/move, files/copy u FilesController + UI gumbe/drag-drop u fileManager(). Batch varijanta nad .fsel checkbox selekcijom koja vec postoji.
  - Mapiranje: modules: filemanager · operations: fs.rename, fs.move, fs.copy
- **Arhiviranje i ekstrakcija (zip/tar/gz) iz file managera** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: aaPanel (uzor po CLAUDE.md), cPanel, CyberPanel i Plesk nude kompresiju selektiranih fajlova u zip/tar.gz i ekstrakciju arhiva jednim klikom unutar file managera.
  - Tko: aaPanel, cPanel/WHM, Plesk Obsidian, CyberPanel, HestiaCP
  - Prijedlog: Dodati agent operacije fs.archive (kreira zip/tar/tar.gz iz liste putanja) i fs.extract (raspakira arhivu u ciljani direktorij). Izvrsavati preko Proc::run s array argumentima (zip/unzip/tar) UNUTAR vhost konteksta, chown rezultata na vh_<id> usera; svaka ulazna i izlazna putanja kroz Validator::vhostPath (sprjecava zip-slip — provjeriti da svaki ekstrahirani entry realpath ostaje unutar roota). Izloziti files/archive i files/extract rute + gumbe u fileManager() i batch nad selekcijom. Resource-safe: nice/ionice na velikim arhivama.
  - Mapiranje: modules: filemanager · operations: fs.archive, fs.extract
- **Chunked / drag-drop upload velikih datoteka** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: aaPanel i CyberPanel imaju chunked upload s drag-drop zonom i progress barom za velike (GB) fajlove; spec ForgePanela ('drag-drop + chunked upload za velike fajlove') to eksplicitno trazi.
  - Tko: aaPanel, CyberPanel, cPanel/WHM, Plesk Obsidian
  - Prijedlog: Trenutni upload cita cijeli file u memoriju i salje base64 kroz agent socket (fs.write, ~33% overhead, 120s timeout, OOM rizik). Uvesti fs.write_chunk op koji prima offset+chunk i appenda u .part datoteku (O_APPEND, chown vhost user), te fs.finalize_upload koji atomski preimenuje .part u finalnu putanju nakon SHA-256 verifikacije. FilesController/upload prima chunkove (npr. 5 MB) sekvencijalno; UI dobiva drag-drop zonu + XHR progress. Svaki chunk i finalna putanja kroz vhostPath provjeru.
  - Mapiranje: modules: filemanager · operations: fs.write_chunk, fs.finalize_upload
- **Cron 'zadnja izvrsavanja' — stvarni capture exit code/outputa** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: DirectAdmin, cPanel i CyberPanel cron manageri prikazuju zadnji run, exit status i output zadnjeg izvrsenja zadatka. ForgePanel schema (cron_jobs.last_run_at/last_output/last_exit_code) i UI to vec prikazuju, ali polja su uvijek NULL.
  - Tko: cPanel/WHM, DirectAdmin, CyberPanel, Plesk Obsidian
  - Prijedlog: CronSync trenutno pise samo '<schedule> vh_<id> <command> >> logs/cron.log'. Umotati svaku komandu u forge-cron-wrapper (mali script koji panel deploya po vhostu): biljezi start, hvata exit code i zadnjih N KB outputa, pa preko agent op cron.report (ili direktnim zapisom u stanje koje agent periodicki cita) azurira cron_jobs.last_run_at/last_exit_code/last_output za taj job_id. Alternativno: agent op cron.tail koji parsira strukturirani wrapper log natrag u bazu. Time UI 'last run' i status postaju zivi end-to-end.
  - Mapiranje: modules: cron · tables: cron_jobs · operations: cron.sync, cron.report
- **Interaktivni web terminal (PTY/TTY stream), ne single-shot** `[L/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: HestiaCP (bubblewrap jail), CyberPanel (web terminal za korisnike), Laravel Forge (terminal s SSH kolaboracijom), Webdock i FastPanel imaju pravi interaktivni terminal/SSH konzolu u browseru s live streamom, povijescu komandi i interaktivnim programima (vim, top, composer prompt).
  - Tko: HestiaCP, CyberPanel, Laravel Forge, FastPanel, Webdock, aaPanel
  - Prijedlog: Terminal je trenutno request/response (jedna komanda -> jedan output preko POST) — interaktivni programi i dugotrajne komande ne rade. Uvesti PTY sesiju: agent op terminal.session_open pokrece proc_open s pseudo-TTY (admin: sh na hostu; klijent: docker run -it u postojecem sandboxu) i drzi je u registru sesija; terminal.session_write salje keystrokeove, a izlaz se streama natrag preko vec postojeceg SSE sloja (Core/Sse). UI xterm.js-style frontend. Reuse postojece izolacije (mode=client docker sandbox). Otvara i put ka kolaborativnom modu (Forge differentiator).
  - Mapiranje: modules: terminal · operations: terminal.session_open, terminal.session_write, terminal.session_close
- **Klijentski sandboxirani terminal nema UI ulaz** `[S/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: CyberPanel i aaPanel daju krajnjem korisniku (ne samo adminu) web terminal/SSH konzolu ogranicenu na njegov prostor. ForgePanel backend (TerminalExec mode=client, docker --network none --read-only, mount samo vhost roota) je kompletan i dobro dizajniran, ali terminalSection() u app.js renderira se SAMO za rolu admin.
  - Tko: CyberPanel, aaPanel, HestiaCP
  - Prijedlog: Otkljucati terminalSection() i za klijenta KAD je admin ukljucio toggle (TerminalController vec ima toggle/clientExec preko settings tablice). UI: ako je client terminal enabled za subscription/vhost, prikazi terminal panel koji gadja clientExec rutu (mode=client, vhost_id) umjesto adminExec. Strogo respektirati delegated_access/ownership middleware. Time se realizira vec napisani 'prvi panel s per-klijent terminalom koji ne vidi sustav' (poglavlje 14.10) koji je trenutno backend-only.
  - Mapiranje: modules: terminal, users · tables: settings, delegated_access · operations: terminal.exec
- **FTP promjena lozinke i chmod/owner u UI editoru** `[S/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Svi paneli (cPanel, Plesk, DirectAdmin, HestiaCP) dopustaju promjenu FTP lozinke bez brisanja usera, te chmod/chown iz file managera. ForgePanel FtpController izlaze samo GET/POST/DELETE (nema PUT za lozinku), a fs.chmod op postoji ali nije izlozen u UI editoru; chown se uopce ne nudi.
  - Tko: cPanel/WHM, Plesk Obsidian, DirectAdmin, HestiaCP, Froxlor
  - Prijedlog: FTP: dodati PUT rutu ftp/{id}/password u FtpController koja azurira ftp_users.password_hash i triggera ftp.sync (ProftpdConf regenerira passwd iz baze — jedno mjesto istine vec postoji). File manager: izloziti vec postojeci fs.chmod gumb u openFileEditor() i listi (oktalni mod picker), te dodati fs.chown op ogranicen na dozvoljene vhost user/grupu (vh_<id>) uz realpath provjeru. Bez sirenja arhitekture — koristi postojece op-kodove plus jedan novi.
  - Mapiranje: modules: ftp, filemanager · tables: ftp_users · operations: ftp.sync, fs.chmod, fs.chown
- **Code editor sa syntax highlightingom i search po fajlovima** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: aaPanel (online editor s highlightingom), cPanel (Jodit/CodeMirror editor), CloudPanel i Plesk imaju editor s isticanjem sintakse; svi nude i pretragu po imenu/sadrzaju fajlova. ForgePanel editor je obican textarea s rucnim brojevima linija, bez highlightinga, i nema search.
  - Tko: aaPanel, cPanel/WHM, Plesk Obsidian, CloudPanel
  - Prijedlog: Editor: integrirati lagani client-side highlighter bez build alata (npr. CodeMirror 6 ili highlight.js ucitani kao staticki asset, u skladu s 'bez build alata' pravilom) u openFileEditor() — detekcija jezika po ekstenziji, zadrzati postojeci save/download/delete tok. Search: dodati agent op fs.search (find po imenu + opcionalni grep po sadrzaju, ogranicen na vhost root, s limitom rezultata i timeoutom da ne ubije I/O) i files/search rutu + polje u fileManager(). Oboje unutar postojece path izolacije.
  - Mapiranje: modules: filemanager · operations: fs.search

### Backup i restore (destinacije, format, granularnost, off-site)

- **Off-site destinacije (S3/SFTP/FTP) - upload backupa** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Backup se uploada na vise paralelnih off-site destinacija: S3-kompatibilno (AWS/Wasabi/Backblaze B2/Cloudflare R2/DigitalOcean Spaces/Hetzner), SFTP, FTP, a JetBackup i Cloudron dodaju Google Drive/Dropbox/OneDrive. CloudPanel ima S3/Wasabi/Spaces/Dropbox/GDrive/SFTP s automatskim odabirom S3 regije po lokaciji servera; Easypanel S3 storage classes + Cloudflare R2 preset. Lokalna kopija nikad nije jedina kopija.
  - Tko: JetBackup, CloudPanel, Easypanel, CyberPanel, Coolify, Dokploy, SpinupWP, Cloudron, CWP
  - Prijedlog: Implementirati upload sloj: nova agent operacija backup.push (preuzima gotov backup_dir + destination config, uploada na S3/SFTP/FTP). S3 preko AWS SDK ili cisti SigV4 PHP klijent (bez frameworka, vendor/ pravilo); SFTP preko phpseclib koji je vec u repu (koristi se za migrator). BackupVhostCreate na kraju enqueue-a backup.push ako destinacija != local. backup_destinations.config (vec enkriptiran preko Crypto.php) drzi endpoint/region/bucket/credentials. Dodati BackupsController rute za CRUD destinacija + UI u backup modulu. Restore prvo povlaci s off-sitea u lokalni temp pa postojeci verifyManifest+restore tok.
  - Mapiranje: modules: backup · tables: backup_destinations, backups · operations: backup.push, backup.vhost_create, backup.restore
- **Zakazani (scheduled) backupi - scheduler ne postoji** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Svaki panel nudi rasporedjene backupe (daily/weekly/monthly/custom cron) s automatskim izvrsavanjem bez intervencije: Softaculous zakazani backup, JetBackup per-destination reindex raspored, Webdock dnevni+tjedni snapshoti automatski, CloudwaysBot, Enhance backup rola. ForgePanel ima tablicu backup_schedules ali nijedna linija je ne cita - svi backupi su iskljucivo rucni/on-demand.
  - Tko: JetBackup, Softaculous, Installatron, CloudPanel, CyberPanel, Webdock, Cloudways, Enhance, FastPanel
  - Prijedlog: Dodati scheduler tick u forge-agentd daemon (uz postojeci task queue loop): svaku minutu citaj backup_schedules WHERE enabled=1, evaluiraj cron izraz (schedule kolona) protiv last_run, i za dospjele enqueue-aj backup.vhost_create (ili full-server kad subscription_id NULL). Alternativa: installer instalira systemd timer koji zove panel CLI. Dodati backup_schedules CRUD rute u BackupsController + scheduler UI (human-readable kao kod crona, vec postoji pattern u cron modulu). Primijeniti retention JSON iz schedule, ne keep N.
  - Mapiranje: modules: backup · tables: backup_schedules, backups · operations: backup.vhost_create
- **Inkrementalni backup s deduplikacijom (rsync hardlink / restic / borg)** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Point-in-time inkrementalni backup gdje se nakon prvog fulla cuvaju samo izmjene preko hardlink retencije (JetBackup: 30 dnevnih kopija 2GB racuna = 2GB + samo delta) ili dedup engine (HestiaCP Restic, Enhance diferencijalni backup stedi do 80% prostora, FastPanel differential beta). Drasticno manje diska i jeftin off-site jer se salju samo izmjene.
  - Tko: JetBackup, HestiaCP, Enhance, FastPanel, Cloudron
  - Prijedlog: backups.type ENUM vec ima 'incremental' ali se uvijek pise 'full'. Implementirati rsync --link-dest strategiju (vec navedena u spec poglavlju 10) u novoj operaciji backup.vhost_incremental: rsync vhost roota u novi datum-dir s --link-dest na prethodni snapshot (nepromijenjeni fileovi = hardlinkovi). Baze ostaju puni dump (ili binlog kasnije). Manifest dobiva polje base_backup_id. Retention mora paziti da ne obrise base na koji se hardlinkuje. Alternativno integrirati restic kao backend za off-site dedup. UI: po vhostu izbor full/incremental u schedule.
  - Mapiranje: modules: backup · tables: backups, backup_schedules · operations: backup.vhost_incremental, backup.vhost_create
- **Backup enkripcija (AES-256, kljuc kod korisnika)** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Enkriptirani backupi po defaultu prije slanja off-site: CloudPanel AES-256 default, JetBackup AES-256 s lokalnim/remote upravljanjem kljucevima, Cloudron enkriptirani backupi na S3/GCS/Spaces, Acronis immutable+encrypted. Bitno za GDPR i da off-site provider ne vidi sadrzaj.
  - Tko: CloudPanel, JetBackup, Cloudron, Acronis
  - Prijedlog: backup_schedules.encrypt kolona postoji ali se ignorira. Dodati enkripciju u backup pipeline: nakon tar/dump streamati kroz openssl enc -aes-256-gcm (proc_open array args, agent pravilo) ili PHP sodium/openssl. Kljuc izvodi iz korisnickog passphrasea (Argon2id KDF) - kljuc NIKAD ne ide u panel bazu, samo se cuva salt + verifikacijski tag; jasno upozorenje 'bez kljuca nema restorea' (spec poglavlje 10). Manifest oznacava encrypted=true + alg. Restore trazi passphrase prije verifyManifest. Crypto.php se prosiruje ili dedicirani BackupCrypto helper.
  - Mapiranje: modules: backup · tables: backup_schedules, backups · operations: backup.vhost_create, backup.restore
- **Immutable / WORM off-site backup (anti-ransomware)** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Backup koji se tijekom retencije ne moze izmijeniti ni obrisati - S3 Object Lock Compliance/Governance mode (JetBackup, JetBackup Storage s versioningom), Acronis immutable storage. Stiti kad napadac/ransomware dobije root jer agent (root) inace moze rm -rf lokalne backupe. World Backup Day 2026 'cyber resilience' poruka cijele industrije.
  - Tko: JetBackup, Acronis
  - Prijedlog: Nadovezuje se na off-site (gap #1): kod S3 destinacije podrzati Object Lock - pri upload-u postaviti retention period (x-amz-object-lock-retain-until-date) iz schedule retencije; bucket s Object Lock enabled. backup_destinations.config dobiva immutable:true + lock_mode/lock_days. UI jasno oznaci 'immutable' destinaciju i da se takav backup ne moze rucno obrisati prije isteka. Lokalni backupi opcionalno chattr +i nakon kreiranja (skida se samo eksplicitnim admin op-om s audit logom).
  - Mapiranje: modules: backup, security · tables: backup_destinations, backups · operations: backup.push, backup.delete
- **Sira granularnost restorea (mail, DNS zona) - backup ne obuhvaca mail/DNS** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Granularni self-service restore pojedine kategorije: cijeli account / fileovi / baza / E-MAIL / DNS ZONA (JetBackup, Enhance restore pojedinacnih fileova, cPanel/Plesk standard). JetBackup tvrdi ~70% manje support ticketa zbog self-service granularnog restorea. ForgePanel backup uopce ne snima Maildir (izvan vhost roota) ni DNS zone pa te granularnosti nema cime restorirati.
  - Tko: JetBackup, Enhance, cPanel, Plesk, Cloudron
  - Prijedlog: Prosiriti BackupVhostCreate da uz fileove+baze ukljuci i mail (Maildir vhost domena iz Dovecot stabla) i DNS zonu (BIND zone file + dns_records snapshot) kao zasebne artefakte u istom tar+manifest formatu (manifest dobiva sekcije mail[]/dns[]). Restore dobiva nove modove uz 'files'/'db': 'mail' (vrati Maildir + chown) i 'dns' (zone write preko postojece dns.zone_write operacije). UI nudi checkbox po kategoriji. Dosljedno spec poglavlju 10 (granularni restore vhost/files/db/mail/dns).
  - Mapiranje: modules: backup, mail, dns · tables: backups, mail_domains, dns_zones, dns_records · operations: backup.vhost_create, backup.restore, dns.zone_write
- **Full-server backup** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Backup cijelog servera (svi vhostovi + sve baze + mail + DNS + panel config) jednom akcijom/rasporedom, ne samo per-vhost: Webdock full-server snapshoti, Enhance backup rola, CWP full backup, Virtualmin. Kriticno za disaster recovery cijelog hosta.
  - Tko: Webdock, Enhance, CWP, Virtualmin, ISPConfig
  - Prijedlog: Dodati operaciju backup.server_create koja iterira sve aktivne vhostove (reuse backup.vhost_create logike), dumpa sve hostane baze, ukljuci panel config snapshot (config_versions/etc paths koje panel kontrolira) i kompletira jedinstveni server-level manifest. Vezuje se na backup_schedules.subscription_id NULL (vec dokumentirano kao 'full server'). Resursno zasticeno nice/ionice. Admin-only u UI; off-site destinacija obavezno preporucena.
  - Mapiranje: modules: backup · tables: backup_schedules, backups, backup_destinations · operations: backup.server_create, backup.vhost_create
- **Napredna retencija (daily/weekly/monthly grandfather) + integrity check tool** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Grandfather-father-son retencija: npr. 7 dnevnih + 4 tjedna + 3 mjesecna, definirana kao politika (JetBackup per-destination, Softaculous, sve spec-navedeno). Plus JetBackup Integrity Check Tool koji periodicki verificira da su pohranjeni backupi konzistentni (ne tek pri restoreu).
  - Tko: JetBackup, Softaculous, Enhance, Acronis
  - Prijedlog: Zamijeniti prost keep-N (applyRetention) s primjenom backup_schedules.retention JSON ({daily,weekly,monthly}): klasificiraj postojece backupe po datumu i zadrzi N po svakoj klasi, ostalo obrisi (vodeci racuna o hardlink base ovisnosti iz gap #3 i immutable lock iz gap #5). Dodati periodicki backup.verify task (scheduler) koji prolazi manifest SHA-256 nad pohranjenim/off-site backupima i oznacava status corrupt + notifikacija, da se ostecenje uhvati prije kriznog restorea.
  - Mapiranje: modules: backup · tables: backup_schedules, backups · operations: backup.vhost_create, backup.verify

### Sigurnost: malware, WAF, firewall, fail2ban, anomaly detection, hardening

- **Anomaly detection na logovima s dinamičkim generiranjem fail2ban jailova** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Imunify360 Proactive Defense + IDS/IPS i BitNinja AI-driven detekcija analiziraju promet/logove u realnom vremenu i automatski reagiraju na 404 floode, wp-login/xmlrpc brute force, path traversal i SQLi/XSS obrasce. cPanel/Plesk uz Imunify, te CSF/LFD (DirectAdmin) rade log-based intrusion detection s automatskim banovima. Spec ForgePanela ovo traži kao core security feature, ali nije implementiran nigdje osim statičkog nginx-forbidden jaila u installeru.
  - Tko: Imunify360 (cPanel/Plesk/DirectAdmin), BitNinja, cPGuard, CSF/LFD (DirectAdmin), Enhance (brute force zaštita)
  - Prijedlog: Novi Scheduler task 'anomaly_scan' (npr. svakih 60-120s) + System/AnomalyDetector helper koji tail-a nginx/Apache access+error logove (preko journald/file pozicija), agregira po IP-u i detektira pragove: 404 flood, ponavljani 401/403 na wp-login.php/xmlrpc.php, traversal (../, %2e%2e), SQLi/XSS pattern matchove. Pri prekoračenju praga agent generira custom fail2ban jail/filter (kroz postojeći System/Fail2ban) i banira IP preko ufw banaction. Eventi se upisuju u novu tablicu 'security_events' (vhost_id, type, source_ip, count, action, detected_at) i prikazuju u firewall/security modulu s one-click whitelistom. Pragovi konfigurabilni per-vhost. Sve kroz agent operaciju (AnomalyScan) i postojeći whitelist model.
  - Mapiranje: modules: security, firewall, monitoring · tables: security_events, audit_log · operations: AnomalyScan, FirewallAction
- **One-click restore čiste verzije iz pred-infekcijskog backupa (deklarirani differentiator koji ne postoji)** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Acronis Cyber Protect skenira same backup fileove na malware PRIJE restorea i radi automatski rollback zaraženih fileova iz čistog point-in-time backupa. Imunify cleanup uklanja samo zlonamjerni dio i čuva integritet originala. ForgePanel spec ovo eksplicitno navodi kao značajku 'koju nitko od panela nema' (usporedba checksuma kroz backup povijest), ali implementacije nema — postoji samo prazna kolona quarantine_items.restored_from_backup_id; QuarantineRestore vraća sam zaraženi file.
  - Tko: Acronis Cyber Protect, Imunify360 (cleanup), JetBackup (granularni restore)
  - Prijedlog: Proširiti QuarantineRestore operaciju: za karantenirani path pretraži backups povijest (tablica backups + manifest.json sa SHA-256 po fileu — već dokumentirani backup format) unatrag od detected_at, pronađi najnoviju verziju filea čiji checksum NIJE jednak zaraženom (tj. zadnja čista kopija prije infekcije), ekstrahiraj samo taj file iz tar backupa i vrati na original (chown vh_<id>), upiši backups.id u quarantine_items.restored_from_backup_id. Dodati novi mod restore: 'restore_clean' uz postojeći 'restore'/'delete'. UI prikazuje koji je backup point i datum korišten. Zahtijeva indeksiranje per-file checksuma u manifestu (ako već nije po fileu, dodati).
  - Mapiranje: modules: security, backup · tables: quarantine_items, backups · operations: QuarantineRestore, BackupRestore
- **Zakazani i real-time (inotify) malware scanovi** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: cPGuard i Imunify rade real-time file monitoring (inotify-style) za detekciju novih zaraza čim se upload dogodi, plus zakazane periodične scanove. ForgePanel spec traži 'zakazani scanovi per-vhost ili full' i 'real-time scan novih uploada (inotify watch na uploads direktorije)'. Trenutno je scan isključivo on-demand — Scheduler nema scan task, nema inotify watcha.
  - Tko: cPGuard, Imunify360 (RapidScan), aaPanel (anti-tamper/file protection), FastPanel (Ai-Bolit)
  - Prijedlog: (1) Scheduler task 'malware_scan' koji prema novoj tablici 'scan_schedules' (vhost_id, scope, cron, last_run) enqueue-a 'malware.scan' taskove u maintenance prozoru. (2) Dugotrajni inotify watcher u agentu (forge-agentd ili zaseban forge-watchd systemd unit) koji prati uploads/wp-content/uploads direktorije aktivnih vhostova; na CLOSE_WRITE pokreće MalwareScanner::scanContent na novom fileu i, ako je hit, odmah karantenira + notificira. Hash cache (RapidScan model) da se nepromijenjeni fileovi ne re-skeniraju (ključno za idle footprint <300MB). UI: per-vhost raspored + toggle real-time zaštite.
  - Mapiranje: modules: security · tables: scan_schedules, malware_scans, quarantine_items · operations: MalwareScan
- **Backend-enforced 2FA (admin može zaobići 2FA preko API-ja)** `[S/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: cPanel Team Manager i Enhance enforcaju obavezni 2FA na razini autentikacije; Cloudron OIDC endpoint enforca 2FA serverski. cPanel je u 2025/2026 uveo session-scope enforcement ('off-scope sessions fail closed') upravo zato što UI-side enforcement nije dovoljan. U ForgePanelu komentar u Auth.php priznaje da admin bez TOTP secreta dobiva potpunu sesiju (twofa_passed=1) — enforcement je samo UI-side, pa direktan /api/v1 pristup zaobilazi obavezni 2FA.
  - Tko: cPanel (Team Manager), Enhance, Cloudron, Plesk (MFA)
  - Prijedlog: U Core/Auth.php i AuthContext middleware-u: ako rola zahtijeva 2FA (admin obavezno, ili enforced po settingu) a user nema potvrđen twofa_secret/webauthn, NE postavljati twofa_passed=1; umjesto toga izdati ograničenu sesiju (scope: samo 2FA-setup endpointi) i odbiti sve ostale API/UI rute s 403 'twofa_setup_required'. API Bearer tokeni admina bez 2FA odbijaju se jednako. Dodati setting 'enforce_2fa_roles' (JSON) i grace-period prozor. Bez nove tablice — proširenje sessions/api_tokens scope logike.
  - Mapiranje: modules: users · tables: sessions, api_tokens, settings
- **WAF i ClamAV lazy-install rupa (na čistom serveru ne rade)** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Enhance i CWP isporučuju built-in WAF (ModSecurity + OWASP CRS) koji radi out-of-the-box, te skenere koji su instalirani od prvog dana. ForgePanel WafToggle lazy-instalira samo paket 'modsecurity-crs' ali NE instalira nginx modsec modul (libnginx-mod-modsecurity) niti ga učitava; installer ne instalira ni clamav. Na čistom Ubuntu 26.04 serveru WAF toggle vjerojatno padne na nginx -t, a ClamAV drugi sloj se nikad ne aktivira.
  - Tko: Enhance, CWP, CyberPanel, aaPanel (aaWAF)
  - Prijedlog: (1) U installer/install.sh (component 'web'/'security') instalirati libnginx-mod-modsecurity, učitati modul kroz load_module direktivu u glavnom nginx configu i validirati nginx -t; opcionalno instalirati clamav + clamav-daemon + freshclam ako je komponenta 'mail' ili 'security' odabrana. (2) U WafToggle::ensureModsec dodati provjeru da je nginx modsec modul učitan (i lazy-install libnginx-mod-modsecurity + reload), inače jasna greška u task output umjesto tihog nginx -t fail-a. (3) ServiceStatus prikazuje status modsec/clamav komponenti.
  - Mapiranje: modules: security · tables: components · operations: WafToggle, ServiceStatus, MalwareScan
- **Proaktivni runtime behavioralni engine za PHP (zero-day webshell zaštita)** `[L/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Imunify360 Proactive Defense analizira ponašanje PHP skripti u realnom vremenu (ne signature) i ubija izvršavanje po obrascima — obfuscirani command injection, planting koda, spam, SQLi — PRIJE štete; tri moda (log/kill/disabled). BitNinja radi ML behavioral detekciju. ForgePanel ima samo statički regex skener na fileovima (11 potpisa) koji se izvodi tek pri scanu, ne hvata runtime izvršavanje već postavljenog backdoora.
  - Tko: Imunify360 (Proactive Defense), BitNinja
  - Prijedlog: Opcionalna PHP auto_prepend_file zaštitna shim skripta (ForgePanel runtime guard) injektirana per-FPM-pool (disable_functions je već preset, dodati prepend guard): pri svakom requestu provjerava sumnjive runtime obrasce (eval na request-derived inputu, base64+gzinflate lanci u izvršavanju, neočekivani system/exec pozivi iz writable uploads patha) i u 'kill' modu prekida izvršavanje + loggira u security_events. Per-vhost mod (log/kill/off) u security modulu. Lagano, bez vanjske ovisnosti; uklapa se u postojeću FPM pool izolaciju.
  - Mapiranje: modules: security, websites · tables: security_events, waf_rules · operations: WafToggle, VhostPhpSettings
- **Virtualni patching / auto-harden ranjivih WordPress plugina i tema** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Plesk WP Toolkit Vulnerability Protection, Imunify Patch/Patchman i Patchstack (Solid Security) rade virtualni patching (WAF blokira exploit poznate CVE ranjivosti plugina/tema prije službenog patcha) i targeted source-code patching. ForgePanel ima WP core checksum provjeru (WpChecksums), ali nema detekciju ranjivih plugina/tema ni virtualni patch.
  - Tko: Plesk (WP Toolkit), Imunify360, Patchman, Wordfence, Solid Security/Patchstack
  - Prijedlog: Nova agent operacija WpVulnScan: enumerira instalirane plugine/teme (čita verzije iz file headera ili wp-cli), provjerava protiv feeda poznatih ranjivosti (Patchstack/WPScan-style JSON, dnevno osvježavan Scheduler taskom 'vuln_feed') i upisuje nalaze u novu tablicu 'vuln_findings' (vhost_id, component, version, cve, severity). Za visoke severity automatski generira virtual-patch ModSecurity pravilo (kroz WafRule) koje blokira poznati exploit URI/payload. UI u security/apps modulu: popis ranjivih komponenti s one-click 'zaštiti' (virtual patch) ili 'ažuriraj'.
  - Mapiranje: modules: security, apps · tables: vuln_findings, waf_rules · operations: WpVulnScan, WafRule, WpChecksums
- **Security Advisor / hardening pregled + persistirano country blocking + AppArmor** `[L/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Plesk Security Advisor daje prioritizirane hardening preporuke s one-click akcijom (SSL na sve, HTTP/2, vuln scan vs CVE). aaPanel Daily Report Pro sažima sigurnosno stanje. ForgePanel hardening je razasut po installeru bez objedinjenog pregleda; AppArmor enforce profili (traženi spec poglavlje 8/12) nisu nigdje implementirani; country blocking ipset se ne persistira preko reboota niti ga Scheduler dnevno osvježava, a AI/SEO bot-block nginx mape i xmlrpc blok ne postoje u kodu.
  - Tko: Plesk (Security Advisor), aaPanel (Daily Report), Imunify (country blocking),  servera s CSF
  - Prijedlog: (1) Security Advisor pregled u security modulu: agent operacija SecurityAudit koja prikuplja stanje (2FA enforced?, SSH PasswordAuth, AppArmor profili enforce, ufw aktivan, fail2ban jailovi, vhostovi bez WAF/SSL) i vraća prioritiziranu listu preporuka s one-click fix akcijama kroz postojeće operacije. (2) AppArmor: installer enforce profili za nginx/Apache/PHP-FPM/Postfix/Dovecot + agent toggle. (3) CountryBlock: persistirati ipset (ipset save → /etc/forgepanel/ipset.conf + systemd restore unit) i dodati Scheduler task 'geoip_refresh' (24h) koji obnavlja zone iz ipdeny.com. (4) Gotovi setovi: generator nginx map bot-blocklisti (AI/SEO) i xmlrpc blok pravila kroz firewall modul.
  - Mapiranje: modules: security, firewall · tables: settings, security_events · operations: SecurityAudit, CountryBlock, FirewallAction

### Monitoring, uptime, logovi, alarmi, observability

- **Centralni log viewer (nginx/Apache/PHP/mail/system + journald, live tail preko SSE)** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Plesk 18.0.79 ima /logs pretragu (Apache/nginx/PHP-FPM); cPanel ima log viewere; aaPanel i CyberPanel imaju ugradjeni log pregled; svi tradicionalni paneli (Webmin/ISPConfig/HestiaCP) nude pregled servisnih logova iz UI-ja. Coolify/Dokploy/Easypanel imaju real-time log streaming po servisu (Easypanel cak Loki). Plesk dodatno GoAccess kao default analitiku.
  - Prijedlog: Novi modul 'logs' (spec modul 14, potpuno neimplementiran). Agent operacija 'logs.tail' (journalctl --output=json -u <whitelist unit> ILI tail nad whitelistanim file logom unutar /var/www/vhosts/<id>/logs i /var/log) s realpath/whitelist provjerom; LogsController u web/src/Api/V1 s filterima (servis, severity, vremenski raspon, regex). Live tail preko postojeceg Sse.php (dodati streamLogs uz streamTask). Per-vhost error log pregled filtriran po subscription_id u middlewareu. Reuse: docker logs i WafRule::tail() vec dokazuju pattern citanja logova kroz agent.
  - Mapiranje: modules: logs · operations: logs.tail, logs.search
- **Per-vhost monitoring metrike (cgroup v2 FPM pool statistika) — 'mrtva' read putanja** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: cPanel Resource Usage (CloudLinux LVE) prikazuje per-account CPU/vMem/IO i 'End Users hitting limits'; CyberPanel ima per-website cgroups CPU/RAM/IO grafove; Coolify Sentinel daje per-aplikacija grafove; ServerPilot app-specific metrike. Spec ForgePanela (modul 13 i poglavlje 4) eksplicitno trazi per-vhost cgroup FPM statistiku za klijentski dashboard potrosnje.
  - Prijedlog: Scheduler::collectMetrics() vec cita cgroup v2 po system.slice/<unit>.service, ali NIKAD ne pise scope='vhost:<id>' — pa MonitoringController::history() koji vec dopusta klijentu regex /^vhost:\d+$/ vraca prazno. Prosiriti collectMetrics() da mapira php<ver>-fpm pool cgroup (ili per-vhost slice ako se FPM pool stavi u zaseban systemd scope) na vhost_id iz vhosts tablice i pise monitoring_metrics scope='vhost:<id>' (cpu_pct, mem). Time klijentski grafovi vlastite potrosnje (poglavlje 4 dashboard) postaju zivi bez novih tablica.
  - Mapiranje: modules: monitoring · tables: monitoring_metrics, vhosts
- **CRUD API za uptime probe + povijest eventa i response-time graf** `[M/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Plesk 360 Monitoring (interval do 60s, izbor probe, vise kanala, retencija); Laravel Forge health checks iz 3 geo-regije + Heartbeats (dead-man's-switch za cron/worker); Ploi recovery notifikacije i 30-dnevna retencija. ForgePanel ima sam runtime probe engine ali bez ikakvog korisnickog upravljanja.
  - Prijedlog: Probe se sad kreiraju samo automatski pri vhost/subdomain create s hardkodiranim intervalom 300s i tipom https (VhostsController), bez API-ja za uredjivanje. Dodati UptimeController u web/src/Api/V1 (CRUD nad uptime_probes: dodaj/uredi/obrisi, izbor type HTTP/HTTPS/TCP/keyword, interval, target) + GET povijesti iz uptime_events i response-time serije iz uptime_probes uzoraka (dodati response_ms zapis u zasebnu seriju ili monitoring_metrics scope='probe:<id>'). Dodati 'heartbeat' tip probe (cron/worker dead-man's-switch — cron job pinga endpoint, izostanak pinga = alarm) kao Forge Heartbeats ekvivalent. Filtriranje po subscription_id.
  - Mapiranje: modules: monitoring · tables: uptime_probes, uptime_events
- **Moderni alarmni kanali: Slack / Discord / PagerDuty / Opsgenie** `[S/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Dokploy salje na Slack/Discord/Telegram/email/webhook; Ploi na Slack/Discord/Teams; CloudwaysBot email/Slack/custom; Forge Slack/Telegram; vecina DevOps panela ima chat-webhook kanale. ForgePanel Notifier podrzava samo email/Telegram/generic webhook.
  - Prijedlog: Prosiriti agent/src/System/Notifier.php sa sendSlack() i sendDiscord() (oba su HTTPS webhook POST s JSON payloadom — vec postoji siguran sendWebhook obrazac s CURLPROTO_HTTPS/TLS verify, samo treba formatirati payload po platformi) + opcionalno PagerDuty Events API v2 (routing_key + dedup_key za incident dedup, prirodno se spaja s postojecim alarm dedup logom). Konfiguracija u settings.monitoring_alarms s validacijom u MonitoringController::alarmsPut. Mali napor, visok pull kod DevOps publike.
  - Mapiranje: modules: monitoring · tables: settings · operations: alarm.test
- **Konfigurabilna alarmna pravila: per-servis/per-vhost pragovi, uptime trajanje, SSL istek** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: CloudwaysBot proaktivni alarmi na disk/health/performance; aaPanel predictive analytics (predvidja bottleneckove); Plesk 360 vise tipova alarma s pragovima; Acronis/Imunify anomaly modeli. ForgePanel checkAlarms() radi iskljucivo staticki prag na 3 globalne metrike (cpu/mem/disk) na razini servera; uptime/SSL/RBL se javljaju ad-hoc, ne kroz jedinstven prag sustav.
  - Prijedlog: Prosiriti Scheduler::checkAlarms() i settings.monitoring_alarms shemu na pravila po scope-u: per-servis (service:<name> mem/cpu prag), per-vhost (vhost:<id> kvota — vezuje se uz gap per-vhost metrika), uptime trajanje (down dulje od N min), SSL istek (cert <N dana iz ssl_certs) i RBL listing kao konfigurabilno pravilo umjesto ad-hoc notifikacije. Zadrzati postojeci dedup (1x/sat) i recovery. Dodati anomaly nacin: baseline iz monitoring_metrics agregata (npr. zadnjih 7 dana po satu) + odstupanje preko X*stddev kao rani signal (aaPanel-style predictive). Nove tablice nisu nuzne — pravila u settings, opcionalno alarm_rules tablica ako se zeli per-user.
  - Mapiranje: modules: monitoring · tables: settings, monitoring_metrics, ssl_certs, rbl_checks
- **PHP-FPM pool status + opcache statistika per pool** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: cPanel/CloudLinux prikazuju FPM/PHP resurse; SpinupWP najavljuje PHP settings dashboard (workers/upload/memory); spec ForgePanela (modul 13) eksplicitno trazi 'PHP-FPM i opcache statistika per pool'. Trenutno se opcache samo konfigurira, nikad ne mjeri.
  - Prijedlog: Aktivirati pm.status_path i ping.path u per-vhost FPM pool configu (PhpFpm.php vec generira poolove); agent operacija 'fpm.status' koja preko FPM socketa cita pool status (active/idle/total procesi, listen queue, slow requests) i opcache_get_status() preko malog status skripta izvrsenog kroz pool. MonitoringController::fpmStatus() izlaze podatke; per-vhost vidljivo klijentu (filter subscription_id). Reuse: ServiceStatus whitelist + AgentClient obrazac.
  - Mapiranje: modules: monitoring, websites · operations: fpm.status
- **MariaDB slow query log viewer s EXPLAIN gumbom** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Spec ForgePanela (modul 13) ga navodi kao feature; vecina ozbiljnih panela nudi neki DB performance uvid; aaPanel AI asistent analizira MySQL status; Plesk/cPanel imaju DB dijagnostiku. ForgePanel nema nista (grep prazan).
  - Prijedlog: Agent operacija 'db.slowlog' (citanje slow_query_log filea ili performance_schema, agregacija po digestu) + 'db.explain' (EXPLAIN/EXPLAIN ANALYZE nad odabranim queryjem kroz panel DB usera s read-only ovlastima). DatabasesController dobiva slowlog endpoint; UI lista najsporijih upita s one-click EXPLAIN (idealan ulaz za assistant modul — Claude objasni plan). Filter po db_databases vlasnistvu (subscription_id).
  - Mapiranje: modules: databases, monitoring · tables: db_databases · operations: db.slowlog, db.explain
- **Standardizirani metrics export (Prometheus/OpenMetrics /metrics endpoint)** `[S/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Plesk nudi Grafana monitoring ekstenziju; GridPane koristi Grafana monitoring + log aggregation servere; Easypanel izlaze Prometheus metrike; Coolify Sentinel pusha metrike. Metrike su industrijski ocekivane u scrape formatu za vanjske dashboarde/alerting. ForgePanel metrike su zakljucane u vlastiti REST history endpoint.
  - Prijedlog: Read-only /metrics endpoint (OpenMetrics tekstualni format) koji iz monitoring_metrics i live cgroup snapshota izlaze server+per-service+per-vhost serije, zasticen Bearer tokenom sa scope 'metrics:read' (api_tokens vec ima scope model). Time se ForgePanel ukljucuje u postojece Prometheus/Grafana stackove bez vlastite vizualizacije; nizak napor jer podaci vec postoje. Opcionalno Grafana datasource dokumentacija u docs/.
  - Mapiranje: modules: monitoring · tables: monitoring_metrics, api_tokens
- **Konfigurabilna retencija metrika i klijentski pristup monitoringu** `[S/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Ploi 30-dnevna retencija (Unlimited plan), Plesk 360 do 2 god. historije, plan-ovisne retencije su standard. cPanel/CyberPanel daju klijentu uvid u vlastite resurse. ForgePanel retencija je hardkodirana (2h/7d/400d) u aggregate(), a vecina MonitoringController endpointa zahtijeva requireRole('admin') pa klijent monitoring dobiva samo posredno (status page, widgeti).
  - Prijedlog: Parametrizirati Scheduler::aggregate() retenciju iz settings (globalno) ili plana (plans.features JSON — npr. retencija po tieru). Otvoriti MonitoringController::history() i nove per-vhost/fpm/probe endpointe klijentu uz strogi filter da scope mora biti vhost:<id> ili probe:<id> u njegovom vlasnistvu (provjera kroz subscription_id middleware, ne admin gate). Klijentski dashboard tako dobiva vlastite grafove potrosnje koje spec (poglavlje 4) trazi.
  - Mapiranje: modules: monitoring, users · tables: settings, plans, monitoring_metrics

### Auto-update orkestrator, OS patch management, health/rollback

- **Live kernel patching (Livepatch / Ubuntu Pro) bez reboota** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Imunify360 integrira KernelCare za live kernel security patcheve bez reboota (agent provjerava svaka 4h). Ubuntu Pro Livepatch je nativni ekvivalent. cPanel/Plesk hosteri masovno koriste KernelCare jer reboot produkcijskih servera radi kernel CVE-a je glavna operativna bol.
  - Tko: Imunify360 (KernelCare), Ubuntu Pro (Livepatch), cPanel ekosustav
  - Prijedlog: Novi agent op `os.livepatch_configure` (unos Ubuntu Pro tokena, `pro attach` + `pro enable livepatch`) i `os.livepatch_status` (parse `canonical-livepatch status --format json`). Token enkriptiran preko postojeceg Core\Crypto kao i cloudflare_accounts.api_token. Prikaz statusa zakrpa i pending CVE-ova u updates modulu. Tablica `settings` cuva enabled flag; status u novu kolonu/redak komponente 'kernel'. CLAUDE.md 9.9 ovo eksplicitno trazi a koda nema.
  - Mapiranje: modules: updates · tables: settings, components · operations: os.livepatch_configure, os.livepatch_status
- **'Reboot required' detekcija + zakazivanje reboota u maintenance window** `[S/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Standardni dio svakog ozbiljnog patch managementa: detekcija /var/run/reboot-required (npr. nakon kernel/glibc updatea) i zakazivanje kontroliranog reboota. Webdock nudi Rescue Console + scheduled reboot; svi enterprise paneli signaliziraju 'pending reboot'. ForgePanel update orkestrator radi apt upgrade ali nikad ne kaze korisniku da je reboot potreban.
  - Tko: Webdock, cPanel/WHM, Plesk, unattended-upgrades ekosustav
  - Prijedlog: Prosiriti UpdatesScan (vec radi svaka 4h) da agent cita /var/run/reboot-required i /var/run/reboot-required.pkgs te puni novi redak/flag. Novi op `os.reboot_schedule` koji upisuje zakazani reboot u maintenance window (reuse UpdatePolicy::inWindow logike) preko `shutdown -r` ili systemd transient timera. UI indikator + 'zakaži reboot' gumb u updates modulu. Notifikacija adminu kad reboot postane potreban.
  - Mapiranje: modules: updates, monitoring · tables: update_policies, notifications, tasks · operations: os.reboot_schedule, updates.scan
- **OS-level dist-upgrade orkestracija pod kontrolom panela** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Enhance v12 koristi apt-based deployment + systemd za sve servise pod punom kontrolom panela. Plesk Migrator podrzava upgrade na nove OS-eve (Debian 13, AlmaLinux 10). ForgePanel prepusta SVE OS pakete iskljucivo unattended-upgrades cronu (konfiguriran jednom u installeru), bez UI-ja za pregled, ukljucivanje/iskljucivanje ili pokretanje full-upgradea.
  - Tko: Enhance, Plesk (OS upgrade), DirectAdmin CustomBuild
  - Prijedlog: Novi op `os.upgrade_apply` (apt-get full-upgrade ciljano, s istim snapshot+health+rollback ciklusom kao UpdatesApply) i `os.unattended_configure` (citanje/pisanje 50unattended-upgrades + 52forgepanel-unattended.conf iz panela: toggle security-only, blacklist paketa, auto-reboot prozor). UI sekcija 'OS updatei' u updates modulu uz postojeci pregled komponenti. Tretirati OS kao pseudo-komponentu u components tablici.
  - Mapiranje: modules: updates · tables: components, update_policies, component_updates · operations: os.upgrade_apply, os.unattended_configure
- **Stabilizacijska odgoda N dana od release-a (delay_days enforcement)** `[S/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Konzervativni staged rollout: ne primijeni update odmah po izlasku nego pricekaj N dana da se stabilizira (izbjegava regresijske releaseove). delay_days kolona postoji u schema.sql:305 (default 3) i u UI defaultu, ali enqueueAutoUpdates je NIGDJE ne cita — security update ulazi cim udje u prozor. Patchman/Installatron rade rollback-safe staged pristup.
  - Tko: Installatron, enterprise patch politika (industrijski standard)
  - Prijedlog: U Scheduler::enqueueAutoUpdates() dodati provjeru: dohvatiti datum dostupnosti verzije (zapisati `available_since` timestamp u components pri UpdatesScan kad se available_version promijeni) i preskociti enqueue ako (now - available_since) < delay_days. Trivijalna izmjena koja aktivira vec postojecu shemu i UI. Zaokruzuje politiku koja je inace dobro dizajnirana (inWindow/isMajorJump su cisti).
  - Mapiranje: modules: updates · tables: components, update_policies · operations: updates.scan
- **Obavezan svjez verificiran backup prije DB major upgradea** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Installatron i Softaculous rade automatski backup PRIJE app upgradea + auto-restore ako padne. JetBackup/Acronis nude verified/immutable restore point prije rizicnih operacija. CLAUDE.md 9.3 to eksplicitno trazi za MariaDB/MySQL major: 'obavezan svjez verificirani backup, inace blokiraj'. UpdatesApply radi samo config tar snapshot, ne i DB backup gate.
  - Tko: Installatron, Softaculous, Acronis Cyber Protect, JetBackup
  - Prijedlog: U UpdatesApply (i u UI major-wizardu) prije DB major upgradea pozvati postojeci BackupVhostCreate/mariadb-dump put i verificirati svjezinu (max N sati) + integritet (checksum iz backup manifesta). Ako nema verificiranog backupa — blokiraj s jasnom porukom. Reuse postojeceg backup modula i backups tablice; dodati provjeru u UpdatePolicy ili UpdatesApply preflight.
  - Mapiranje: modules: updates, backup · tables: backups, component_updates · operations: updates.apply, backup.vhost_create
- **Funkcionalne post-update probe (HTTP panel/vhost, SMTP/IMAP port)** `[M/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Laravel Forge radi health check ping iz 3 geo-regije; svaki ozbiljan orkestrator nakon promjene provjeri da aplikacija STVARNO odgovara, ne samo da je servis 'active'. CLAUDE.md 9.5 trazi HTTP probe panela + sample vhosta i SMTP/IMAP port probe. Trenutni healthCheck radi samo config-test + systemd isActive + DB SELECT 1 — servis moze biti 'active' a vracati 502.
  - Tko: Laravel Forge, Cloudways (CloudwaysBot), Acronis (DR test)
  - Prijedlog: Prosiriti UpdatesApply::healthCheck(): nakon servisa napraviti stvarni HTTP GET na panel :8443 i na jedan sample vhost (ocekuj 2xx/3xx), te za mail komponente otvoriti TCP/STARTTLS probe na 25/465/587/143/993 (reuse logike koja vec postoji u MailConf). Fail bilo koje probe -> postojeci rollback put. Time funkcionalni health zatvara petlju koja je inace najjaca strana modula.
  - Mapiranje: modules: updates, mail, websites · tables: component_updates · operations: updates.apply
- **Major-verzija wizard s compatibility checkom** `[L/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Plesk daje UI wizard za MariaDB 11.8 / MySQL 8.4 upgrade iz UI-ja s provjerama; cPanel detektira i uklanja version lockove pri DB upgradeu (v134). CLAUDE.md 9.7 trazi 'UI wizard s compatibility checkom' za major skokove. Trenutno isMajorJump samo blokira AUTO granu — korisnik major moze gurnuti kroz isti 'apply' gumb bez ikakve provjere kompatibilnosti.
  - Tko: Plesk, cPanel/WHM
  - Prijedlog: Novi op `updates.major_precheck` koji za ciljanu komponentu pokrece compatibility provjere (npr. PHP: deprecirane ekstenzije/funkcije u vhostovima; MariaDB: mysql_upgrade --check, deprecated varijable, plugin kompatibilnost) i vraca strukturiran izvjestaj. Frontend wizard (vise koraka: precheck -> obavezan backup -> apply -> health) prije nego dopusti major apply. Reuse health/rollback infrastrukture.
  - Mapiranje: modules: updates · tables: component_updates, components · operations: updates.major_precheck, updates.apply
- **Auto-healing watchdog za kljucne servise** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: RunCloud 'Auto Healing' i Cloudways SmartFix automatski detektiraju pad servisa i restartaju ga bez intervencije; CloudwaysBot proaktivno alarmira. ForgePanel ima ServiceStatus/Reload i Scheduler, ali nema kontinuirani watchdog koji detektira mrtav servis (nginx/php-fpm/mariadb/postfix) i automatski ga pokusava ozivjeti + alarmira — oslanja se samo na systemd restart unutar update health checka.
  - Tko: RunCloud (Auto Healing), Cloudways (SmartFix), Enhance
  - Prijedlog: Dodati Scheduler task (svakih ~60s) `services.watchdog` koji preko postojeceg Systemd helpera provjerava isActive za skup kriticnih servisa; na fail pokusati N restartova s backoffom, logirati u audit_log i poslati notifikaciju. Per-servis politika (auto-restart on/off) u settings. Razlikovati od update health checka — ovo je stalni nadzor neovisan o updateima.
  - Mapiranje: modules: monitoring, updates · tables: settings, notifications, audit_log · operations: services.watchdog, service.reload

### Multi-tenant: reseller, white-label, delegacija, kvote, billing/provisioning, licensing

- **Disk kvota se ne enforcea kao filesystem kvota (project quota)** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: cPanel/CloudLinux LVE i svi tradicionalni paneli (DirectAdmin, HestiaCP, ISPConfig) tvrdo enforcaju disk kvotu po korisniku/računu na razini filesystema (user/project quota); kada korisnik dosegne limit, daljnji zapis fizički ne uspijeva. cPanel uz to broji i inode limite, a Enhance v12.23 dodaje per-website inode limite.
  - Tko: cPanel/WHM, DirectAdmin, HestiaCP, ISPConfig 3, CloudLinux, Enhance
  - Prijedlog: Implementirati ext4/xfs project quota u agentu: nova System helper klasa Fs::setProjectQuota() koja registrira projid po vhostu (/etc/projid + /etc/projects), poziva 'xfs_quota -x -c project'/'setquota -P' kroz proc_open s array argumentima. Nova agent operacija Vhost.QuotaSet (op 'vhost.quota_set' params: vhost_id, disk_bytes, inode_limit) pozvana iz VhostCreate i pri promjeni plana. Repquota agregaciju puniti u monitoring_metrics + dodati 'disk_used_bytes'/'inode_used' u dashboard. Plan polja disk_bytes/max_inodes već postoje — sada ih stvarno vezati na FS. Pri kreiranju vhosta enforcati i pri 100% kvote blokirati upload u file manageru s jasnim errorom.
  - Mapiranje: modules: websites, filemanager, monitoring · tables: plans, vhosts, monitoring_metrics · operations: vhost.quota_set, vhost.create
- **cgroup v2 kvote nisu per-vhost — dijeljene su na cijeli phpX.Y-fpm.service** `[L/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: CloudLinux LVE (cPanel) i CyberPanel (OpenLiteSpeed cgroups) i DirectAdmin v1.690 (isolated per-user PHP-FPM) primjenjuju CPU/RAM/IO/EP/process limite STROGO po korisniku/vhostu, pa jedan klijent koji podivlja ne ruši ostale na istoj PHP verziji. To je sama bit 'resource kvote bez CloudLinuxa' obećanja.
  - Tko: cPanel (CloudLinux LVE), CyberPanel, DirectAdmin, Enhance
  - Prijedlog: Drop-in se trenutno piše na phpX.Y-fpm.service s fiksnim imenom forgepanel-quota.conf (Systemd.php:72,83), pa ga svaki sljedeći vhost prepisuje i limit dijele SVI vhostovi te verzije. Ispravak: svaki vhost mora imati zaseban systemd scope/slice ili dedicirani FPM master serv<br>is (model DirectAdmin v1.690 isolated FPM). Predlažem prelazak na zaseban systemd slice po vhostu (forgepanel-vh<id>.slice) i FPM pool koji se pokreće u tom sliceu, ili dedicirani 'forgepanel-fpm-vh<id>.service' (ondemand) s vlastitim drop-inom imenovanim po vhost id. writeResourceDropin() mora primati jedinstveni unit/slice naziv po vhostu. TasksMax dodati uz CPUQuota/MemoryMax. Dodati monitoring per-slice cgroup statistike (cpu.stat/memory.current) u monitoring modul + dashboard 'korisnici koji udaraju u kvote (24h)' (cPanel Resource Usage uzor).
  - Mapiranje: modules: websites, monitoring, users · tables: plans, vhosts, monitoring_metrics · operations: vhost.create, vhost.php_set
- **Delegirane permisije osim 'files' su mrtve — git/cron/ftp/mail/db/backup nedostupni delegatu** `[S/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: cPanel Team Manager (od v112) daje sub-userima granularne role (Web/Database/Email) koje stvarno otključavaju te dijelove sučelja; Plesk Restricted mode i GridPane client portal daju funkcionalan granularni pristup po feature-u. ForgePanel grant sprema permisije ali ih ne primjenjuje.
  - Tko: cPanel (Team Manager), Plesk (Restricted mode), GridPane, aaPanel (sub-accounts)
  - Prijedlog: delegated_access + DelegationController + AuthContext::hasDelegation() postoje i PERMS lista ima ['files','git','cron','databases','ftp','mail','backup'], ali samo FilesController prosljeđuje perm. Provjereno: GitController/CronController/FtpController/BackupsController/MailController zovu vhostOr404($id) BEZ drugog argumenta → permission===null → delegat nikad ne prolazi. Ispravak je mali i lokaliziran: u svakom kontroleru proslijediti odgovarajući perm (vhostOr404($id,'git'), 'cron', 'ftp', 'mail', 'databases', 'backup'). DatabasesController veže DB na vhost pa zahtijeva mapiranje DB→vhost prije provjere. Bez backend ispravka cijela delegacija je iluzija.
  - Mapiranje: modules: users, git, cron, ftp, mail, databases, backup · tables: delegated_access
- **Nema frontend UI-ja za delegirani pristup** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: cPanel Team Manager ima potpuni UI (dodaj člana, dodijeli role, obavezni 2FA, automatsko isticanje, audit po sub-useru). GridPane client portal i Plesk imaju vizualno upravljanje delegiranim/team pristupom. ForgePanel ima samo REST rute (grant/revoke/index) bez ijednog ekrana.
  - Tko: cPanel (Team Manager), GridPane, Plesk, Laravel Forge (Organizations)
  - Prijedlog: Dodati 'Delegirani pristup' ekran u klijentski UI (web/public/assets/app.js — trenutno 0 referenci na 'delegat'): forma za poziv developera (email), checkbox matrica permisija po vhostu (files/git/cron/databases/ftp/mail/backup), popis aktivnih delegacija s revoke gumbom, opcionalni datum isteka (dodati expires_at u delegated_access) i prikaz per-delegat audit zapisa. Reseller/admin pogled za pregled svih delegacija. Koristi postojeće DelegationController rute; nema novih agent operacija.
  - Mapiranje: modules: users · tables: delegated_access
- **Provisioning API ne provjerava reseller kapacitet ni vlasništvo plana (oversell rupa)** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Enhance i Cloudways WHMCS moduli te cPanel Manage2 provisioniraju unutar reseller/paket limita — billing ne može stvoriti pretplate izvan dodijeljenog pula. Reseller capacity zaštita mora vrijediti i za API put, ne samo UI.
  - Tko: Enhance, cPanel (WHM/Manage2), Cloudways, Plesk
  - Prijedlog: ProvisioningController::create() i changePackage() (provjereno u kodu) NE zovu assertResellerCapacity() koji postoji samo u UsersController, i prihvaćaju BILO KOJI plan_id ('SELECT 1 FROM plans WHERE id=?') bez provjere da plan pripada tom reselleru (plans.owner_user_id se ignorira). Posljedica: reseller s API tokenom (ili njegov billing) zaobilazi oversell zaštitu i može koristiti tuđe/globalne planove. Ispravak: izdvojiti assertResellerCapacity()/resellerCeiling() u zajednički servis (npr. Core\ResellerQuota) i pozvati ga iz oba kontrolera; u create/changePackage validirati da plan_id pripada provisioning akteru (owner_user_id = akter ili globalni dopušten po politici).
  - Mapiranje: modules: users · tables: plans, subscriptions
- **Nema gotovog billing modula (WHMCS/Blesta/FOSSBilling)** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Enhance i Cloudways isporučuju službene WHMCS module; cPanel ima Manage2 + WHMCS module; Webuzo/aaPanel WHMCS automatizaciju. Hosteri biraju panel upravo zato što integracija s billingom radi out-of-the-box; goli REST API + webhook nije dovoljan jer integrator mora pisati modul ručno.
  - Tko: Enhance, Cloudways, cPanel (Manage2), Webuzo, aaPanel, Plesk
  - Prijedlog: Provisioning API + anti-SSRF webhook su solidan temelj — sada isporučiti tanki WHMCS provisioning modul (PHP: CreateAccount/SuspendAccount/UnsuspendAccount/TerminateAccount/ChangePackage mapiran na /api/v1/provisioning/* endpointe, ConfigOptions za plan mapping, Bearer token u server config) + FOSSBilling adapter (open-source, lak ulaz). Smjestiti u modules/billing-whmcs/ (modules/ je trenutno prazan, samo README). Dokumentirati event payloade. Ovo je Faza 5 po spec-u ali je ključan prodajni adut i webhook/REST već postoje.
  - Mapiranje: modules: billing-whmcs · tables: subscriptions, plans, api_tokens
- **Provisioning endpointi nisu u OpenAPI specifikaciji** `[S/med]` — status ForgePanel: *none*
  - Konkurenti imaju: cPanel (UAPI/WHM API) i Plesk (REST, 2026. otvoren i resellerima) drže potpunu, javnu API dokumentaciju gdje je svaka provisioning operacija specificirana — billing integratori se oslanjaju na nju. ForgePanel spec sam zahtijeva 'API-first + održavan OpenAPI'.
  - Tko: cPanel/WHM, Plesk, Froxlor, ISPConfig
  - Prijedlog: docs/openapi.yaml postoji ali grep za 'provisioning'/'account/create' vraća 0 pogodaka. Dodati /api/v1/provisioning/account/{create,suspend,unsuspend,terminate,changepackage} u OpenAPI s requestima (email, plan_id, password?), responseima, scope 'provisioning:write', error kodovima (invalid_email, invalid_plan, email_exists, reseller_quota_exceeded) i webhook event shemama (account.created/suspend/terminate/changepackage). Ovo je preduvjet za vanjske billing module i potvrđuje API-first disciplinu.
  - Mapiranje: modules: users
- **Add-on planovi i auto-sinkronizacija pretplata pri promjeni plana** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Plesk service plans + add-on planovi: promjena plana automatski re-sinkronizira SVE vezane pretplate (limiti, FPM kvote, feature flagovi), a add-on planovi dodaju resurse iznad baznog plana. cPanel Account Packages imaju isti pretplatnički model.
  - Tko: Plesk, cPanel
  - Prijedlog: changePackage() i updatePlan() samo mijenjaju plan_id/limit u DB ali NE re-primjenjuju nove kvote na postojeće resurse (FPM cgroup drop-ini, disk project quota, broj dopuštenih domena). Dodati: (1) add-on planove preko nove tablice plan_addons (subscription_id, addon_plan_id) čiji se limiti zbrajaju na bazni plan u resolveSubscriptionLimits(); (2) pri changePackage/updatePlan enqueue task 'subscription.resync' koji prolazi sve vhostove pretplate i ažurira vhost.quota_set + cgroup drop-ine na nove vrijednosti (Plesk auto-sync model). Audit zapis promjene.
  - Mapiranje: modules: users, websites · tables: plans, plan_addons, subscriptions, vhosts · operations: vhost.quota_set
- **Nema team/sub-account modela ni SSO (OIDC) za hostane korisnike** `[L/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: cPanel Team Manager nudi prave sub-usere unutar jednog računa bez dijeljenja lozinke; Laravel Forge Organizations i Ploi teams premjestili su vlasništvo resursa na tim; Cloudron 8.0 je OIDC provider za 60+ hostanih aplikacija s enforcanim 2FA. ForgePanel ima samo delegated_access vezan na pojedini vhost.
  - Tko: cPanel (Team Manager), Laravel Forge, Ploi, Cloudron, GridPane
  - Prijedlog: Dvije odvojene nadogradnje: (1) Team accounts — nova tablica team_members (owner_user_id, member_user_id, role, twofa_required) da klijentski račun ima više sub-usera s rolama (a ne samo per-vhost delegaciju), s obaveznom 2FA politikom po članu (audit po sub-useru — cPanel uzor). (2) OIDC provider sloj: ForgePanel kao jedinstveni login za hostane WordPress/Nextcloud instance (Cloudron model) preko novog Core\OidcProvider + agent operacije za upis OIDC klijent configa u app. Prva je S/M i pokriva veću potražnju; druga (OIDC) je L i bolje stoji uz apps modul.
  - Mapiranje: modules: users, apps · tables: team_members, users, sessions

### Aplikacije: WordPress toolkit, staging, Git deploy, Docker, Node/Python runtime, app marketplace

- **WP Toolkit dubina (plugin/tema management, vuln skener, smart/auto-update, multisite)** `[L/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Plesk WP Toolkit i cPanel WP Toolkit nude upravljanje pluginovima/temama (instalacija, bulk update, aktivacija), Smart Update (klon → test → update → vizualni before/after diff → rollback ako pukne), security risk rating per komponenta, vulnerability skener s virtualnim patchingom ranjivih plugina/tema, maintenance mode, multisite podršku i auto-update politiku per instanca. Softaculous/Installatron nude rollback-safe auto-update s backupom prije i auto-restoreom.
  - Tko: Plesk Obsidian (WP Toolkit + Vulnerability Protection), cPanel WP Toolkit, Softaculous WordPress Manager, Installatron, GridPane UpdateSafely, aaPanel WordPress Toolkit Pro, Imunify Patch / Patchman
  - Prijedlog: Proširiti modul apps WordPress slojem oko wp-cli (instalirati wp-cli u installeru). Nove agent operacije: wp.plugins (list/install/activate/update/delete), wp.themes (isto), wp.vuln_scan (dohvat ranjivosti iz WPScan/wordfence-style feeda + checksum WpChecksums koji već postoji), wp.update (uz obavezni backup.vhost_create prije + post-update HTTP health probe + auto-rollback iz tog backupa, ista logika kao update orkestrator pogl. 9), wp.option_set za maintenance mode. Dovršiti WpInstall preko 'wp core install' (admin user/site title iz parametara) umjesto ostavljanja na /wp-admin/install.php. Nova tablica wp_instances (vhost_id, version, auto_update_policy ENUM('off','minor','all'), last_vuln_scan, risk_score) umjesto krhkih settings retaka. Smart Update: iskoristiti postojeći StagingClone za klon → wp update na klonu → screenshot diff preko AssistantController/Claude vision → blokiraj ako regresija.
  - Mapiranje: modules: apps, security · tables: wp_instances, backups, staging_envs, malware_scans · operations: WpInstall, WpChecksums, WpPlugins, WpThemes, WpVulnScan, WpUpdate, StagingClone
- **Staging: serialized-aware search-replace + push-to-production + diff + re-sync** `[M/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Plesk/cPanel WP Toolkit, CyberPanel, ServerAvatar, GridPane i Softaculous rade serialized-aware search-replace (wp-cli search-replace ispravlja duljine PHP serializiranih stringova), nude push-to-production (sync staging → produkcija) s diff pregledom prije primjene, te ponovnu sinkronizaciju (re-sync) staging↔produkcija. GridPane 'infinite staging' spaja production↔staging↔dev za bilo koju stranicu.
  - Tko: Plesk WP Toolkit, cPanel WP Toolkit, CyberPanel clone/stage/migrate, ServerAvatar Staging Area, GridPane infinite staging, Cloudways, Softaculous, Enhance clone-to-staging
  - Prijedlog: StagingClone trenutno koristi naivni str_replace($source_domain,$staging_domain) koji KORUMPIRA WP serializirane vrijednosti (PHP serialize stringovi nose duljinu pa promjena duljine domene razbija unserialize). Zamijeniti: za WP stranice koristiti 'wp search-replace --all-tables' (serialized-aware); za generičke PHP appove zadržati str_replace samo kao fallback uz upozorenje. Dodati novu agent operaciju staging.push (rsync staging→source httpdocs + serialized-aware search-replace staging_domain→source_domain u dumpu + import, sve uz obavezni backup.vhost_create produkcije prije) i staging.resync (ponovni klon source→staging). Dodati endpoint POST /vhosts/{id}/staging/{sid}/push i /resync u StagingController, te diff pregled (lista promijenjenih fileova preko 'rsync --dry-run -i') koji se prikaže prije push potvrde. Ažurirati staging_envs.last_sync pri svakom re-syncu.
  - Mapiranje: modules: apps · tables: staging_envs, backups · operations: StagingClone, StagingPush, StagingResync
- **Atomski (zero-downtime) Git deploy s release direktorijima i rollbackom** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Svi moderni DevOps paneli rade atomski deploy kao DEFAULT: build u novi release-<sha> direktorij → symlink switch (instant zero-downtime) → čuvanje N prethodnih releaseova → jednoklik rollback na bilo koji prethodni release. Traje povijest deploya. ForgePanel git modul radi 'git reset --hard' direktno u živi docroot (vidljiv polu-deployan sajt + nema rollbacka).
  - Tko: Laravel Forge, RunCloud (Atomic Deployment), Ploi.io, Moss.sh, Dokploy (rollbacks), Coolify
  - Prijedlog: Preraditi GitDeploy: umjesto reset --hard u httpdocs, klonirati/fetchati u $vhost/releases/<timestamp>-<sha>, izvršiti post-deploy korake unutar release dira, pa atomski 'ln -sfn releases/<x> current' i nginx docroot pokazuje na .../current (već postoji symlink switch obrazac u PanelSelfUpdate pogl. 9). Čuvati zadnjih N (npr. 5) releaseova, brisati starije. Nova operacija git.rollback (prebaci 'current' symlink na prethodni release zapis). Nova tablica git_deploys (repo_id, vhost_id, commit, release_path, status, deployed_at) za trajnu povijest + UI listu s rollback gumbom. shared/ direktorij (za .env, uploads) symlinkan u svaki release. Live progress već ide preko SSE (TaskContext->output), to zadržati.
  - Mapiranje: modules: git · tables: git_deploys, git_repos · operations: GitDeploy, GitRollback
- **Docker Compose import + volume management + privatni registry** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: CyberPanel, aaPanel Docker App Store, Coolify, Dokploy, Easypanel i CapRover pokreću compose-bazirane multi-container stackove (template marketplace je u pravilu compose), nude volume management (perzistentni podaci + volume backup) i login na privatni registry. ForgePanel instalira docker-compose-plugin ali ga ne koristi, podržava samo pojedinačne containere bez volumena i bez registry logina.
  - Tko: CyberPanel Docker Manager, aaPanel Docker App Store, Coolify, Dokploy, Easypanel, CapRover
  - Prijedlog: Nova agent operacija docker.compose_up (zapiše validirani docker-compose.yml u izolirani projektni dir, 'docker compose -p <proj> up -d'; portovi i dalje vezani na 127.0.0.1, nginx proxy preko postojećeg DockerProxyMap). Validacija compose fajla u agentu (whitelist ključeva, zabrana host network/privileged/bind-mount izvan dozvoljenih putanja — realpath provjera). Proširiti DockerCreate s '-v <named_volume>:<path>' (named volumes, ne proizvoljni host bind), te dodati docker.volume (create/list/rm) + uključiti volumene u BackupVhostCreate (docker volume export → tar). Nova operacija docker.registry_login (docker login s enkriptiranim credentialima iz nove tablice, isti Crypto sloj kao cloudflare_accounts.api_token). Tablica docker_compose_projects (vhost_id, project_name, compose_path, status).
  - Mapiranje: modules: docker, backup · tables: docker_containers, docker_compose_projects, docker_volumes, docker_registries · operations: DockerCreate, DockerComposeUp, DockerVolume, DockerRegistryLogin, BackupVhostCreate
- **Pravi app marketplace (dinamički katalog, kategorije, verzije) + potpuni uninstall** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Softaculous (450+ aplikacija), Webuzo (280+), Easypanel (273+), Coolify (280+) i aaPanel nude dinamičke kataloge sa stotinama aplikacija, kategorijama, logom/metapodacima, verzioniranjem i potpunom deinstalacijom (fileovi + baza). ForgePanel ima statički hardkodiran niz od 5 stavki, a uninstall briše samo settings zapis ne dirajući fileove/bazu.
  - Tko: Softaculous, Webuzo, Easypanel, Coolify, Dokploy, aaPanel App Store, CapRover one-click apps
  - Prijedlog: Zamijeniti const CATALOG dinamičkim katalogom: compose/template-bazirani manifesti (id, name, kategorija, logo, verzija, kind, needs_db, install_op) učitani iz modules/apps/catalog/*.json (lokalno, verzionirano u repu — bez vanjskog poziva radi sigurnosti), s mogućnošću remote sync iz potpisanog manifesta. Nova tablica app_catalog (cache) + namjenska tablica app_instances (vhost_id, app_type, version, status, installed_at, admin_url) umjesto settings key-value retaka (riješi praćenje statusa/verzije). Implementirati pravi uninstall: nova operacija apps.remove koja briše app fileove (realpath unutar vhost roota), drop bazu/usera preko db.delete, i ukloni systemd/nginx artefakte za node/ghost — uz obavezni backup prije. Kategorije i pretraga u UI katalogu.
  - Mapiranje: modules: apps · tables: app_catalog, app_instances · operations: AppInstall, AppRemove
- **Container-native build pipeline (Nixpacks/Railpack/Dockerfile) + preview deploy po PR-u** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Moderni PaaS paneli (Coolify, Dokploy, Easypanel, CapRover, Dokku) deploydaju iz git repozitorija s automatskom detekcijom build sustava (Dockerfile / Nixpacks / Railpack / Heroku/Cloud Native buildpacks) i nude efemerna preview okruženja po pull requestu (vlastiti FQDN, commit-based image tag, auto-teardown). ForgePanel git deploy radi samo klasični 'git pull u docroot' na fiksni branch.
  - Tko: Coolify (preview + Railpack/Nixpacks), Dokploy (preview + Nixpacks/Buildpacks), Easypanel, CapRover, Dokku
  - Prijedlog: Faza 1: dodati per-PR preview iskorištavajući postojeću staging infrastrukturu — GitController webhook na 'pull_request' event kreira preview vhost pr-<n>.<domena> (klon trenutnog deploya na taj branch, zaseban FPM pool), DELETE na zatvaranje PR-a (auto-teardown). Nova tablica preview_envs (git_repo_id, pr_number, vhost_id, branch, status). Faza 2 (opcionalno, za moderne apps): container-native build — nova operacija git.build_image koja detektira Dockerfile/nixpacks.toml i gradi image (nixpacks/railpack binarni alat instaliran kroz installer), zatim docker.create + DockerProxyMap. Build secrets (build-time) odvojeni od runtime env. Auto-registracija GitHub/GitLab webhooka iz panela (Quick Deploy toggle) jer GitController već generira webhook_secret.
  - Mapiranje: modules: git, docker, apps · tables: preview_envs, git_repos · operations: GitDeploy, GitBuildImage, DockerCreate, DockerProxyMap, StagingClone
- **Object/page cache stack per vhost (Redis object cache + nginx FastCGI page cache)** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: CyberPanel (LSCache), RunCloud Hub (RunCache: Redis object + nginx page + OPcache), SpinupWP (Redis object cache + full-page cache), GridPane (3-slojni cache), Cloudways (Breeze/Object Cache Pro + Varnish) nude jednoklik page/object cache na razini web servera, ne samo PHP plugin, s automatskom WP integracijom. ForgePanel ima redis-server u stacku ali bez ikakvog cache toggle/integracije per vhost.
  - Tko: CyberPanel, RunCloud Hub, SpinupWP, GridPane, Cloudways, aaPanel
  - Prijedlog: Nova agent operacija cache.toggle per vhost: (1) nginx FastCGI page cache — generirati fastcgi_cache_path + cache zone u vhost configu (whitelisted snippet, uz postojeći nginx -t + rollback iz pogl. 1), s bypass pravilima za wp-admin/logged-in cookie; (2) Redis object cache — kreirati zaseban Redis bazu/socket za vhost i (za WP) instalirati+aktivirati object-cache.php drop-in preko wp-cli. Toggle u websites modulu per vhost. Cache purge endpoint (cijela zona ili URL) koji se može povezati s postojećim Git auto-deploy (auto-purge nakon deploya, ima paralelu u cloudflare modulu). Nova tablica vhost_cache (vhost_id, page_cache BOOL, object_cache BOOL, redis_db).
  - Mapiranje: modules: websites, apps · tables: vhost_cache, vhosts · operations: CacheToggle, CachePurge
- **Izbor verzije runtimea per aplikacija (Node/Python) i Git post-deploy build koraci** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: DirectAdmin (nodejs_provider: distro/NodeSource/custom), CloudPanel (Node 20/22 LTS izbor), cPanel/Plesk (Node multi-verzija) i ServerAvatar (per-PHP-verzija ekstenzije, manualna instalacija verzija) nude izbor verzije runtimea per aplikacija. Git paneli nude bogate post-deploy build korake (npm/yarn build, cache clear, DB migracije), ne samo composer.
  - Tko: DirectAdmin, CloudPanel, Plesk, cPanel, ServerAvatar, Laravel Forge (deploy skripte), Ploi.io
  - Prijedlog: NodeApp/PythonApp trenutno nemaju izbor verzije (Node fallback na distro paket, Python na sistemski python3). Dodati parametar runtime_version: za Node instalirati traženu major verziju preko NodeSource (repo se već dodaje kroz installer) ili nvm-style per-app binarni put; za Python koristiti deadsnakes/pyenv za python3.X u venvu. Spremiti odabranu verziju u node/python app zapis. Paralelno proširiti GitDeploy POST_DEPLOY_WHITELIST s 'npm_build' (npm ci && npm run build kao vhost user), 'cache_clear' i 'db_migrate' (artisan/generic preko whitelistanih binarnih komandi, proc_open array args) — sve idempotentno i kao vhost user, uz nginx/health provjeru.
  - Mapiranje: modules: apps, git · tables: git_repos · operations: NodeApp, PythonApp, GitDeploy

### Platforma: multi-server, API-first, plugin SDK, installer, DX/UX (command palette, mobile, dark mode)

- **Multi-server izvrsenje (remote agent dispatch), ne samo registar** `[L/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Pravo orkestriranje vise servera iz jednog panela: server role (app/db/email/dns/backup) rasporedive ili konsolidirane, single-command enroll novog nodea koji nasljedjuje globalni config, inter-server migracija sajta s auto DNS updateom, mirror/failover. Enhance skalira 1->10.000+ servera s $0/server licencom; ISPConfig ima master-master + Dovecot sync; Coolify/Dokploy dispatchaju taskove na remote nodove preko SSH/Swarm agenta.
  - Tko: Enhance, ISPConfig 3, Coolify, Dokploy, Plesk 360, cPanel/WHM
  - Prijedlog: ForgePanel ima servers tablicu + enroll_token generiranje, ali AgentClient se spaja iskljucivo na lokalni /run/forgepanel/agent.sock i nijedan task se ne moze poslati na udaljeni node (potvrdjeno: grep server_id/remote/mTLS u AgentClient = prazno). Implementirati: (1) agent-side enrollment endpoint koji konzumira fpsrv_ token i uspostavlja mTLS bridge (TCP socket s klijentskim certom umjesto/uz UNIX socket); (2) AgentClient dobiva server_id parametar i routira na lokalni socket ILI remote mTLS tunel; (3) tasks tablica dobiva server_id kolonu, TaskWorker/Scheduler odlucuju ciljni node; (4) op 'server.enroll' + 'server.dispatch'. Pocetni cilj: per-server role flagovi na servers tablici (web/db/mail/dns) i ruting taska po roli. Failover/mirror je Faza 5+.
  - Mapiranje: modules: servers · tables: servers, tasks · operations: server.enroll, server.dispatch, agent.bridge
- **Plugin SDK end-to-end: agent ucitava module-registrirane op-codove + UI ucitavanje + (de)instalacija** `[L/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Ekstenzijski ekosustav kao platforma gdje treca strana registrira komponentu koja STVARNO izvrsava akcije: Plesk katalog (Docker/Node/Imunify/Grafana plug-and-play), Webmin 'sve je modul', ISPConfig Plugin/Extension Installer (UI+CLI), aaPanel/Webuzo app store. Modul deklarira operaciju i ona radi.
  - Tko: Plesk Obsidian, Webmin/Virtualmin, ISPConfig 3, aaPanel, Webuzo
  - Prijedlog: ModuleRegistry (web/src/Core) validira manifest (name==dir anti-spoofing, op-namespace, semver) i izlaze registeredOperations(), ALI agent OperationRegistry je HARDKODIRANI const array (agent/src/OperationRegistry.php linija 17) koji se NIGDJE ne puni iz ModuleRegistry -> operacija modula se ne moze izvrsiti. Implementirati: (1) agent pri startu skenira modules/*/manifest.json i dinamicki registrira <ime>.* op klase u whitelist (i dalje validirano: op klasa mora postojati u modules/<ime>/operations/, namespace zatvoren); (2) UI loader za modules/<ime>/ui/ isjecke; (3) op-i 'module.install'/'module.enable'/'module.disable' (kopija u modules/, manifest verify, agent reload); (4) UI ekran za upravljanje modulima u ModulesController. modules/ je trenutno prazan (samo README) -> isporuciti 1 referentni modul kao dokaz.
  - Mapiranje: modules: modules · tables: settings · operations: module.install, module.enable, module.disable
- **Atomski (zero-downtime) git deploy s release dir + symlink switch + jednoklik rollback** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Atomic/zero-downtime deploy kao DEFAULT: gradi novu verziju u release dir, pa symlink switch za instant prebacivanje; keep-N-releases; jednoklik rollback na bilo koji prethodni release; trajna povijest deploya; stacked/queued deployments; real-time deploy log.
  - Tko: Laravel Forge, RunCloud, Ploi.io, Moss.sh, SpinupWP, Coolify, Dokploy
  - Prijedlog: GitDeploy operacija (agent/src/Operations/GitDeploy.php) radi IN-PLACE 'git checkout -f -B branch FETCH_HEAD' direktno u docroot -> nema atomicnosti, nema rollbacka, deploy moze ostaviti site u polustanu. Preraditi na release model: checkout u /var/www/vhosts/<domena>/releases/<timestamp>, post-deploy akcije (composer install), pa atomski 'ln -sfn' current symlinka na novi release + reload FPM; cuvati zadnjih N releaseva. Dodati op 'git.rollback' (symlink na prethodni release). git_repos tablica dobiva kolone current_release/releases_kept. Deploy log vec ide preko SSE (TaskQueue) -> live output je rijesen. Uklapa se i u config time-machine filozofiju.
  - Mapiranje: modules: git, websites · tables: git_repos, tasks · operations: git.deploy, git.rollback
- **Generic odlazni webhook / event bus + UI za vise endpointa i chat kanali** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Notifikacije na Slack/Discord/Telegram/email/webhook za deploy i monitoring evente; CloudwaysBot proaktivni alarmi na email/Slack/custom; opci event bus za module. Forge/Ploi/Dokploy salju na chat kanale, registracija vise endpointa.
  - Tko: Dokploy, Cloudways, Laravel Forge, Ploi.io, ServerAvatar
  - Prijedlog: Odlazni webhookovi su trenutno jednosmjerni i jedan kanal: ProvisioningController.emitWebhook() salje samo na jedan provisioning_webhook iz settings, nema registracije vise endpointa ni chat kanala (potvrdjeno: nema slack/discord/telegram referenci u Core/Settings). Implementirati: (1) tablica webhook_endpoints (subscription_id, url, events JSON, secret, kanal ENUM('generic','slack','discord','telegram')); (2) WebhookDispatcher u Core koji emitira evente (deploy.finished, backup.failed, ssl.expiring, monitoring.alarm, malware.detected) uz postojeci anti-SSRF HttpClient::isSafePublicUrl; (3) Slack/Discord payload formatteri; (4) UI za registraciju u Settings/Notifications. notifications tablica vec postoji za in-app.
  - Mapiranje: modules: updates, monitoring · tables: notifications, webhook_endpoints, settings
- **Sluzbeni CLI alat za panel (forge-cli) preko REST API-ja** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: CLI za upravljanje cijelom infrastrukturom iz terminala uz REST API: Forge CLI, Ploi CLI, Coolify/Dokploy CLI+Swagger, ISPConfig/Froxlor/DirectAdmin CMD_API nasljede. DevOps publika to ocekuje za skriptiranje i CI/CD.
  - Tko: Laravel Forge, Ploi.io, Coolify, Dokploy, DirectAdmin
  - Prijedlog: Postoji samo install.sh, forge-agentd daemon i build-release.sh -- nijedan CLI za upravljanje panelom. ForgePanel je vec dosljedno API-first (227 ruta, scoped Bearer tokeni) pa je CLI tanki klijent: PHP PHAR ili mali Go/PHP binary 'forge-cli' koji cita FORGE_API_TOKEN + base URL, mapira podkomande (forge vhost create, forge db list, forge backup run, forge deploy) na /api/v1/ pozive, podrzava JSON output za skriptiranje. Distribuira se uz installer. Nula promjena na backendu -- cisti potrosac postojeceg API-ja. Veliki DX dobitak za skroman effort.
  - Mapiranje: tables: api_tokens
- **OpenAPI spec odrzavan uz cijeli API (227 ruta) + dostupnost klijentima/resellerima** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: API-first disciplina: SVAKA UI akcija dokumentirana u OpenAPI/UAPI s odlicnom dokumentacijom; Plesk je 2026. otvorio REST API i za customer/reseller racune (ne samo admin) -- veliki pomak za multi-tenant automatizaciju. cPanel docs.cpanel.net je industrijski zlatni standard.
  - Tko: cPanel/WHM, Plesk Obsidian, Coolify, Dokploy, Froxlor
  - Prijedlog: docs/openapi.yaml pokriva ~36 od 227 ruta (~16%, uglavnom Faza 1-2) -- nedostaju servers, provisioning, distribution, modules, docker, security, firewall, cloudflare, staging, licensing, node, python, migrator, bulk, delegation. Suprotno deklariranom 'API-first, OpenAPI uz kod' nacelu. Predlog: generator koji iz Router tablice ruta + Controller::requireScope() anotacija auto-emitira OpenAPI 3.1 paths/security scopes (vec imamo 40 scopeova na jednom mjestu u TokensController), CI provjera da svaka registrirana ruta ima spec unos. Token model je vec scoped i radi za klijente/resellere -> Pleskov potez samo potvrdjuje smjer.
  - Mapiranje: tables: api_tokens
- **Git/PR-driven preview deployments (efemerno okruzenje po pull requestu)** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Preview deployment po PR-u: efemerno okruzenje za svaki pull request s vlastitim FQDN-om/subdomenom, commit-based image tag, auto-teardown na merge/close, DELETE API za teardown. Killer feature modernih PaaS-ova za agencije/dev timove.
  - Tko: Coolify, Dokploy, Vercel-class PaaS
  - Prijedlog: ForgePanel ima StagingClone op (klon fileova+baze na staging subdomenu sa search-replace) i univerzalni staging differentiator, ALI nema git/PR automatizacije (potvrdjeno: nema preview/pull_request referenci u Git/Staging). Nadograditi: webhook na PR open -> auto StagingClone na pr-<n>.<domena> subdomenu s AutoSSL i zasebnim FPM poolom; na PR close/merge -> auto teardown (vhost.delete). git_repos dobiva preview_enabled flag; staging_envs tablica vec postoji za vezivanje source<->staging. Gradi se na vec implementiranom StagingClone + inbound git webhook + DomainProvision.
  - Mapiranje: modules: git, staging, websites · tables: git_repos, staging_envs, subdomains · operations: staging.clone, vhost.create, vhost.delete
- **MCP server nad ForgePanel resursima za AI/agentsku integraciju** `[M/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Read-only Model Context Protocol server za panel resurse (metrike, logovi, status) da AI asistent/eksterni agenti citaju kontekst i predlazu: Coolify instance-level MCP, sluzbeni aaPanel MCP server. 2025/2026 trend agentske integracije.
  - Tko: Coolify, aaPanel
  - Prijedlog: ForgePanel ima assistant modul (AnthropicClient, Assistant op-i) koji cita kontekst interno, ali nema MCP server koji EKSTERNI agenti/Claude Desktop mogu koristiti. Dodati lagani MCP endpoint (read-only) koji izlaze ForgePanel resurse kao MCP alate (list_vhosts, get_metrics, tail_log, get_service_status) mapirane na postojece /api/v1 + System(Metrics/Top)/ServiceStatus op-e, autoriziran scoped tokenom (npr. novi scope 'mcp:read'). Sve citanje, nikakve mutacije -- u skladu s 'asistent samo cita i predlaze' filozofijom. Niska cijena jer su podaci vec izlozeni kroz API.
  - Mapiranje: modules: assistant, monitoring · tables: api_tokens · operations: system.metrics, service.status

### AI asistent, config time-machine, automatizacija, scheduled tasks, IaC/blueprints

- **Config time-machine ne snapshota vecinu komponenti (samo nginx)** `[M/high]` — status ForgePanel: *partial*
  - Konkurenti imaju: Konkurenti ne nude pravi config time-machine, ali ForgePanel spec 14.3 obecava da se SVAKA promjena bilo kojeg configa verzionira. Trenutno je auto-snapshot ozicen samo iz NginxConf.php. Webmin nudi config editing bez verzioniranja, GridPane/Plesk imaju activity/audit log ali ne diff+restore configa — ForgePanel ovdje moze biti jasan lider tek kad pokrije sve writere.
  - Prijedlog: Pozvati ConfigGit::snapshot() iz SVIH config writera: ApacheConf, MailConf (Postfix/Dovecot), BindConf, ProftpdConf, Fail2ban. Najbolje centralizirati: dodati ConfigGit::snapshotAfter($component, $changed_by, $msg) helper koji writeri pozivaju u finally bloku, te proslijediti stvarni AuthContext user kroz task params (op→params.actor) da changed_by nije uvijek 'agent'. Vezati commit hash na audit_log redak (dodati config_version_hash u audit_log ili novi stupac u config_versions s audit_log_id).
  - Mapiranje: modules: websites, mail, dns, ftp, firewall · tables: config_versions, audit_log · operations: config.history, *.apply
- **Scheduled backupi + retencijska rotacija (mrtva backup_schedules tablica)** `[M/high]` — status ForgePanel: *none*
  - Konkurenti imaju: JetBackup, Softaculous, Installatron, CloudPanel, SpinupWP, cPanel — svi imaju zakazane backupe s retencijskom rotacijom (7 dnevnih/4 tjedna/3 mjeseca) i multi-destinaciju. Cloudron/Enhance imaju zakazane diferencijalne backupe. ForgePanel ima samo on-demand POST /vhosts/{id}/backups.
  - Prijedlog: Ostvariti backup_schedules: scheduler job 'backup' (npr. every 5min provjerava due rasporede po cron izrazu/intervalu), enqueue task op backup.vhost.create za svaki due vhost na zadanu destinaciju, pa retencijska rotacija (keep N dnevnih/tjednih/mjesecnih, brisanje viska preko backup.delete). Dodati BackupScheduleController CRUD i polja schedule(cron), retention_daily/weekly/monthly, destination_id, last_run_at. Iskoristiti postojeci hardlink/manifest format.
  - Mapiranje: modules: backup · tables: backup_schedules, backup_destinations, backups, tasks · operations: backup.vhost.create, backup.delete
- **Korisnicki definiran event-driven automatizacijski/rule engine** `[L/high]` — status ForgePanel: *none*
  - Konkurenti imaju: Coolify/Dokploy (deploy webhooks, schedule jobs s log entryjem), CloudwaysBot i Acronis (alarm→akcija), RunCloud Auto Healing (pad servisa→auto restart), Enhance/Cloudways SmartFix (problem→jednoklik fix). Generalno: 'kad se desi X, napravi Y' bez koda. ForgePanel ima samo hardkodiran fiksni scheduler i odlazni provisioning webhook.
  - Prijedlog: Dodati tablicu automation_rules (id, subscription_id, trigger_type ENUM('metric_threshold','service_down','ssl_expiry','task_failed','cron_event','schedule'), trigger_params JSON, action_op, action_params JSON, enabled). Scheduler/alarms job evaluira pravila i enqueue-a akciju kao standardni task (samo whitelistane op-kodove — zadrzava agent-whitelist sigurnost). Pocetni setovi: 'service down→service.reload', 'disk<15%→notify+cleanup', 'malware found→quarantine+notify'. UI rule builder u modulu monitoring/security.
  - Mapiranje: modules: monitoring, security, updates · tables: automation_rules, tasks, notifications · operations: service.reload, quarantine.restore, alarm.test
- **AI predictive/auto-triage i jednoklik SmartFix** `[M/med]` — status ForgePanel: *partial*
  - Konkurenti imaju: Cloudways Copilot (root-cause dijagnostika + SmartFix jednoklik popravci), aaPanel AI (predictive analytics — predvidja buduce bottleneckove iz trendova, conversational troubleshooting), Webdock on-prem RAG, CyberPanel AI Scanner (real-time WP malware). ForgePanel AI ulazi tek na rucni klik (ai_prompt hand-off), dashboard insights su SQL pravila.
  - Prijedlog: Prosiriti dashboard insights da AI generira preporuke proaktivno (scheduler job 'ai_insights' periodicno salje agregirane metrike/trendove asistentu i sprema rezultat u notifications/dashboard cache) — uz opt-in jer trosi tokene. Dodati 'SmartFix' UX: na svaku AI preporuku ponuditi gumb koji predlaze KONKRETNU whitelistanu agent operaciju (service.reload, cleanup, ssl.issue) koju admin potvrdi — zadrzava human-in-the-loop. Trend forecasting nad monitoring_metrics (linearna regresija na disk/CPU). Razmotriti opcionalni on-prem model (kao Webdock RAG) uz Claude API za GDPR-osjetljive.
  - Mapiranje: modules: assistant, monitoring · tables: monitoring_metrics, notifications, settings · operations: assistant.query, service.reload
- **Atomic/zero-downtime git deploy s instant rollbackom** `[M/high]` — status ForgePanel: *weak*
  - Konkurenti imaju: Laravel Forge, RunCloud, Ploi, Moss, Dokploy, Easypanel — svi imaju atomic deploy (release dir→symlink switch) kao DEFAULT, keep-N-releases, jednoklik rollback na prethodni release, real-time deploy log. ForgePanel GitDeploy radi in-place pull bez release dirova ni rollbacka.
  - Prijedlog: Preraditi GitDeploy u release model: deploy u releases/<timestamp> (ili <sha>), symlink current→release nakon uspjesnih post-deploy akcija, cuvati N zadnjih releaseova, op git.rollback prebacuje symlink na prethodni release. Real-time output vec ide preko SSE task loga. Dodati git_repos.deploy_mode ENUM('inplace','atomic'), keep_releases INT, current_release. Prosiriti POST_DEPLOY_WHITELIST s 'cache_clear' (spec 6.10).
  - Mapiranje: modules: git · tables: git_repos, tasks · operations: git.deploy, git.rollback
- **IaC: blueprint/template eksport vhosta + declarative apply** `[L/med]` — status ForgePanel: *none*
  - Konkurenti imaju: Coolify (third-party Terraform provider, 33 resursa), Dokploy/Easypanel (compose-based template marketplace, REST+CLI), Enhance (single-command server deploy koji nasljedjuje globalni config, CLI), Forge/Ploi CLI. DevOps publika ocekuje 'apply desired state' i template-as-code. ForgePanel provisioning je cisto imperativan.
  - Prijedlog: Dodati blueprint sloj: GET /vhosts/{id}/blueprint izvozi deklarativni JSON/YAML opis (php_version, web_backend, baze, mail, dns zona, cron, ssl politika, kvote) bez tajni. POST /blueprints/apply prima blueprint i orkestrira postojece op-kodove da dovede sustav u zeljeno stanje (idempotentno: kreiraj sto fali, preskoci sto postoji, dry-run mod kao migrator). Temelj za buduci Terraform provider i CLI. Tablica blueprints (subscription_id, name, spec JSON) za spremljene predloske.
  - Mapiranje: modules: websites, provisioning · tables: blueprints, vhosts, plans · operations: vhost.create, db.create, dns.zone.write, cron.sync
- **Eksport u cPanel-kompatibilnu strukturu (anti vendor-lock)** `[M/med]` — status ForgePanel: *none*
  - Konkurenti imaju: cPanel pkgacct/cpmove je industrijski format koji i Enhance (download/upload backupa za prenosivost) i Webuzo postuju. ForgePanel spec 0/11/14.12 eksplicitno obecava eksport, ali implementiran je SAMO uvoz (cPanel/Plesk parseri).
  - Prijedlog: Dodati agent op account.export.cpanel koji sklapa cpmove-kompatibilan tar (homedir, mysql dumpovi, mail Maildir, DNS zone, alias fajlovi) iz ForgePanel entiteta jednog vhosta/subscriptiona. Iskoristiti postojeci backup engine i obrnuti mapper od MigratorCpanelParse. MigratorController dobiva POST /export endpoint s dry-run popisom sadrzaja. Ispunjava deklariranu anti-vendor-lock filozofiju.
  - Mapiranje: modules: migrator, backup · tables: vhosts, subscriptions, backups · operations: account.export.cpanel, backup.vhost.create
- **Scheduled deployments + cron heartbeat (dead-man's-switch)** `[S/med]` — status ForgePanel: *weak*
  - Konkurenti imaju: Ploi (scheduled deployments u maintenance prozor), Forge (Heartbeats za cron/worker monitoring — alarm ako cron NE ode), Dokploy (schedule jobs s log entryjem po izvrsenju). ForgePanel cron izvrsava jobove ali ne prati izostanak izvrsenja niti zakazuje deploye.
  - Prijedlog: Dodati cron heartbeat: cron_jobs dobiva expected_interval_s i last_success_at; scheduler 'cron_watch' job alarmira ako job nije javio izvrsenje unutar prozora (dead-man's-switch). Zakazani deploy: git.deploy enqueue u maintenance window (reuse update orkestrator logike). Opcionalno output zadnjih izvrsavanja vec postoji u cron modulu — dodati success/fail status i notifikaciju.
  - Mapiranje: modules: cron, git, monitoring · tables: cron_jobs, notifications, tasks · operations: cron.sync, git.deploy