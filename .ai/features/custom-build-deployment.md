# Custom Build & Deployment (Leftclick Fork)

This fork is installed and updated by **building the Docker image locally from source**,
rather than pulling a pre-built image from a Docker registry. No registry push is required.

---

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-install.sh | bash
```

`scripts/leftclick-install.sh` performs:

1. Installs prerequisites (git, docker, openssl, …) for Debian/Ubuntu/Arch/Fedora/Alpine
2. Clones the repo to `/data/coolify/build`
3. Builds the image locally: `docker build -f docker/production/Dockerfile` → `leftclick/coolify:local`
4. Downloads the **upstream** `docker-compose.yml` + `docker-compose.prod.yml` + `.env.production`
   from Coolify's CDN (keeps postgres/redis/soketi in sync with upstream)
5. Writes `/data/coolify/source/docker-compose.custom.yml` overriding only the `coolify`
   service image to the locally-built one
6. Writes `/data/coolify/source/.leftclick-build` metadata and copies `leftclick-update.sh`
   to `/data/coolify/source/`
7. Generates secrets, sets up the localhost SSH key, runs `docker compose up`, then
   `php artisan migrate --force`

### Environment variables

| Var | Default | Purpose |
|---|---|---|
| `REPO_URL` | `https://github.com/leftclickag/coolify` | Git repo to clone |
| `REPO_BRANCH` | `v4.x` | Branch to build |
| `GITHUB_TOKEN` | — | For private repos |
| `ROOT_USERNAME` / `ROOT_USER_EMAIL` / `ROOT_USER_PASSWORD` | — | Pre-seed root user |
| `AUTOUPDATE` | — | Set `false` to disable auto-updates |

---

## Update

```bash
curl -fsSL https://raw.githubusercontent.com/leftclickag/coolify/v4.x/scripts/leftclick-update.sh | bash
# or, after first install:
bash /data/coolify/source/leftclick-update.sh
```

`scripts/leftclick-update.sh`:

1. `git pull` the build dir
2. Rebuilds `leftclick/coolify:local`
3. Hot-swaps **only** the `coolify` container (postgres/redis/soketi keep running)
4. Runs `php artisan migrate --force`
5. Prunes dangling image layers

---

## Auto-update interception

`app/Actions/Server/UpdateCoolify.php` is patched so the built-in "Update" button and the
scheduled auto-update job use the local rebuild path **for custom builds**:

- `isLeftclickBuild()` checks for `/data/coolify/source/.leftclick-build` over SSH
- When present, upstream CDN version comparison is skipped (version numbers are meaningless
  for a fork) and `updateLeftclick()` runs `bash /data/coolify/source/leftclick-update.sh`
- The `AUTOUPDATE` / `is_auto_update_enabled` setting is still respected
- Non-fork installs are unchanged (still use upstream `upgrade.sh`)

---

## On-disk layout

```
/data/coolify/
  build/                              # git clone (source of truth for the image)
  source/
    .env                             # secrets — BACK THIS UP
    .leftclick-build                 # metadata used by the updater + UpdateCoolify.php
    leftclick-update.sh              # local copy called by UpdateCoolify.php
    docker-compose.yml               # upstream (postgres, redis, soketi)
    docker-compose.prod.yml          # upstream
    docker-compose.custom.yml        # image override (auto-generated)
```

---

## Migration idempotency

Installing a custom build on top of a database previously populated by upstream Coolify can
hit `SQLSTATE[42701]: Duplicate column`. Several `2026_*` migrations were made idempotent
(guarded with `Schema::hasColumn()` / `Schema::hasIndex()` / `CREATE INDEX IF NOT EXISTS`) so
`php artisan migrate --force` is safe to re-run. The install/update scripts also tolerate
duplicate-column errors and print guidance instead of aborting.

---

## Key Files

| File | Purpose |
|---|---|
| `scripts/leftclick-install.sh` | One-shot installer (clone → build → run → migrate) |
| `scripts/leftclick-update.sh` | Pull → rebuild → hot-swap → migrate |
| `app/Actions/Server/UpdateCoolify.php` | Routes fork updates to the local rebuild path |
