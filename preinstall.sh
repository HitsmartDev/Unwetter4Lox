#!/bin/bash
# Unwetter4Lox preinstall.sh – Systemvoraussetzungen prüfen
# Läuft VOR dem Entpacken der Plugin-Dateien

echo "<INFO> Unwetter4Lox preinstall – prüfe Voraussetzungen..."

# Python3 prüfen
if ! command -v python3 &>/dev/null; then
    echo "<ERR> Python3 ist nicht installiert!"
    echo "<ERR> Bitte Python3 installieren: sudo apt-get install python3"
    exit 2
fi

PYVER=$(python3 -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')
PYMAJ=$(python3 -c 'import sys; print(sys.version_info.major)')
PYMIN=$(python3 -c 'import sys; print(sys.version_info.minor)')

echo "<INFO> Python ${PYVER} gefunden"

if [ "$PYMAJ" -lt 3 ] || { [ "$PYMAJ" -eq 3 ] && [ "$PYMIN" -lt 8 ]; }; then
    echo "<ERR> Python 3.8 oder höher wird benötigt (gefunden: ${PYVER})"
    exit 2
fi

echo "<OK> Python ${PYVER} – OK"

# pip3 prüfen
if ! command -v pip3 &>/dev/null && ! python3 -m pip --version &>/dev/null 2>&1; then
    echo "<WARNING> pip3 nicht gefunden – wird versucht python3-pip zu installieren"
fi

echo "<OK> Alle Voraussetzungen erfüllt"
exit 0
