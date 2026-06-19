#!/bin/bash
# Unwetter4Lox preupgrade.sh
# Wird von LoxBerry VOR dem Überschreiben der Dateien aufgerufen (Plugin-Update).
# WICHTIG: Backup muss nach /tmp/ gehen, weil LoxBerry den config/-Ordner beim Update
# komplett löscht und neu anlegt – ein Backup im config/-Ordner würde mitgelöscht!

PLUGIN="unwetter4lox"
LBHOMEDIR="${LBHOMEDIR:-/opt/loxberry}"
CFGFILE="${LBHOMEDIR}/config/plugins/${PLUGIN}/${PLUGIN}.cfg"
CFGBAK_TMP="/tmp/${PLUGIN}_cfg_upgrade.bak"
DAEMON="${LBHOMEDIR}/system/daemons/plugins/${PLUGIN}"
PIDFILE="${LBHOMEDIR}/log/plugins/${PLUGIN}/daemon.pid"

# Config sichern (vor Update, da LoxBerry config/-Ordner beim Update löscht)
if [ -f "${CFGFILE}" ]; then
    cp "${CFGFILE}" "${CFGBAK_TMP}"
    echo "<OK> Konfiguration nach /tmp gesichert: ${CFGBAK_TMP}"
else
    echo "<INFO> Keine bestehende Konfiguration vorhanden – kein Backup nötig"
fi

# Alten Daemon sauber stoppen BEVOR neue Dateien installiert werden.
# Verhindert RC=7-Loop durch zwei gleichzeitig laufende Instanzen nach dem Update.
echo "<INFO> Stoppe Daemon vor Plugin-Update..."
if [ -f "${DAEMON}" ]; then
    sudo "${DAEMON}" stop 2>/dev/null \
        && echo "<OK> Daemon via Daemon-Script gestoppt" \
        || echo "<INFO> Daemon-Script stop fehlgeschlagen (vermutlich nicht konfiguriert)"
fi
# Zusätzlich direkt killen – Fallback falls sudo noch nicht eingerichtet (Erstinstall)
if [ -f "${PIDFILE}" ]; then
    PID=$(cat "${PIDFILE}" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        kill "$PID" 2>/dev/null
        sleep 1
        kill -0 "$PID" 2>/dev/null && kill -9 "$PID" 2>/dev/null || true
    fi
    rm -f "${PIDFILE}"
fi
pkill -f "unwetter4lox_daemon.py" 2>/dev/null || true
sleep 1
echo "<OK> Daemon-Stop abgeschlossen"

exit 0
