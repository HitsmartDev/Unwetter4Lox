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

# Daemon nach Update/Neuinstallation automatisch starten wenn bereits konfiguriert (LAT/LON vorhanden)
DAEMON="${LBHOMEDIR}/system/daemons/plugins/${PLUGINDIR}"
LAT=$(grep "^LAT=" "${CFGFILE}" 2>/dev/null | cut -d= -f2 | tr -d ' \r')
LON=$(grep "^LON=" "${CFGFILE}" 2>/dev/null | cut -d= -f2 | tr -d ' \r')
if [ -n "$LAT" ] && [ -n "$LON" ] && [ -f "${DAEMON}" ]; then
    echo "<INFO> Standort konfiguriert (LAT=${LAT}) – starte Daemon nach Installation/Update..."
    sleep 1  # Kurz warten bis sudoers-Eintrag aus postroot.sh aktiv ist
    sudo "${DAEMON}" restart 2>/dev/null \
        && echo "<OK> Daemon erfolgreich gestartet" \
        || echo "<WARNING> Daemon-Start fehlgeschlagen – bitte in der Plugin-UI manuell starten"
else
    echo "<INFO> Kein Standort konfiguriert – Daemon wird nach der Konfiguration gestartet"
fi

# Autostart-Cronjob nach LoxBerry-Neustart registrieren
# Verhindert, dass der Daemon nach Reboot des LoxBerry-Hosts manuell gestartet werden muss.
# sleep 90: gibt MQTT-Broker und Netzwerk Zeit hochzufahren (bei langsamer Hardware ggf. erhöhen)
if [ -f "${DAEMON}" ]; then
    CRON_MARKER="# unwetter4lox-autostart"
    CRON_CMD="@reboot sleep 90 && sudo ${DAEMON} start >/dev/null 2>&1 ${CRON_MARKER}"
    # Alten Eintrag entfernen (idempotent bei Updates)
    (crontab -l 2>/dev/null | grep -v "${CRON_MARKER}") | crontab - 2>/dev/null || true
    # Neuen Eintrag hinzufügen
    (crontab -l 2>/dev/null; echo "${CRON_CMD}") | crontab - 2>/dev/null \
        && echo "<OK> Autostart-Cronjob registriert (startet 90s nach Systemstart)" \
        || echo "<WARNING> Autostart-Cronjob konnte nicht registriert werden"
fi

echo "<OK> Unwetter4Lox Installation abgeschlossen."
exit 0
