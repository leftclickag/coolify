#!/bin/bash
## Leftclick Custom Coolify Updater
## Pulls latest code, rebuilds the image, and hot-swaps the running container.
##
## Usage:
##   curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-update.sh | bash
##   # or after first install:
##   bash /data/coolify/source/leftclick-update.sh

set -e
set -o pipefail

if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo."
    exit 1
fi

BUILD_META="/data/coolify/source/.leftclick-build"

if [ ! -f "${BUILD_META}" ]; then
    echo "ERROR: No leftclick build metadata found at ${BUILD_META}."
    echo "Please run the installer first:"
    echo "  curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-install.sh | bash"
    exit 1
fi

# shellcheck source=/dev/null
source "${BUILD_META}"

SOURCE_DIR="/data/coolify/source"
ENV_FILE="${SOURCE_DIR}/.env"
DATE=$(date +"%Y%m%d-%H%M%S")
LOG_FILE="${SOURCE_DIR}/leftclick-update-${DATE}.log"

exec > >(tee -a "${LOG_FILE}") 2>&1

echo ""
echo "=========================================="
echo "   Leftclick Coolify Updater - ${DATE}"
echo "=========================================="
echo ""
echo "  Repo   : ${REPO_URL}@${REPO_BRANCH}"
echo "  Image  : ${IMAGE_NAME}:${IMAGE_TAG}"
echo "  Source : ${BUILD_DIR}"
echo ""

log() { echo "[$(date '+%H:%M:%S')] $1"; }
section() {
    echo ""
    echo "──────────────────────────────────────────"
    echo "  $1"
    echo "──────────────────────────────────────────"
}

# ── 1. Pull latest code ───────────────────────────────────────────────────────
section "1/4 Pulling latest code"
if [ -d "${BUILD_DIR}/.git" ]; then
    OLD_COMMIT=$(git -C "${BUILD_DIR}" rev-parse --short HEAD)
    git -C "${BUILD_DIR}" fetch origin
    git -C "${BUILD_DIR}" checkout "${REPO_BRANCH}"
    git -C "${BUILD_DIR}" reset --hard "origin/${REPO_BRANCH}"
    NEW_COMMIT=$(git -C "${BUILD_DIR}" rev-parse --short HEAD)
    log "Updated: ${OLD_COMMIT} → ${NEW_COMMIT}"
else
    echo "ERROR: Build directory ${BUILD_DIR} is not a git repo."
    echo "Please re-run the installer."
    exit 1
fi

# ── 2. Rebuild the image ──────────────────────────────────────────────────────
section "2/4 Rebuilding Docker image"
PREVIOUS_ID=$(docker images -q "${IMAGE_NAME}:${IMAGE_TAG}" 2>/dev/null || true)

docker build \
    --file "${BUILD_DIR}/docker/production/Dockerfile" \
    --tag "${IMAGE_NAME}:${IMAGE_TAG}" \
    "${BUILD_DIR}"

NEW_ID=$(docker images -q "${IMAGE_NAME}:${IMAGE_TAG}")
log "Image rebuilt: ${PREVIOUS_ID:-<none>} → ${NEW_ID}"

# ── 3. Hot-swap the coolify container ─────────────────────────────────────────
section "3/4 Restarting coolify container"
COMPOSE_CMD="docker compose \
    --env-file ${ENV_FILE} \
    -f ${SOURCE_DIR}/docker-compose.yml \
    -f ${SOURCE_DIR}/docker-compose.prod.yml \
    -f ${SOURCE_DIR}/docker-compose.custom.yml"

log "Stopping existing coolify container..."
$COMPOSE_CMD stop coolify 2>/dev/null || true
$COMPOSE_CMD rm -f coolify 2>/dev/null || true

log "Starting updated coolify container..."
$COMPOSE_CMD up -d coolify --no-deps --wait --wait-timeout 120
log "Container started."

# ── 4. Database migrations ────────────────────────────────────────────────────
section "4/4 Running database migrations"
docker exec coolify php artisan migrate --force
log "Migrations complete."

# Update build metadata timestamp
sed -i "s/LAST_INSTALLED=.*/LAST_INSTALLED=\"${DATE}\"/" "${BUILD_META}"

echo ""
echo "Update complete!"
echo "Commit  : ${NEW_COMMIT}"
echo "Image   : ${IMAGE_NAME}:${IMAGE_TAG}"
echo "Log     : ${LOG_FILE}"

# Remove dangling image layers left by the previous build
docker image prune -f --filter "dangling=true" >/dev/null 2>&1 || true
