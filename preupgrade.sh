#!/bin/bash
# Unwetter4Lox preupgrade.sh
# Wird von LoxBerry VOR dem Überschreiben der Dateien aufgerufen (Plugin-Update).
# Sichert die bestehende Konfiguration, damit postroot.sh sie wiederherstellen kann.

PLUGIN="unwetter4lox"
LBHOMEDIR="${LBHOMEDIR:-/opt/loxberry}"
CFGFILE="${LBHOMEDIR}/config/plugins/${PLUGIN}/${PLUGIN}.cfg"
CFGBAK="${LBHOMEDIR}/config/plugins/${PLUGIN}/${PLUGIN}.cfg.upgrade_bak"

if [ -f "${CFGFILE}" ]; then
    cp "${CFGFILE}" "${CFGBAK}"
    echo "<OK> Konfiguration vor Update gesichert: ${CFGBAK}"
else
    echo "<INFO> Keine bestehende Konfiguration – kein Backup nötig"
fi

exit 0
