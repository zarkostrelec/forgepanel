# Handoff: ForgePanel — web server control panel

## Overview
ForgePanel je moderni web control panel za upravljanje serverom (kategorija: Plesk / cPanel / Webmin, ali developer-first). Pet ekrana: Pregled (dashboard), Siteovi, Datoteke (file manager + code editor + terminal), Monitoring i SSL · DNS · Sigurnost. Ključni UX koncepti: command palette (⌘K), diskretni AI asistent "Forge AI" (⌘J), keyboard-first navigacija (G + slovo), live metrike i animirana topologija servera.

## About the Design Files
Datoteke u `design/` su **dizajn-reference izrađene u HTML-u + React (Babel standalone)** — prototip koji pokazuje izgled i ponašanje, **ne produkcijski kod za direktno kopiranje**. Zadatak je **rekreirati ove dizajne u ciljnom codebaseu** koristeći njegove postojeće paterne i biblioteke (npr. Next.js/React + Tailwind, Vue, Svelte…). Ako projekt još nema okruženje, odaberi najprikladniji stack (preporuka: React + TypeScript + Tailwind ili CSS varijable kako su definirane u `tokens.css`).

Otvori `design/ForgePanel.html` u browseru da vidiš živi prototip. `design/forgepanel/tweaks-panel.jsx` je alat za prototipiranje (panel za live podešavanje) — **ne implementirati**, ali tweakovi koje omogućuje (akcent boja, svijetla/tamna tema, gustoća) trebaju postojati kao korisničke postavke.

## Fidelity
**High-fidelity (hifi).** Boje, tipografija, razmaci, radijusi i interakcije su finalni. Rekreiraj pixel-perfect, ali kroz komponente i konvencije ciljnog codebasea. Svi podaci u prototipu su mock (vidi `forgepanel/data.js`) — u implementaciji dolaze s backenda.

## Design Tokens (izvor istine: `design/forgepanel/tokens.css`)

### Fontovi
- UI: **Instrument Sans** (400–700), fallback `system-ui, sans-serif`
- Mono (brojevi, kod, IP-ovi, terminal): **IBM Plex Mono** (400–600)
- Svi numerički podaci koriste klasu `.num` → mono font + `font-variant-numeric: tabular-nums`

### Boje — svijetla tema (default)
| Token | Vrijednost | Upotreba |
|---|---|---|
| `--bg` | `#f4f4f1` | pozadina aplikacije (toplo-neutralna) |
| `--surface` | `#ffffff` | kartice, tablice, topbar |
| `--surface-2` | `#fafaf7` | hover redaka, sekundarne plohe |
| `--surface-3` | `#f1f0ec` | kbd, ikonske pozadine |
| `--ink` | `#1a1c21` | primarni tekst |
| `--ink-2` | `#5b5e66` | sekundarni tekst |
| `--ink-3` | `#989ba4` | tercijarni tekst, labele |
| `--line` | `#e7e6e1` | rubovi kartica |
| `--line-2` | `#f0efeb` | razdjelnici redaka |
| `--line-strong` | `#d8d7d1` | scrollbar, topologija rubovi |

### Tamni "chrome" (rail, file tree, editor, terminal — taman i u svijetloj temi!)
| Token | Vrijednost |
|---|---|
| `--chrome` | `#0e1116` |
| `--chrome-2` | `#151922` |
| `--chrome-3` | `#1c212c` |
| `--chrome-line` | `#262c38` |
| `--chrome-ink` | `#c3c7d1` |
| `--chrome-ink-2` | `#767d8b` |

### Akcent i statusne boje (oklch — akcent hue je korisnička postavka)
- Akcent (emerald, default): `oklch(0.62 0.125 163)`; deep: `oklch(0.49 0.11 163)`; soft pozadina: `oklch(0.95 0.028 163)`
- Alternativni akcenti: hue 250 (plava), 300 (ljubičasta), 75 (jantarna) — ista lightness/chroma
- Status: ok = akcent; warn `oklch(0.68 0.125 75)`; danger `oklch(0.58 0.14 25)`; info `oklch(0.58 0.1 250)` + njihove `-soft` varijante za badge pozadine

### Tamna tema
Kompletan set overridea u `tokens.css` pod `html[data-theme="dark"]` (bg `#0b0d11`, surface `#12151b`…). Tema se prebacuje atributom na `<html>`.

### Dimenzije
- Font: base 13px, sm 12px, xs 11px (compact gustoća: 12.5/11.5/10.5 — atribut `data-density="compact"`)
- Radius: kartice 10px, manji elementi 7px; tablice: padding ćelija `9px 12px` (compact 6px)
- Sjene: `--shadow-1` (kartice, suptilna), `--shadow-2` (editor), `--shadow-pop` (modalni overlay — command palette)
- Razmak između kartica (`--gap`): 14px (compact 10px)

## Screens / Views

### Layout shell (svi ekrani)
- **Rail** (lijevo, 56px, `--chrome`): logo kvadrat 32px (akcent bg, radius 8, "zap" ikona, blagi glow), 5 nav ikona 40×38 (aktivna: `--chrome-3` bg + 2.5px akcent traka lijevo), tooltip na hover s kraticom ("G D"), dolje terminal ikona + avatar 30px (gradijent emerald→plava, inicijali).
- **Topbar** (48px, `--surface`, donji rub `--line`): pulsirajuća zelena točka + ime servera (bold) + IP (mono, sivo) + breadcrumb; desno search-pill 320×30px ("Naredba, site, datoteka, akcija…" + ⌘K kbd), gumb "Forge AI" (sparkle ikona; aktivan = akcent border + soft bg), zvonce.
- **Sadržaj**: padding 14px, skrolabilan (osim ekrana Datoteke koji je fiksne visine).

### 1. Pregled (dashboard)
- **Red 1**: 5 metričkih kartica u gridu (CPU, RAM, Disk, Mreža, Zahtjevi/s) — uppercase xs labela, vrijednost 22px mono, sub-tekst xs, desno živi sparkline 84×30 (linija 1.6px + gradijent fill + točka na zadnjoj vrijednosti). Podaci se osvježavaju ~1.4s.
- **Red 2** (grid 1.65fr/1fr): **Topologija servera** — SVG dijagram čvorova (Internet → Cloudflare → nginx LB → php-fpm / node·pm2 / apache2 → pg + redis). Čvor = zaobljeni rect 44px visine, statusna točka, naziv (11px bold), sub (mono 9.5px). Veze: tanka siva linija + animirana isprekidana akcent linija (dash 3/13, animacija pomaka, ~1.1s loop); degradirane veze bez animacije. Desno: **Događaji uživo** — feed s mono timestampom, ikonom po tipu (deploy/security/ssl/system/backup/db) obojanom po severityju, tekst sm.
- **Red 3**: tablica **Servisi** (naziv+verzija, status badge, CPU%, RAM, uptime, port; akcije na hover retka: restart/logovi/više). Desno: **Forge AI kartica s preporukama** (akcent border + soft gradijent bg, sparkle ikona u akcent kvadratu, 2 CTA gumba) i **Zadnji deployi** (check/x ikona, site, #broj · sha mono, vrijeme).

### 2. Siteovi
- Toolbar: filter pilule (svi / live / problemi s warn brojčanikom), brojač desno, primarni gumb "Novi site".
- Glavna tablica: statusna točka + ime (bold) + tip ispod (xs sivo), stack (mono), promet + trend (zeleno/crveno), SSL badge (`TLS · 64d` ok / `istječe 9d` warn), deploy (branch ikona + grana + vrijeme), disk. Klik na redak → selekcija (akcent-soft pozadina).
- Desni detaljni panel (340px): kartica site-a (ime + pulsirajuća točka, stack mono, sparkline prometa 24h, 2×2 mini-statovi, gumbi Deploy/Datoteke/SSH), kartica **Domene i SSL** (popis domena s lock badgeom, "Dodaj domenu"), kartica **Brze radnje** (Očisti cache, Restartaj PHP pool, Backup, Maintenance mode — redovi s chevronom).

### 3. Datoteke (file manager + editor + terminal)
Cijeli ekran je tamni blok (chrome boje), radius 10, bez unutarnjeg skrola stranice:
- **Stablo** (230px, `--chrome`): header s imenom site-a + akcije; direktoriji s chevronom i folder ikonom, datoteke s kvadratićem u boji tipa (php ljubičasta, json jantarna, yml plava, env crvena), modificirane datoteke → narančasta točka, `.env` → lock ikona. Aktivna datoteka: `--chrome-3` bg.
- **Editor**: tab bar (aktivni tab: 2px akcent linija gore, svjetliji bg; tab ima x za zatvaranje), desno git status (`main · 1 izmjena`) + primarni gumb "Spremi i deployaj". Kod: brojevi linija (mono, 46px desno poravnato, 60% opacity), syntax highlighting (keywords ljubičasta, stringovi jantarna, varijable plava, komentari sivo), aktivna linija s akcent-tintom pozadine + trepćući kursor.
- **Terminal** (dno): naslovna traka s "TERMINAL" + ssh konekcija (mono) + ⌃` kbd; sadržaj mono 12px, prompt `$`, zeleni ✓ output, trepćući blok-kursor.

### 4. Monitoring
- Toolbar: vremenske pilule (15m/1h/6h/24h/7d/30d, aktivna akcent), "streaming · 2s" live indikator, gumb Izvoz.
- Grid 2×2 velikih grafova (140px visine, linija 1.8px, gradijent fill, 4 horizontalne grid-linije): **CPU** (+ 8 per-core mini barova s postocima; >65% = warn boja), **Memorija** (info boja + legenda aplikacije/cache/slobodno), **Mreža** (dva preklopljena grafa: ↓ akcent, ↑ info), **Vrijeme odgovora** (warn boja + 4 kartice 2xx/3xx/4xx/5xx sa statusnim točkama).
- Ispod (1.5fr/1fr): tablica **Top procesi** (PID mono desno, proces mono, korisnik, CPU%, RAM, CPU bar) i **Aktivna upozorenja** (pravilo + target → kanal, toggle; footer sa zadnjim okidanjem).

### 5. SSL · DNS · Sigurnost
Tab navigacija (ikona + label, aktivni: 2px akcent podcrta; SSL tab ima warn badge "1"):
- **SSL certifikati**: tablica (domena, izdavatelj, istječe + dani — crveno ako <14d, auto-renew badge, status badge); redak koji istječe ima warn-soft pozadinu i inline gumb "Obnovi sad".
- **DNS zona**: tablica zapisa (tip badge obojan po tipu, ime mono bold, vrijednost mono, TTL, Cloudflare proxy toggle); footer "DNSSEC aktivan · SPF, DKIM i DMARC ispravno konfigurirani".
- **Firewall**: lijevo popis pravila (shield ikona — akcent kad je uključeno, naziv + mono detalj, toggle; **toggleovi su interaktivni**); desno statistika 24h (4 mini-kartice s brojkama) + Forge AI prijedlog.
- **Pristup**: SSH ključevi (ime, tip · zadnje korištenje; stari ključ → crvena ikona + "Opozovi" danger gumb) i API tokeni (ime, scope, maskirani token mono).

## Interactions & Behavior
- **Command palette (⌘K)**: overlay `rgb(10 13 18 / 0.45)` + blur 2px, panel 620px, max 60vh, pop-in animacija 0.16s. Input + grupe (Akcije / Navigacija / Siteovi / AI). Strelice ↑↓ za selekciju (akcent-soft bg + akcent ikonski kvadrat), Enter izvršava, Esc zatvara. Filtriranje po labelu i hintu.
- **Forge AI drawer (⌘J ili gumb)**: desni panel 380px, slide/fade-in 0.18s. Chat: korisnikova poruka tamni balon desno; AI odgovor = tekst + kartice nalaza (statusna točka + naslov + objašnjenje) + CTA gumbi ("Primijeni sva 3 fixa", "Pokaži naredbe"). Dolje prijedlozi-pilule + input.
- **Keyboard navigacija**: `G` pa `D/S/F/M/P` → skok na ekran (G ostaje "aktivan" ~900ms). Kratice ne rade dok je fokus u inputu.
- **Hover stanja**: redci tablica → `--surface-2` bg; akcijske ikone u redcima vidljive samo na hover (opacity 0→1, 0.12s); gumbi potamne (primary → `--accent-deep`).
- **Animacije**: ekrani ulaze s fade-up 0.25s; statusne točke pulsiraju 2.4s; topologija dash-flow 1.1s; sparklineovi se osvježavaju svakih 1.2–1.6s; kursor u editoru/terminalu trepće 1.1s step-start. Postavka za isključivanje animacija (atribut `data-anim="off"` → sve animacije 0s); poštovati `prefers-reduced-motion`.
- **Perzistencija**: aktivni ekran se pamti (u prototipu `localStorage`, ključ `fp-view`).

## State Management
- `view` — aktivni ekran (5 vrijednosti), perzistiran
- `paletteOpen`, `aiOpen` — booleani za overlay/drawer
- Korisničke postavke: `accent` (4 opcije), `theme` (light/dark), `density` (regular/compact), `anim` (bool) — apliciraju se kao atributi/CSS varijable na `<html>`
- Per-ekran: selekcija site-a, filteri, aktivna datoteka/tabovi, vremenski raspon, firewall toggleovi
- Live podaci: u prototipu simulirani hookom `useLive` (random walk s privlačenjem prema baseline-u); u produkciji WebSocket/SSE stream metrika (2s rezolucija) + REST za tablice

## Design Tokens — sažetak za implementaciju
Sve je u `design/forgepanel/tokens.css` — preporuka: prenijeti 1:1 kao CSS custom properties (ili Tailwind theme). Teme i gustoća rade preko atributa na `<html>`: `data-theme`, `data-density`, `data-anim`.

## Assets
- Nema slika ni eksternih asseta. Ikone su jednostavne geometrijske stroke SVG ikone 24×24 (stroke-width 1.6, round caps) definirane u `forgepanel/ui.jsx` (objekt `FP_ICONS`, ~30 ikona) — u implementaciji ih se može zamijeniti konzistentnim setom (npr. Lucide) uz zadržavanje veličina (13–18px).
- Google Fonts: Instrument Sans, IBM Plex Mono.

## Files
| Datoteka | Sadržaj |
|---|---|
| `design/ForgePanel.html` | entry point — otvori u browseru za živi prototip |
| `design/forgepanel/tokens.css` | **svi design tokeni** (boje, fontovi, teme, gustoća, animacije) |
| `design/forgepanel/ui.jsx` | primitivi: ikone, Spark/BigChart grafovi, Badge, Dot, Card, Bar, Toggle, Btn, tablične ćelije |
| `design/forgepanel/chrome.jsx` | Rail, TopBar, CommandPalette, AIDrawer |
| `design/forgepanel/screen-dashboard.jsx` | Pregled + topologija |
| `design/forgepanel/screen-sites.jsx` | Siteovi + detaljni panel |
| `design/forgepanel/screen-files.jsx` | File tree + editor + terminal |
| `design/forgepanel/screen-monitoring.jsx` | Grafovi, procesi, alerti |
| `design/forgepanel/screen-settings.jsx` | SSL, DNS, firewall, pristup |
| `design/forgepanel/app.jsx` | shell, routing, kratice, postavke |
| `design/forgepanel/data.js` | mock podaci (oblik podataka = dobar nacrt za API response modele) |
| `design/forgepanel/tweaks-panel.jsx` | alat za prototipiranje — ignorirati u implementaciji |
