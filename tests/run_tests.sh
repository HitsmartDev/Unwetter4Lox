#!/bin/bash
# Unit-Tests auf LoxBerry ausführen
# Aufruf: bash /opt/loxberry/config/plugins/unwetter4lox/tests/run_tests.sh

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TEST_DIR="$PLUGIN_DIR/tests"

echo "=== Unwetter4Lox Unit-Tests ==="
echo "Plugin: $PLUGIN_DIR"
echo ""

# pytest installieren falls nicht vorhanden
if ! python3 -m pytest --version >/dev/null 2>&1; then
    echo "pytest nicht gefunden – installiere..."
    pip3 install pytest --quiet
fi

# Tests ausführen
cd "$PLUGIN_DIR"
python3 -m pytest "$TEST_DIR/test_daemon.py" -v --tb=short "$@"
