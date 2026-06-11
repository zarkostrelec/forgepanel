# ForgePanel — arhitektura (Faza 1)

## Tri sloja

```
WEB (:8443, nginx + PHP-FPM 8.4, user fpanel, bez roota)
  │  UNIX socket /run/forgepanel/agent.sock (root:fpanel 0660)
  │  JSON: {"op": "vhost.create", "params": {...}}
AGENT (forge-agentd, PHP CLI daemon, root, systemd hardening + watchdog)
  │  whitelist operacija, validacija parametara, proc_open array-only
SUSTAV (nginx, PHP-FPM poolovi, MariaDB, ufw, fail2ban, ...)
```

## Tok zahtjeva

1. UI/API klijent šalje `POST /api/v1/vhosts` s Bearer tokenom.
2. Web sloj autenticira (session ili API token + scope), provjerava vlasništvo
   po `subscription_id` (middleware u `AuthContext`, ne ručno po endpointima)
   i limite plana.
3. Kratke operacije → sinkroni agent poziv (`AgentClient::call`).
   Dugotrajne → red u tablicu `tasks`; agent ih obrađuje asinkrono,
   UI prati progress preko SSE (`GET /api/v1/tasks/{id}/stream`).
4. Agent validira parametre (FQDN regex, realpath unutar `/var/www/vhosts/`),
   izvršava whitelistanu operaciju, loggira u `audit_log`.

## Agent protokol

- Request (jedna linija JSON + `\n`): `{"op": string, "params": object}`
- Response: `{"ok": bool, "data"?: object, "error"?: string}`
- Nepoznat op-code → `unknown_operation` + audit zapis.
- Operacije s `isLongRunning() === true` socket odbija (`use_task_queue`).

## Izolacija vhosta

Svaki vhost: sistemski user `vh_<id>`, vlastiti PHP-FPM pool (ondemand,
open_basedir, disable_functions, vlastiti tmp), nginx server block,
cgroup v2 kvote (CPUQuota/MemoryMax/TasksMax drop-in na FPM unit).

## Config sigurnost

Svaki config koji panel piše: syntax test (`nginx -t`, `php-fpmX.Y -t`)
prije reloada + automatski rollback na prethodnu verziju ako test padne
(`NginxConf::writeAndReload`, `PhpFpm::writePool`).

## AutoSSL

Vlastiti ACME klijent (`agent/src/System/Acme.php`, RFC 8555, http-01).
Svaki novi vhost automatski dobiva Let's Encrypt cert; do izdavanja služi
privremeni self-signed. Obnova 30 dana prije isteka (cron orkestracija — Faza 2).
