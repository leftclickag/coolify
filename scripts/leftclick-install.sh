#!/bin/bash
## Leftclick Custom Coolify Installer
## Clones the repo, builds the image locally, and runs the full stack.
## No Docker registry required.
##
## Usage:
##   curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-install.sh | bash
##
## Environment variables:
##   REPO_URL             - Git repo to clone (default: https://github.com/leftclickag/coolify)
##   REPO_BRANCH          - Branch to build from (default: v4.x)
##   GITHUB_TOKEN         - Personal access token for private repos (optional)
##   ROOT_USERNAME        - Predefined root username
##   ROOT_USER_EMAIL      - Predefined root user email
##   ROOT_USER_PASSWORD   - Predefined root user password
##   AUTOUPDATE           - Set to "false" to disable auto-updates

set -e
set -o pipefail

## ─── Configuration ────────────────────────────────────────────────────────────
REPO_URL="${REPO_URL:-https://github.com/leftclickag/coolify}"
REPO_BRANCH="${REPO_BRANCH:-v4.x}"
IMAGE_NAME="leftclick/coolify"
IMAGE_TAG="local"
BUILD_DIR="/data/coolify/build"
SOURCE_DIR="/data/coolify/source"
ENV_FILE="${SOURCE_DIR}/.env"
OFFICIAL_CDN="https://cdn.coollabs.io/coolify"
DATE=$(date +"%Y%m%d-%H%M%S")
## ──────────────────────────────────────────────────────────────────────────────

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo."
    exit 1
fi

OS_TYPE=$(grep -w "ID" /etc/os-release | cut -d "=" -f 2 | tr -d '"')
CURRENT_USER="${USER:-root}"

echo ""
echo "=========================================="
echo "   Leftclick Coolify Installer - ${DATE}"
echo "=========================================="
echo ""
echo "  Repo   : ${REPO_URL}@${REPO_BRANCH}"
echo "  Image  : ${IMAGE_NAME}:${IMAGE_TAG} (built locally)"
echo "  OS     : ${OS_TYPE}"
echo ""

# ── Helpers ──────────────────────────────────────────────────────────────────
log() { echo "[$(date '+%H:%M:%S')] $1"; }
section() {
    echo ""
    echo "──────────────────────────────────────────"
    echo "  $1"
    echo "──────────────────────────────────────────"
}
update_env_var() {
    local key="$1" value="$2"
    if grep -q "^${key}=$" "${ENV_FILE}"; then
        sed -i "s|^${key}=$|${key}=${value}|" "${ENV_FILE}"
    elif ! grep -q "^${key}=" "${ENV_FILE}"; then
        printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
    fi
}

# ── 1. System prerequisites ───────────────────────────────────────────────────
section "1/8 Installing required packages"
case "$OS_TYPE" in
    ubuntu|debian|raspbian)
        apt-get update -y -qq
        apt-get install -y -qq curl wget git jq openssl openssh-server ca-certificates
        ;;
    arch|manjaro)
        pacman -Sy --noconfirm --needed curl wget git jq openssl openssh
        ;;
    fedora|centos|rhel|rocky|almalinux)
        dnf install -y curl wget git jq openssl openssh-server
        ;;
    alpine)
        apk add --no-cache curl wget git jq openssl openssh
        ;;
    *)
        echo "Unsupported OS: ${OS_TYPE}. Supported: debian, ubuntu, arch, fedora, alpine."
        exit 1
        ;;
esac
log "Done."

# ── 2. Docker ─────────────────────────────────────────────────────────────────
section "2/8 Checking Docker"
if ! command -v docker >/dev/null 2>&1; then
    log "Docker not found – installing via get.docker.com..."
    curl -fsSL https://get.docker.com | sh
else
    log "Docker already installed ($(docker --version))."
fi

DOCKER_MAJOR=$(docker version --format '{{.Server.Version}}' 2>/dev/null | cut -d. -f1)
if [ -z "$DOCKER_MAJOR" ] || [ "$DOCKER_MAJOR" -lt 24 ]; then
    echo "ERROR: Docker 24+ is required. Found: ${DOCKER_MAJOR}."
    exit 1
fi

# Configure Docker log rotation
mkdir -p /etc/docker
if [ ! -f /etc/docker/daemon.json ]; then
    cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "default-address-pools": [{"base":"10.0.0.0/8","size":24}]
}
EOF
    systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null || true
fi
log "Done."

# ── 3. Directory layout ───────────────────────────────────────────────────────
section "3/8 Creating directory layout"
mkdir -p /data/coolify/{source,ssh,applications,databases,backups,services,proxy,sentinel}
mkdir -p /data/coolify/ssh/{keys,mux}
mkdir -p /data/coolify/proxy/dynamic
mkdir -p "${BUILD_DIR}"
chown -R 9999:root /data/coolify
chmod -R 700 /data/coolify
log "Done."

# ── 4. Clone / update the repo ────────────────────────────────────────────────
section "4/8 Fetching source code"

# Build authenticated URL if a token was provided
if [ -n "${GITHUB_TOKEN:-}" ]; then
    CLONE_URL="${REPO_URL/https:\/\//https://${GITHUB_TOKEN}@}"
else
    CLONE_URL="${REPO_URL}"
fi

if [ -d "${BUILD_DIR}/.git" ]; then
    log "Repo already cloned – pulling latest ${REPO_BRANCH}..."
    git -C "${BUILD_DIR}" fetch origin
    git -C "${BUILD_DIR}" checkout "${REPO_BRANCH}"
    git -C "${BUILD_DIR}" reset --hard "origin/${REPO_BRANCH}"
else
    log "Cloning ${REPO_URL} (branch: ${REPO_BRANCH})..."
    git clone --branch "${REPO_BRANCH}" --depth 1 "${CLONE_URL}" "${BUILD_DIR}"
fi
log "Done. Commit: $(git -C "${BUILD_DIR}" rev-parse --short HEAD)"

# ── 5. Build Docker image locally ────────────────────────────────────────────
section "5/8 Building Docker image (this takes a few minutes)"
log "Building ${IMAGE_NAME}:${IMAGE_TAG} from ${BUILD_DIR}..."
docker build \
    --file "${BUILD_DIR}/docker/production/Dockerfile" \
    --tag "${IMAGE_NAME}:${IMAGE_TAG}" \
    "${BUILD_DIR}"
log "Build complete."

# ── 6. Fetch official compose + env skeleton from Coolify CDN ─────────────────
section "6/8 Fetching compose skeleton and env template"
# We reuse the upstream docker-compose files so postgres/redis/soketi are
# kept in sync with upstream. Only the app image is overridden.
curl -fsSL "${OFFICIAL_CDN}/docker-compose.yml"      -o "${SOURCE_DIR}/docker-compose.yml"
curl -fsSL "${OFFICIAL_CDN}/docker-compose.prod.yml" -o "${SOURCE_DIR}/docker-compose.prod.yml"
curl -fsSL "${OFFICIAL_CDN}/.env.production"         -o "${SOURCE_DIR}/.env.production"
curl -fsSL "${OFFICIAL_CDN}/upgrade.sh"              -o "${SOURCE_DIR}/upgrade.sh"
curl -fsSL "${OFFICIAL_CDN}/upgrade-postgres.sh"     -o "${SOURCE_DIR}/upgrade-postgres.sh"
chmod +x "${SOURCE_DIR}/upgrade.sh" "${SOURCE_DIR}/upgrade-postgres.sh"

# Write the image override so our locally-built image is used
cat > "${SOURCE_DIR}/docker-compose.custom.yml" <<EOF
# Generated by leftclick-install.sh – do not delete.
# Overrides the upstream coolify service image with the locally built one.
services:
  coolify:
    image: "${IMAGE_NAME}:${IMAGE_TAG}"
    build:
      context: "${BUILD_DIR}"
      dockerfile: docker/production/Dockerfile
EOF

# Store build metadata for the updater
cat > "${SOURCE_DIR}/.leftclick-build" <<EOF
REPO_URL="${REPO_URL}"
REPO_BRANCH="${REPO_BRANCH}"
IMAGE_NAME="${IMAGE_NAME}"
IMAGE_TAG="${IMAGE_TAG}"
BUILD_DIR="${BUILD_DIR}"
LAST_INSTALLED="${DATE}"
EOF

log "Done."

# ── 7. Environment file ───────────────────────────────────────────────────────
section "7/8 Setting up environment"
if [ -f "${ENV_FILE}" ]; then
    log "Existing .env found – merging with .env.production..."
    cp "${ENV_FILE}" "${ENV_FILE}.backup-${DATE}"
    awk -F '=' '!seen[$1]++' "${ENV_FILE}" "${SOURCE_DIR}/.env.production" \
        > "${ENV_FILE}.tmp" && mv "${ENV_FILE}.tmp" "${ENV_FILE}"
else
    cp "${SOURCE_DIR}/.env.production" "${ENV_FILE}"
fi

update_env_var "APP_ID"           "$(openssl rand -hex 16)"
update_env_var "APP_KEY"          "base64:$(openssl rand -base64 32)"
update_env_var "DB_PASSWORD"      "$(openssl rand -base64 32)"
update_env_var "REDIS_PASSWORD"   "$(openssl rand -base64 32)"
update_env_var "PUSHER_APP_ID"    "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY"   "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"

[ "${AUTOUPDATE:-}" = "false" ] && update_env_var "AUTOUPDATE" "false"

if [ -n "${ROOT_USERNAME:-}" ] && [ -n "${ROOT_USER_EMAIL:-}" ] && [ -n "${ROOT_USER_PASSWORD:-}" ]; then
    update_env_var "ROOT_USERNAME"     "${ROOT_USERNAME}"
    update_env_var "ROOT_USER_EMAIL"   "${ROOT_USER_EMAIL}"
    update_env_var "ROOT_USER_PASSWORD" "${ROOT_USER_PASSWORD}"
fi
log "Done."

# ── SSH key for localhost access ───────────────────────────────────────────────
if [ ! -f ~/.ssh/authorized_keys ]; then
    mkdir -p ~/.ssh && chmod 700 ~/.ssh
    touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
fi
if ! docker volume ls | grep -q coolify-db; then
    KEY_FILE="/data/coolify/ssh/keys/id.${CURRENT_USER}@host.docker.internal"
    rm -f "${KEY_FILE}" "${KEY_FILE}.pub"
    ssh-keygen -t ed25519 -a 100 -f "${KEY_FILE}" -q -N "" -C coolify
    chown 9999 "${KEY_FILE}"
    sed -i "/coolify/d" ~/.ssh/authorized_keys
    cat "${KEY_FILE}.pub" >> ~/.ssh/authorized_keys
    rm -f "${KEY_FILE}.pub"
fi
chown -R 9999:root /data/coolify && chmod -R 700 /data/coolify

# ── 8. Start the stack ────────────────────────────────────────────────────────
section "8/8 Starting Coolify"

# Ensure the coolify Docker network exists
docker network inspect coolify >/dev/null 2>&1 \
    || docker network create --attachable coolify 2>/dev/null \
    || true

COMPOSE_CMD="docker compose \
    --env-file ${ENV_FILE} \
    -f ${SOURCE_DIR}/docker-compose.yml \
    -f ${SOURCE_DIR}/docker-compose.prod.yml \
    -f ${SOURCE_DIR}/docker-compose.custom.yml"

log "Pulling supporting images (postgres, redis, soketi)..."
$COMPOSE_CMD pull --ignore-buildable

log "Starting all containers..."
$COMPOSE_CMD up -d --remove-orphans --wait --wait-timeout 120

log "Running database migrations..."
docker exec coolify php artisan migrate --force

log "Done."

# ── Final output ──────────────────────────────────────────────────────────────
echo ""
echo -e "\033[0;35m"
echo "   ____            _ _  __                         ___          _     _ _"
echo "  / ___|___   ___ | (_)/ _|_   _                  / _ \__   ___| |   | | |"
echo " | |   / _ \ / _ \| | | |_| | | |  _____  _____  | (_) \ \ / / | |   | | |"
echo " | |__| (_) | (_) | | |  _| |_| | |_____||_____| |  _ < \ V /| |___| |_|_|"
echo "  \____\___/ \___/|_|_|_|  \__, |                |_| \_\ \_/ |_____|_(_(_)"
echo "                           |___/"
echo -e "\033[0m"

IPV4=$(curl -4s --max-time 5 https://ifconfig.io 2>/dev/null || true)
IPV6=$(curl -6s --max-time 5 https://ifconfig.io 2>/dev/null || true)

echo "Your custom Coolify instance is ready!"
echo ""
[ -n "$IPV4" ] && echo "  http://${IPV4}:8000"
[ -n "$IPV6" ] && echo "  http://[${IPV6}]:8000"
echo ""
echo "Locally built image : ${IMAGE_NAME}:${IMAGE_TAG}"
echo "Source code         : ${BUILD_DIR}"
echo ""
REPO_RAW_BASE="${REPO_URL/github.com/raw.githubusercontent.com}"
echo "To update later, run:"
echo "  curl -fsSL ${REPO_RAW_BASE}/${REPO_BRANCH}/scripts/leftclick-update.sh | bash"
echo ""
echo "IMPORTANT: Back up ${ENV_FILE} to a safe location!"
