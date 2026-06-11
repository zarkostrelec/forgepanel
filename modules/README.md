# ForgePanel moduli — Plugin SDK

Svaki modul je direktorij u `modules/<ime>/` sa sljedećom strukturom:

```
modules/<ime>/
├── manifest.json        # obavezno — metapodaci modula
├── api/                 # REST kontroleri (ForgePanel\Web\Api\... klase)
├── ui/                  # JS/CSS isječci koji se učitavaju u SPA
└── operations/          # agent Operation klase (whitelistane)
```

## manifest.json

```json
{
    "name": "moj_modul",
    "version": "1.0.0",
    "title": "Moj modul",
    "description": "Što modul radi.",
    "author": "Ime Autora",
    "depends": ["websites"],
    "operations": ["moj_modul.nesto", "moj_modul.drugo"]
}
```

## Pravila (sigurnost po dizajnu)

- **`name`** mora odgovarati imenu direktorija (anti-spoofing) i biti `[a-z][a-z0-9_]{1,32}`.
- **`version`** semver `X.Y.Z`.
- **`operations`** smiju biti **isključivo u vlastitom namespaceu** (`<ime>.<op>`) —
  modul ne može registrirati ni preuzeti op-code jezgre ni drugog modula.
- Manifest koji ne prođe validaciju se **ne učitava** — jezgra ostaje netaknuta.
- Agent i dalje vrijedi whitelist: op-code mora biti u registru I u OperationRegistry
  jezgre ili u modulu koji ga je deklarirao. Nepoznat op-code = odbij + audit log.

## Jezgreni moduli

Jezgra ima ugrađene module (websites, dns, mail, databases, ssl, backup, …) koji
NE žive u `modules/` — oni su dio core koda. `modules/` je za **treće strane** i
opcionalne ekstenzije (Faza 5 plugin SDK).

## Učitavanje

`ModuleRegistry` skenira `modules/*/manifest.json`, validira i izlaže popis kroz
`GET /api/v1/modules` (admin). UI učitava `ui/` isječke modula koji su aktivni.
