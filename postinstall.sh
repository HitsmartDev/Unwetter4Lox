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

echo "<OK> Unwetter4Lox Installation abgeschlossen."
exit 0
