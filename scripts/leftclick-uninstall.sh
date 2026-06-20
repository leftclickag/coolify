#!/bin/bash
## Leftclick Coolify Uninstaller
## Removes ALL Coolify data and containers. Irreversible.
##
## Usage:
##   bash /data/coolify/source/leftclick-uninstall.sh
##   # or
##   curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-uninstall.sh | bash

set -e

if [ "$EUID" -ne 0 ]; then
    echo "Run as root or with sudo."
    exit 1
fi

read -p "This will permanently delete ALL Coolify data (databases, services, configs, SSH keys, volumes). Type YES to continue: " CONFIRM
if [ "$CONFIRM" != "YES" ]; then
    echo "Aborted."
    exit 1
fi

echo ""
echo "Stopping and removing containers..."
for c in coolify coolify-db coolify-redis coolify-realtime coolify-sentinel; do
    docker rm -f "$c" 2>/dev/null || true
done

echo "Removing Docker volumes (all data lost)..."
for v in coolify-db coolify-redis coolify-logs; do
    docker volume rm "$v" 2>/dev/null || true
done

echo "Removing data directory..."
rm -rf /data/coolify

echo "Removing Docker network (if present)..."
docker network rm coolify 2>/dev/null || true

echo ""
echo "Coolify fully removed."
echo "To reinstall: curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-install.sh | bash"
