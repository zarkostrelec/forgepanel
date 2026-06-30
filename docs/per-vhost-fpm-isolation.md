# Per-vhost izolacija: dedicirani FPM servis + cgroup kvote + disk project quota

Ovo je tehnička bilješka uz promjenu koja "kvote bez CloudLinuxa" pretvara iz
iluzije u stvarni mehanizam. **Dira systemd/php-fpm/filesystem i MORA se validirati
na čistom Ubuntu 26.04 (multipass/LXD) prije produkcijskog `./deploy.sh`** (pravilo
iz CLAUDE.md: agent/installer izmjene ispravne su tek kad prođu na netaknutom serveru).

## Zašto

PHP-FPM poolovi pod DIJELJENIM `php<ver>-fpm.service` masterom dijele jedan cgroup.
Stari kod je pisao cgroup drop-in (`CPUQuota/MemoryMax/TasksMax`) na taj dijeljeni
servis pod fiksnim imenom datoteke → svaki novi vhost je **prepisivao** limit i taj
limit je vrijedio za CIJELU PHP verziju (svi vhostovi te verzije). Disk kvota se
nije primjenjivala nigdje. Multi-tenant izolacija de facto nije postojala.

## Što sada radi

Svaki vhost ima **vlastiti dedicirani FPM master kao zaseban systemd servis**:

```
forge-fpm-vh_<id>.service   →  /usr/sbin/php-fpm<ver> --nodaemonize --fpm-config \
                                /etc/forgepanel/fpm/vh_<id>/php-fpm.conf
```

- **Zaseban cgroup** (`/sys/fs/cgroup/system.slice/forge-fpm-vh_<id>.service`) → per-vhost
  `CPUQuota/MemoryMax/TasksMax` kroz systemd drop-in (`…service.d/forgepanel-quota.conf`),
  vrijede PO VHOSTU. Per-vhost CPU/RAM metrike (Scheduler → `scope=vhost:<id>`) sad rade.
- **Socket path NEPROMIJENJEN** (`/run/php/fpm-vh_<id>.sock`) → nginx/Apache config ostaje isti.
- **Disk project quota** (ext4/xfs, `projid = vhost_id`, hard limit = `plan.disk_bytes`),
  best-effort u `Fs::setProjectQuota` (no-op ako fs nije montiran s `prjquota`).

Servis je keyiran na `sys_user` (NE na PHP verziju) — promjena verzije samo prepiše
master conf i restarta isti servis (`VhostPhpSet` više ne zove `removePool`).

## Preduvjet za disk kvotu (ručno / po želji)

Disk project quota traži da fs s `/var/www/vhosts` bude montiran s project quotom:

- **ext4:** `tune2fs -O project,quota /dev/<dev>` (fs unmounted) → mount opcija `prjquota`
  u `/etc/fstab` → reboot. Paket `quota` (setquota) instalira installer.
- **xfs:** mount opcija `pquota` (zahtijeva reboot za root fs).

Bez toga `setProjectQuota` vraća false i panel normalno radi (samo bez disk enforcementa).
CPU/RAM kvote ne ovise o ovome (cgroup, bez fs-a).

## Validacija na VM-u (obavezno prije deploya)

Na čistom Ubuntu 26.04 (multipass/LXD), nakon instalacije panela:

1. Kreiraj vhost s planom koji ima CPU/RAM/Tasks/disk limite.
2. `systemctl status forge-fpm-vh_<id>` → active (running), `Type=notify` se javio.
3. `ls -l /run/php/fpm-vh_<id>.sock` → postoji; stranica se servira (curl).
4. `systemctl show forge-fpm-vh_<id> -p MemoryMax,CPUQuotaPerSecUSec,TasksMax` → limiti plana.
5. `cat /sys/fs/cgroup/system.slice/forge-fpm-vh_<id>.service/memory.current` → > 0 nakon zahtjeva.
6. Promijeni PHP verziju vhosta → isti servis, novi `php-fpm<new>`; stranica i dalje radi.
7. Obriši vhost → servis + `/etc/forgepanel/fpm/vh_<id>` + socket nestaju.
8. (ako je prjquota uključen) `repquota -P <mount>` → projid `<id>` ima hard limit.
9. Klijentski dashboard: grafovi potrošnje vlastitog vhosta (scope `vhost:<id>`) se pune;
   klijent NE može dohvatiti tuđi `vhost:<id>` (404).

## Migracija postojećih vhostova

Postojeći vhostovi (stari model, pool u dijeljenom `pool.d`) nastavljaju raditi
NEPROMIJENJENO nakon deploya. Migriraju se na dedicirani servis kad ih se "dotakne"
(promjena PHP verzije/postavki — `writePool` tada makne legacy pool i podigne servis).
Za potpuni backfill (uklj. cgroup limite) postojećih vhostova preporuča se admin
bulk akcija koja re-applya izolaciju (re-`writePool` + `setLimits` iz plana) — zaseban
zadatak, izvesti nakon validacije na VM-u.
