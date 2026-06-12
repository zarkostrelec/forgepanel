# ForgePanel — Sigurnosni pregled

Zapis sigurnosnog audita i otvrdnjavanja. Dopunjuje poglavlje 8 CLAUDE.md-a.

## Potvrđeno dobro (nije mijenjano)

- **Auth**: argon2id (memory 64 MiB, time 4), `hash_equals` za sve token usporedbe,
  identičan odgovor za nepostojeći mail i krivu lozinku (bez enumeracije), lockout s
  eksponencijalnim backoffom, session token se sprema kao SHA-256, session binding na IP+UA.
- **Agent**: whitelist operacija, `proc_open` isključivo s array argumentima (nema shell
  interpolacije), stroga FQDN/identifier/path validacija PRIJE izvršavanja, `realpath`
  provjera vhost putanja protiv symlink trikova, socket 0660 root:fpanel.
- **DB**: svi upiti idu kroz prepared statements (placeholderi), uključujući dinamičke
  `IN (...)` liste (generiraju se samo `?` placeholderi, vrijednosti su vezane).
- **Multi-tenant izolacija**: `AuthContext::vhostOr404` / `requireSubscription` u
  middleware sloju — tuđi resurs vraća 404, delegirani pristup traži eksplicitnu permisiju.
- **Klijentski terminal**: izolirani Docker container (`--network none`, `--read-only`,
  memorija/CPU/pids limiti, mount samo vhost roota, izvršavanje kao vhost user).

### Provjereni lažni alarmi

- "Domain → systemd/Apache config injection" (NodeApp, ApacheConf): nemoguće — `Validator::fqdn`
  je usidren regex koji dopušta samo `[a-z0-9-]` i točke, bez razmaka/novih redaka.
- "FTP/mail `crypt()` SHA-512 umjesto argon2id": namjerno — Dovecot i ProFTPD verificiraju
  isključivo crypt-format hasheve, argon2id bi razbio mail/FTP autentikaciju.

## Otvrdnjavanje primijenjeno u ovom prolazu

1. **Crypto** (`web/src/Core/Crypto.php`): `app_secret` se više NE smije tiho srozati na
   poznati default — prazan, default ili kraći od 16 znakova baca iznimku. Sprječava tihi pad
   svih enkriptiranih tajni (CF/Anthropic tokeni, DB lozinke) na javno poznat ključ.
2. **Odlazni HTTP** (`web/src/Core/HttpClient.php`, novi): jedinstvene otvrdnute cURL opcije —
   obavezna TLS verifikacija peera i hostnamea, samo `https` na zahtjevu i redirectima, bez
   automatskog praćenja redirecta. Primijenjeno na Cloudflare, Anthropic i billing webhook.
3. **Anti-SSRF na webhooku** (`ProvisioningController`): admin-konfigurirani URL mora biti
   `https` i NE smije ciljati loopback/private/link-local/metadata IP (npr. 169.254.169.254).
4. **Brute-force na drugom faktoru** (`web/src/Core/Auth.php`): TOTP i WebAuthn login sada
   dijele isti lockout kao lozinka (neuspjesi se broje, račun se zaključava, uspjeh resetira).
5. **Sigurnosni headeri** (`web/src/Core/Response.php`): CSP dobio `base-uri 'self'`,
   `form-action 'self'`, `object-src 'none'`, `upgrade-insecure-requests`; HSTS
   `includeSubDomains; preload`; dodani CORP `same-origin` i `X-Permitted-Cross-Domain-Policies: none`.
6. **Agent TLS** (`System/Acme.php`, `Scheduler.php`): eksplicitna TLS verifikacija na ACME
   izdavanju certa i na provjeri dostupnosti `resolute` suite-a (MITM ne smije lažirati izdavanje
   certa ni okinuti apt suite switch). Uptime probe namjerno ne validira cert (mjeri dostupnost).
7. **systemd hardening** (`forge-agentd.service`): dodani `ProtectKernelLogs`, `ProtectClock`,
   `ProtectHostname`.

## Preporuke za odluku (nisu mijenjane autonomno)

- **Provisioning ownership**: `provisioning/account/*` endpointi rade na bilo kojem
  `subscription_id` ako token ima scope `provisioning:write`. Scope je admin-dodijeljen pa je
  realna izloženost mala, ali ako se taj scope ikad da reselleru, treba dodati provjeru
  vlasništva (admin ILI vlastiti subscription). Nije promijenjeno da se ne razbije WHMCS/Blesta
  integracija — odluka na vlasniku.
- **phpMyAdmin signon token u query stringu**: jednokratan i 60 s TTL (potroši se odmah,
  race-safe kroz `rowCount`), pa je izloženost niska; idealno bi bio POST body.
