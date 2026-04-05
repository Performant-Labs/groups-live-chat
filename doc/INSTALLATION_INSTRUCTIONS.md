# Installation Instructions

> **Matrix Bridge for Drupal** — real-time group chat powered by the Matrix protocol and HTMX.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Local Development (DDEV)](#local-development-ddev)
3. [Production Deployment (Docker Compose)](#production-deployment-docker-compose)
4. [GitHub Container Registry (GHCR)](#github-container-registry-ghcr)
5. [Matrix Appservice Registration](#matrix-appservice-registration)
6. [First-Run Drupal Setup](#first-run-drupal-setup)
7. [Host Nginx Configuration](#host-nginx-configuration)
8. [Environment Variables Reference](#environment-variables-reference)
9. [Known Issues & Troubleshooting](#known-issues--troubleshooting)

---

## Prerequisites

| Tool | Minimum Version | Notes |
|---|---|---|
| Git | any | |
| Docker | 24+ | Docker Desktop, OrbStack, or Colima |
| DDEV | 1.23+ | Local dev only |
| Composer | 2.x | Available inside `ddev exec` |
| PHP | 8.3 | Required by Drupal 11.3 |
| MariaDB | 10.11 | Provided by DDEV or Docker Compose |
| nginx | any | Host-level reverse proxy for production |

---

## Local Development (DDEV)

### 1. Clone the repository

```bash
git clone https://github.com/Performant-Labs/groups-live-chat.git pl-groups-live-chat
cd pl-groups-live-chat
```

### 2. Start DDEV and install dependencies

```bash
ddev start
ddev composer install
```

### 3. Install Drupal

```bash
ddev drush site:install --account-name=admin --account-pass=admin -y
ddev drush en group matrix_bridge -y
ddev drush cr
```

### 4. Register the Matrix appservice

See [Matrix Appservice Registration](#matrix-appservice-registration) below.

### 5. Open the site

```bash
ddev drush uli
```

Navigate to `/group/1/chat` after creating a group (see [First-Run Drupal Setup](#first-run-drupal-setup)).

---

## Production Deployment (Docker Compose)

The production stack (Drupal + MariaDB + Conduit) is defined in `docker-compose.yml`.

### 1. Clone to the server

```bash
git clone https://github.com/Performant-Labs/groups-live-chat.git /srv/groups-live-chat
cd /srv/groups-live-chat
```

### 2. Create the environment file

```bash
cp .env.example .env
```

Edit `.env` and fill in real values:

```dotenv
MYSQL_ROOT_PASSWORD=<strong-root-password>
MYSQL_DATABASE=groups_chat
MYSQL_USER=drupal
MYSQLpal_PASSWORD=<strong-drupal-password>
DRUPAL_HASH_SALT=<random-64-character-string>
```

Generate a hash salt with:

```bash
openssl rand -base64 48
```

### 3. Copy production settings

The `deploy/settings.php` file contains production database and trusted-host configuration. It is injected by the container entrypoint automatically — you do **not** need to copy it manually.

If you need to customise `trusted_host_patterns`, edit `deploy/entrypoint.sh` before building.

### 4. Build (or pull) and start the stack

**Option A — build locally:**

```bash
docker compose build
docker compose up -d
```

**Option B — pull from GHCR (once the CI workflow is set up):**

```bash
docker compose pull
docker compose up -d
```

> ⚠️ See [GitHub Container Registry (GHCR)](#github-container-registry-ghcr) for the current status of the pre-built image.

### 5. Watch logs

```bash
docker compose logs -f web
```

### 6. Run Drupal install inside the container

After the `web` container is healthy, run the Drupal installer once:

```bash
docker compose exec web drush site:install \
  --account-name=admin \
  --account-pass="$(openssl rand -base64 16)" \
  -y

docker compose exec web drush en group matrix_bridge -y
docker compose exec web drush cr
```

> 💡 Save the admin password printed by the install command.

---

## GitHub Container Registry (GHCR)

### Current Status

> ⚠️ **No pre-built image exists yet.**
>
> The GHCR package (`ghcr.io/performant-labs/groups-live-chat`) has not been published because:
>
> 1. No `.github/workflows/` directory exists — the CI build pipeline has not been created yet.
> 2. For an **organisation** repo, GHCR packages default to **private**. A workflow must explicitly set the package visibility to `public`, or the server must authenticate with a token that has `read:packages` scope.

### What needs to be done

#### A. Create the GitHub Actions workflow

Create `.github/workflows/docker-publish.yml` in the repository:

```yaml
name: Build & Push Docker Image

on:
  push:
    branches: ["main"]
  workflow_dispatch:

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  build-and-push:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write   # <-- required to push to GHCR

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Log in to GHCR
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            type=sha,prefix=sha-
            type=raw,value=latest,enable=${{ github.ref == 'refs/heads/main' }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
```

#### B. Make the GHCR package public (recommended for open-source)

After the first successful workflow run:

1. Go to `https://github.com/orgs/Performant-Labs/packages`
2. Click the `groups-live-chat` package
3. Go to **Package settings** → **Danger Zone** → **Change visibility** → `Public`

Without this step, pulling the image on the server requires:
```bash
docker login ghcr.io -u <github-username> -p <PAT>
```

#### C. Update `docker-compose.yml` to pull from GHCR

Once the image is published, replace the local `build:` block in `docker-compose.yml`:

```yaml
# Replace this:
web:
  build:
    context: .
    dockerfile: Dockerfile

# With this:
web:
  image: ghcr.io/performant-labs/groups-live-chat:latest
```

---

## Matrix Appservice Registration

This is a **one-time** operation required after every fresh Drupal/Conduit installation.

### 1. Enable registration temporarily

**DDEV (local):** Edit `.ddev/conduit/conduit.toml`:
```toml
allow_registration = true
```
Then `ddev restart`.

**Production:** Edit `deploy/conduit.toml` and restart the conduit container:
```bash
docker compose restart conduit
```

### 2. Create the Matrix admin user

```bash
# DDEV
ddev exec curl -s -X POST \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme","auth":{"type":"m.login.dummy"}}' \
  'http://conduit:6167/_matrix/client/v3/register'

# Production
docker compose exec conduit curl -s -X POST \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme","auth":{"type":"m.login.dummy"}}' \
  'http://localhost:6167/_matrix/client/v3/register'
```

Save the `access_token` from the response.

### 3. Register the appservice

Use a Matrix client (e.g. Element) or the curl admin room procedure described in [`doc/BUILD_LOG_MATRIX_CHAT.md`](BUILD_LOG_MATRIX_CHAT.md) (Lesson 1).

The appservice YAML is at `deploy/appservice-drupal.yaml` (production) or `.ddev/conduit/appservice-drupal.yaml` (local).

### 4. Lock down registration

Set `allow_registration = false` and restart Conduit.

---

## First-Run Drupal Setup

### Create a group type

```bash
ddev drush php:eval '
$gts = \Drupal::entityTypeManager()->getStorage("group_type");
$type = $gts->create(["id" => "chat_group", "label" => "Chat Group"]);
$type->save();
$rts = \Drupal::entityTypeManager()->getStorage("group_relationship_type");
$rts->createFromPlugin($type, "group_membership")->save();
echo "Created group type chat_group\n";
'
```

### Create a group

```bash
ddev drush php:eval '
$admin = \Drupal\user\Entity\User::load(1);
$group = \Drupal\group\Entity\Group::create([
  "type" => "chat_group",
  "label" => "My First Chat Group",
  "uid" => 1,
  "status" => 1,
]);
$group->save();
$group->addMember($admin);
echo "Group " . $group->id() . " created with chat room\n";
'
```

Navigate to `/group/1/chat` to open the chat interface.

---

## Host Nginx Configuration

The container exposes port `8083` on `127.0.0.1`. The host nginx acts as a TLS-terminating reverse proxy.

Copy the site config:

```bash
sudo cp deploy/nginx-host-site.conf /etc/nginx/sites-available/groups-live-chat
sudo ln -s /etc/nginx/sites-available/groups-live-chat /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Then obtain a TLS certificate (Let's Encrypt / Certbot):

```bash
sudo certbot --nginx -d chat.performantlabs.com
```

---

## Environment Variables Reference

| Variable | Default | Required | Description |
|---|---|---|---|
| `MYSQL_ROOT_PASSWORD` | — | ✅ | MariaDB root password |
| `MYSQL_DATABASE` | `groups_chat` | — | Database name |
| `MYSQL_USER` | `drupal` | — | Database user |
| `MYSQL_PASSWORD` | — | ✅ | Database user password |
| `DRUPAL_HASH_SALT` | — | ✅ | 64+ char random string for Drupal security |
| `MYSQL_HOST` | `db` | — | Database hostname (Docker service name) |

---

## Known Issues & Troubleshooting

### GHCR image pull fails with 403

**Symptoms:** `docker compose pull` exits with `unauthorized` or `denied`.

**Cause:** The GHCR package is private (default for org repos) and no auth token is configured.

**Fix options:**
- Make the package public (see [GHCR section](#b-make-the-ghcr-package-public-recommended-for-open-source))
- Or log in on the server: `docker login ghcr.io -u <github-user> -p <PAT with read:packages>`

### No `.github/workflows/` directory — CI never ran

**Cause:** The GitHub Actions workflow file has not yet been created and committed.

**Fix:** Create `.github/workflows/docker-publish.yml` with the content shown in the [GHCR section](#a-create-the-github-actions-workflow) and push to `main`.

### Entrypoint settings.php not being applied

**Symptoms:** Drupal shows database connection errors or untrusted host errors.

**Cause:** The `fi` block in `deploy/entrypoint.sh` is never closed — the script has a syntax bug where `php-fpm` and `nginx` start commands are **inside** the `if [ -f "$SETTINGS" ]` block.

**Fix:** See the `entrypoint.sh` bug note below and ensure the shell `fi` terminator is present before the `php-fpm` start command.

> ⚠️ **Bug in `deploy/entrypoint.sh`:** The current file (as of the last commit) is missing the closing `fi` for the outer `if [ -f "$SETTINGS" ]` block. This means `php-fpm` and `nginx` only start if `settings.php` exists. If the file is absent on first boot, the container will exit silently. **Fix:** add `fi` on a new line after the heredoc block.

### Conduit /sync returns 500

Known upstream bug in Conduit v0.10.12 when using appservice masquerading for `/sync`. The long-poll sync in Drupal works around this by querying the `chat_message` database table directly rather than calling Matrix `/sync`.

### Container exits immediately on first boot

**Cause:** Usually the database is not yet ready. The `depends_on: condition: service_healthy` setting in `docker-compose.yml` should handle this, but if MariaDB is slow to initialize its data directory, the first boot can fail.

**Fix:** `docker compose restart web` after `docker compose logs db` shows `ready for connections`.
