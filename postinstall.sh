#!/bin/bash
# Unwetter4Lox postinstall.sh – läuft als loxberry User nach der Installation
# REPLACELBHOMEDIR und REPLACELBPPLUGINDIR werden vom LoxBerry-Installer ersetzt

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

# Standard-Config anlegen wenn noch nicht vorhanden
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

# TAWES Stations-Cache bei jeder Installation/Update löschen
# Verhindert ID-Mismatch wenn GeoSphere API das ID-Format ändert.
# Der Daemon lädt den Cache automatisch beim ersten Lauf neu.
TAWES_CACHE="${LBHOMEDIR}/data/plugins/${PLUGINDIR}/tawes_stations.json"
if [ -f "$TAWES_CACHE" ]; then
    rm -f "$TAWES_CACHE"
    echo "<OK> TAWES Stations-Cache gelöscht – wird beim ersten Daemon-Start neu geladen"
else
    echo "<INFO> Kein TAWES Cache vorhanden (Erstinstallation)"
fi

# Daemon nach Update/Neuinstallation automatisch starten wenn bereits konfiguriert (LAT/LON vorhanden)
DAEMON="${LBHOMEDIR}/system/daemons/plugins/${PLUGINDIR}"
LAT=$(grep "^LAT=" "${CFGFILE}" 2>/dev/null | cut -d= -f2 | tr -d ' \r')
LON=$(grep "^LON=" "${CFGFILE}" 2>/dev/null | cut -d= -f2 | tr -d ' \r')
if [ -n "$LAT" ] && [ -n "$LON" ] && [ -f "${DAEMON}" ]; then
    echo "<INFO> Standort konfiguriert (LAT=${LAT}) – starte Daemon nach Installation/Update..."
    # Sicherheitshalber nochmals alle laufenden Instanzen killen (Fallback für Race Conditions).
    # Der Daemon wurde bereits in preupgrade.sh gestoppt; dies ist ein zusätzlicher Schutz.
    pkill -f "unwetter4lox_daemon.py" 2>/dev/null || true
    sleep 2
    sudo "${DAEMON}" start 2>/dev/null \
        && echo "<OK> Daemon erfolgreich gestartet" \
        || echo "<WARNING> Daemon-Start fehlgeschlagen – bitte in der Plugin-UI manuell starten"
else
    echo "<INFO> Kein Standort konfiguriert – Daemon wird nach der Konfiguration gestartet"
fi

# Autostart + täglicher Restart werden via /etc/cron.d/ in postroot.sh (root) angelegt.
# Zuverlässiger als user-crontab, da root-owned und Update-sicher.

echo "<OK> Unwetter4Lox Installation abgeschlossen."
exit 0
