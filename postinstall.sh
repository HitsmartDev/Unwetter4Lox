#!/bin/bash
# Unwetter4Lox postinstall.sh – läuft als loxberry User nach der Installation
# WICHTIG: postinstall.sh läuft VOR postroot.sh! Config-Restore und sudoers-Setup
# erfolgen erst in postroot.sh. Deshalb KEIN Daemon-Start hier – postroot.sh übernimmt das.

LBHOMEDIR="REPLACELBHOMEDIR"
PLUGINDIR="REPLACELBPPLUGINDIR"
CFGFILE="${LBHOMEDIR}/config/plugins/${PLUGINDIR}/unwetter4lox.cfg"
CFGDEF="${LBHOMEDIR}/config/plugins/${PLUGINDIR}/unwetter4lox.cfg.default"

echo "<INFO> Unwetter4Lox postinstall startet..."
echo "<INFO> LBHOMEDIR=${LBHOMEDIR}"
echo "<INFO> PLUGINDIR=${PLUGINDIR}"

# paho-mqtt installieren: apt-Paket bevorzugen, pip als Fallback
echo "<INFO> Installiere paho-mqtt..."
if apt-get install -y python3-paho-mqtt 2>/dev/null; then
    echo "<OK> paho-mqtt via apt installiert"
else
    echo "<INFO> apt fehlgeschlagen, versuche pip..."
    pip3 install paho-mqtt --break-system-packages 2>/dev/null || \
    pip3 install paho-mqtt 2>/dev/null || \
    python3 -m pip install paho-mqtt --break-system-packages 2>/dev/null || true
    if python3 -c "import paho.mqtt" 2>/dev/null; then
        echo "<OK> paho-mqtt via pip installiert"
    else
        echo "<WARNING> paho-mqtt Installation fehlgeschlagen – MQTT wird nicht funktionieren!"
    fi
fi

# Standard-Config anlegen wenn noch nicht vorhanden (nur bei Erstinstallation)
if [ ! -f "$CFGFILE" ]; then
    if [ -f "$CFGDEF" ]; then
        cp "$CFGDEF" "$CFGFILE"
        echo "<OK> Standard-Config angelegt: ${CFGFILE}"
    else
        echo "<WARNING> cfg.default nicht gefunden: ${CFGDEF}"
    fi
else
    echo "<INFO> Config bereits vorhanden: ${CFGFILE}"
fi

# Python-Daemon ausführbar machen
DAEMON_PY="${LBHOMEDIR}/bin/plugins/${PLUGINDIR}/unwetter4lox_daemon.py"
if [ -f "${DAEMON_PY}" ]; then
    chmod +x "${DAEMON_PY}"
    echo "<OK> Daemon ausführbar: ${DAEMON_PY}"
fi

# TAWES Stations-Cache löschen – wird beim nächsten Daemon-Start frisch geladen
TAWES_CACHE="${LBHOMEDIR}/data/plugins/${PLUGINDIR}/tawes_stations.json"
if [ -f "$TAWES_CACHE" ]; then
    rm -f "$TAWES_CACHE"
    echo "<OK> TAWES Stations-Cache gelöscht"
else
    echo "<INFO> Kein TAWES Cache vorhanden (Erstinstallation)"
fi

# Daemon-Start erfolgt in postroot.sh – dort ist Config bereits wiederhergestellt
# und sudoers ist eingerichtet. postinstall.sh läuft VOR postroot.sh, daher
# wäre sudo hier nicht verfügbar und LAT/LON wären in der default-Config nicht gesetzt.
echo "<INFO> Daemon-Start erfolgt in postroot.sh nach Config-Restore."

echo "<OK> Unwetter4Lox postinstall abgeschlossen."
exit 0
