#!/bin/sh
set -eu

PLUGIN_DIR="$(pwd)/plugins/user"

if [ ! -d "$PLUGIN_DIR/SQL" ]; then
    exit 0
fi

php bin/updatedb.sh --dir="$PLUGIN_DIR/SQL" --package=user
