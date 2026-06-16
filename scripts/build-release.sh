#!/usr/bin/env bash
# Zapakira ForgePanel release tarball + ispiše SHA-256 (za objavu na Distribuciji).
# Paket MORA sadržavati web/ i agent/ (provjerava panel.self_update prije primjene).
#
# Upotreba (iz korijena repoa):
#   ./scripts/build-release.sh 1.2.1
# Rezultat: dist/forgepanel-1.2.1.tar.gz  + ispisan SHA-256.
# Zatim: hostaj tarball preko HTTPS (npr. na elite-u u /opt/forgepanel/web/public/releases/)
# pa u panelu Server → Distribucija → Objavi release (version, package_url, sha256).
set -euo pipefail

VERSION="${1:?Upotreba: build-release.sh <verzija>  (npr. 1.2.1)}"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$SRC/dist"
TARBALL="$OUT/forgepanel-${VERSION}.tar.gz"

mkdir -p "$OUT"
# ukey dijelovi koje node primjenjuje; .git, dist i runtime se izostavljaju
tar -czf "$TARBALL" -C "$SRC" \
    --exclude='.git' --exclude='dist' --exclude='node_modules' --exclude='*.log' \
    web agent modules database installer docs 2>/dev/null || \
tar -czf "$TARBALL" -C "$SRC" --exclude='.git' --exclude='dist' web agent modules database installer

SHA="$(sha256sum "$TARBALL" | awk '{print $1}')"
echo "✓ Tarball : $TARBALL"
echo "  Verzija : $VERSION"
echo "  SHA-256 : $SHA"
echo
echo "Sljedeće:"
echo "  1) Hostaj tarball preko HTTPS (npr. cp '$TARBALL' /opt/forgepanel/web/public/releases/ na elite)."
echo "  2) Panel → Server → Distribucija → Objavi release:"
echo "       version=$VERSION  package_url=https://elite.hostforge.net/releases/forgepanel-${VERSION}.tar.gz  sha256=$SHA"
echo "  Nodovi (licencirani/trial) automatski povuku i primijene potpisani manifest."
