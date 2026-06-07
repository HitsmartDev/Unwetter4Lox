#!/bin/bash
# Unwetter4Lox preupgrade.sh
# Wird von LoxBerry VOR dem Überschreiben der Dateien aufgerufen (Plugin-Update).
# WICHTIG: Backup muss nach /tmp/ gehen, weil LoxBerry den config/-Ordner beim Update
# komplett löscht und neu anlegt – ein Backup im config/-Ordner würde mitgelöscht!

PLUGIN="unwetter4lox"
LBHOMEDIR="${LBHOMEDIR:-/opt/loxberry}"
CFGFILE="${LBHOMEDIR}/config/plugins/${PLUGIN}/${PLUGIN}.cfg"
CFGBAK_TMP="/tmp/${PLUGIN}_cfg_upgrade.bak"

if [ -f "${CFGFILE}" ]; then
    cp "${CFGFILE}" "${CFGBAK_TMP}"
    echo "<OK> Konfiguration nach /tmp gesichert (LoxBerry löscht config-Ordner beim Update): ${CFGBAK_TMP}"
else
    echo "<INFO> Keine bestehende Konfiguration vorhanden – kein Backup nötig"
fi

exit 0
