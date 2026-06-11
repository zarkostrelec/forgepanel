# ForgePanel backup format (v1)

Transparentnost je dio filozofije: backup mora biti restorabilan i **ručno,
bez panela**. Format je običan tar + gzip + JSON manifest.

## Struktura

```
/var/backups/forgepanel/<domena>/<YYYYMMDD-HHMMSS>/
├── files.tar.gz          # cijeli vhost direktorij (tar -C /var/www/vhosts/<domena> .)
├── db_<baza>.sql.gz      # po jedan dump po bazi (mariadb-dump --single-transaction --routines --triggers)
└── manifest.json
```

## manifest.json

```json
{
    "format_version": 1,
    "domain": "example.com",
    "created_at": "2026-06-11T18:15:38+00:00",
    "databases": ["moja_baza"],
    "files": [
        {"name": "files.tar.gz", "sha256": "…", "size_bytes": 1301},
        {"name": "db_moja_baza.sql.gz", "sha256": "…", "size_bytes": 844}
    ]
}
```

## Ručni restore (bez panela)

```bash
# 1. provjeri integritet
cd /var/backups/forgepanel/example.com/20260611-181538
sha256sum files.tar.gz db_moja_baza.sql.gz   # usporedi s manifest.json

# 2. fileovi
tar -xzf files.tar.gz -C /var/www/vhosts/example.com
chown -R vh_<id>:vh_<id> /var/www/vhosts/example.com

# 3. baza
gunzip -c db_moja_baza.sql.gz | mariadb moja_baza
```

## Garancije

- Panel **verificira SHA-256 checksumove prije svakog restorea** — oštećeni
  backup se odbija umjesto da se vrati polovično.
- Backup se izvršava s `nice -n 19 ionice -c 3` da ne ugrozi produkciju.
- Retencija: čuva se zadnjih N (default 7) backupa po domeni; stariji se
  automatski brišu nakon uspješnog novog backupa.
- `--single-transaction` daje konzistentan dump InnoDB baza bez lockanja.

## Planirano (kasnije faze)

- inkrementalni backupi (rsync hardlink strategija)
- destinacije FTP/SFTP/S3 (tablica `backup_destinations` već postoji)
- AES-256 enkripcija s ključem kod korisnika
- tjedna/mjesečna retencija ({"daily": 7, "weekly": 4, "monthly": 3})
