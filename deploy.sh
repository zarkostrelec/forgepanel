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

echo "→ pull origin/main u $SRC"
git -C "$SRC" fetch origin main
git -C "$SRC" checkout main
git -C "$SRC" reset --hard origin/main

echo "→ sync $SRC -> $DEST (bez --delete; ne dira runtime podatke)"
rsync -a --exclude='.git' --exclude='deploy.sh' "$SRC"/ "$DEST"/

# Statički asseti (app.css/app.js/app.html) ne trebaju restart — samo hard refresh.
# Reload weba je jeftin i siguran; agent/FPM dirati samo ako se mijenjao backend.
systemctl reload nginx 2>/dev/null || true

echo "✓ Deploy gotov: $(git -C "$SRC" rev-parse --short HEAD) -> $DEST"
echo "  U browseru: hard refresh (Ctrl+Shift+R)."
echo "  Ako se mijenjao agent/ ili web/src (PHP): systemctl restart forge-agentd php8.4-fpm"
