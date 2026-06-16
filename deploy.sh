#!/usr/bin/env bash
# ForgePanel deploy na live (elite).
#
# RASPORED NA SERVERU (bitno — ne otkrivati iznova svaki put):
#   git checkout (ovaj repo):   /opt/forgepanel-src
#   nginx docroot (servirano):  /opt/forgepanel/web/public
#       (vidi /etc/nginx/conf.d/forgepanel-panel.conf -> root /opt/forgepanel/web/public)
#   => /opt/forgepanel je ZASEBNA kopija, NIJE symlink na -src.
#      Zato git pull u -src sam po sebi NE mijenja ono što nginx servira;
#      treba sinkronizirati -src -> /opt/forgepanel.
#
# Upotreba (kao root na serveru):
#   cd /opt/forgepanel-src && ./deploy.sh
#
set -euo pipefail

SRC="${FP_SRC:-/opt/forgepanel-src}"
DEST="${FP_DEST:-/opt/forgepanel}"

# ── Self-update guard ──
# Povuci NAJPRIJE, pa re-exec SVJEŽU verziju ove skripte. Bez ovoga, kad se promijeni
# sam deploy.sh, stara verzija u memoriji nastavi izvršavati zastarjele korake (npr.
# ne restarta agenta/FPM) → "deployao sam ali stari kod i dalje radi".
if [ "${FP_DEPLOY_REEXEC:-0}" != "1" ]; then
    echo "→ pull origin/main u $SRC"
    git -C "$SRC" fetch origin main
    git -C "$SRC" checkout main 2>/dev/null || git -C "$SRC" checkout -B main origin/main
    git -C "$SRC" reset --hard origin/main
    exec env FP_DEPLOY_REEXEC=1 bash "$SRC/deploy.sh"
fi

echo "→ sync $SRC -> $DEST (bez --delete; ne dira runtime podatke)"
rsync -a --exclude='.git' --exclude='deploy.sh' "$SRC"/ "$DEST"/

# PHP-FPM: reload OBAVEZNO (inače opcache servira STARI web/src kod — čest uzrok
# "deployao sam ali se ništa nije promijenilo"). Reloadamo sve prisutne php*-fpm poolove.
echo "→ reload PHP-FPM (čisti opcache, učitava novi web/src)"
fpm_found=0
for svc in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
    systemctl reload "$svc" 2>/dev/null || systemctl restart "$svc" 2>/dev/null || true
    echo "   $svc"
    fpm_found=1
done
[ "$fpm_found" = 0 ] && echo "   (nijedan php*-fpm servis nije pronađen — provjeri ručno)"

# Agent (forge-agentd): restart da pokupi izmjene u agent/ (op klase, validatori…).
echo "→ restart forge-agentd (pokupi agent/ izmjene)"
systemctl restart forge-agentd 2>/dev/null || echo "   (forge-agentd nije pronađen)"

systemctl reload nginx 2>/dev/null || true

echo "✓ Deploy gotov: $(git -C "$SRC" rev-parse --short HEAD) -> $DEST"
echo "  U browseru: hard refresh (Ctrl+Shift+R)."
