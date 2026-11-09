#!/bin/sh
set -eu

port="${PORT:-10000}"
if ! printf '%s' "$port" | grep -Eq '^[0-9]+$'; then
    echo "PORT must be a numeric TCP port." >&2
    exit 1
fi

sed -ri "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
