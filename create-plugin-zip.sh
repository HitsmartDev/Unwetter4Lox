#!/bin/bash
# Erstellt eine installierbare .zip Datei für den LoxBerry Plugin-Installer
# Verwendung: ./create-plugin-zip.sh
# Ausgabe:    unwetter4lox-<version>-snapshot.zip

set -e

# Abhängigkeiten prüfen
for cmd in zip; do
    if ! command -v "$cmd" &>/dev/null; then
        echo "Fehler: '$cmd' ist nicht installiert."
        echo "Installation: sudo apt-get install zip"
        exit 1
    fi
done

# Version aus plugin.cfg lesen
PLUGIN=$(grep "^NAME=" plugin.cfg | head -1 | cut -d= -f2 | tr -d ' ')
VERSION=$(grep "^VERSION=" plugin.cfg | head -1 | cut -d= -f2 | tr -d ' ')
PLUGIN=${PLUGIN:-unwetter4lox}
VERSION=${VERSION:-dev}

ZIPNAME="${PLUGIN}-${VERSION}-snapshot.zip"
TMPDIR=$(mktemp -d)
STAGEDIR="${TMPDIR}/${PLUGIN}"

echo "Plugin:  ${PLUGIN}"
echo "Version: ${VERSION}"
echo "Output:  ${ZIPNAME}"

# Voraussetzungen prüfen
for f in plugin.cfg preinstall.sh postinstall.sh postroot.sh; do
    if [ ! -f "$f" ]; then
        echo "Fehler: Pflichtdatei fehlt: $f"
        rm -rf "$TMPDIR"
        exit 1
    fi
done

# Staging-Verzeichnis befüllen
mkdir -p "$STAGEDIR"

# Pflichtdateien
cp plugin.cfg          "$STAGEDIR/"
cp preinstall.sh       "$STAGEDIR/"
cp postinstall.sh      "$STAGEDIR/"
cp postroot.sh         "$STAGEDIR/"

# Optionale Lifecycle-Scripts
[ -f postupgrade.sh ]  && cp postupgrade.sh  "$STAGEDIR/"
[ -f preupgrade.sh ]   && cp preupgrade.sh   "$STAGEDIR/"

# Verzeichnisse (nur wenn vorhanden)
for dir in bin config daemon icons templates webfrontend apt uninstall; do
    if [ -d "$dir" ]; then
        cp -r "$dir" "$STAGEDIR/"
    fi
done

# Executable-Bits setzen
chmod +x "$STAGEDIR/preinstall.sh"
chmod +x "$STAGEDIR/postinstall.sh"
chmod +x "$STAGEDIR/postroot.sh"
[ -f "$STAGEDIR/postupgrade.sh" ] && chmod +x "$STAGEDIR/postupgrade.sh"
[ -f "$STAGEDIR/preupgrade.sh"  ] && chmod +x "$STAGEDIR/preupgrade.sh"
[ -f "$STAGEDIR/daemon/daemon"  ] && chmod +x "$STAGEDIR/daemon/daemon"
[ -f "$STAGEDIR/uninstall/uninstall" ] && chmod +x "$STAGEDIR/uninstall/uninstall"
[ -f "$STAGEDIR/bin/unwetter4lox_daemon.py" ] && chmod +x "$STAGEDIR/bin/unwetter4lox_daemon.py"

# ZIP erstellen
rm -f "$ZIPNAME"
cd "$TMPDIR"
zip -r "$OLDPWD/$ZIPNAME" "$PLUGIN/" -x "*.DS_Store" -x "*/__pycache__/*" -x "*.pyc"
cd "$OLDPWD"

# Aufräumen
rm -rf "$TMPDIR"

echo ""
echo "Fertig: ${ZIPNAME}"
echo "Größe: $(du -sh "$ZIPNAME" | cut -f1)"
echo ""
echo "Jetzt in LoxBerry installieren:"
echo "  Plugin Manager → ZIP-Datei hochladen → ${ZIPNAME}"
