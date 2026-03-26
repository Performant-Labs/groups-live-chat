# Build Log: Drupal + Matrix Real-Time Chat

*Started: 2026-03-25*

---

## Phase 1 — Conduit Matrix Sidecar in DDEV

### What Was Done

1. **Installed `group` module** via `composer require drupal/group && drush en group`
2. **Created DDEV sidecar config** — three files:
   - `.ddev/docker-compose.conduit.yaml` — Conduit container (60MB distroless image, RocksDB embedded)
   - `.ddev/conduit/conduit.toml` — homeserver config (`chat.ddev.site`, no federation, no open registration)
   - `.ddev/conduit/appservice-drupal.yaml` — appservice registration with generated tokens, exclusive `@_drupal_*` namespace
3. **Registered the Drupal appservice** via Conduit's admin room (see lessons below)
4. **Verified all Phase 1 tests pass**:
   - Health check: `/_matrix/client/versions` → v1.1–v1.12
   - Registration blocked: `POST /register` → `M_FORBIDDEN`
   - Appservice user creation: `as_token` creates `@_drupal_test:chat.ddev.site`

### Credentials (Dev Only)

| Item | Value |
|---|---|
| Admin user | `@admin:chat.ddev.site` |
| Admin token | `vSNxXo0p1OoqJE0aAkY0CSxIGzAsIoJ5` |
| Appservice `as_token` | `a9da5800d0178beeb0837263a0b0c6ceddfb3fc424ed05a79c880c27fb4972e4` |
| Appservice `hs_token` | `833fd38bb4f7267978d0010f470754e2d87b385d37eec116366ccf4398e47253` |
| Admin room ID | `!9A-4kh1UNiRBJZ0srqpI5qr4G79_ygeYb9NMBs2yBFk` |

---

## Lessons Learned

### 1. Conduit Ignores `appservice_registration_files` in TOML

> [!CAUTION]
> Conduit (v0.10.12) does **not** read appservice registrations from config files. The `appservice_registration_files` TOML key is silently ignored — no error, no warning, no log line. This is different from Synapse, which does use config-file-based registration.

**What works:** Register appservices via the **admin room** by messaging the `@conduit:server_name` bot:

```
@conduit:chat.ddev.site: register-appservice
```yaml
id: drupal-chat
url: http://web:80/matrix/appservice
as_token: <token>
hs_token: <token>
sender_localpart: _drupal_bot
namespaces:
  users:
    - exclusive: true
      regex: "@_drupal_.*"
```‎
```

**Full workflow:**
1. Set `allow_registration = true` in `conduit.toml`
2. Restart Conduit container
3. Register first user (auto-becomes server admin)
4. Send `@conduit:chat.ddev.site: --help` to the admin room to see commands
5. Send `register-appservice` with YAML in a code block
6. Set `allow_registration = false`, restart Conduit

### 2. Conduit Admin Command Syntax

> [!IMPORTANT]
> Conduit admin commands use `@conduit:<server_name>: <command>`, NOT `!admin <command>`.

Available commands (v0.10.12):
- `register-appservice` / `unregister-appservice` / `list-appservices`
- `list-rooms` / `list-local-users`
- `deactivate-user` / `deactivate-all`
- `remove-alias`
- `query-media` / `show-media` / `list-media` / `purge-media`

Get help: `@conduit:chat.ddev.site: --help`
Get command help: `@conduit:chat.ddev.site: register-appservice --help`

### 3. `formatted_body` Required for Code Blocks

The `register-appservice` command expects the YAML in a **Matrix formatted message** — the `formatted_body` field with `<pre><code>` HTML wrapping. Plain-text markdown code fences in the `body` field alone produce: `Expected code block in command body.`

### 4. Shell Escaping Through `ddev exec` Mangles JSON/HTML

> [!CAUTION]
> `ddev exec curl -d '<json with HTML>'` fails because the shell layer between `ddev exec` and curl mangles quotes, angle brackets, and special characters.

**Workaround:** Write the JSON payload to a file, `docker cp` it into the container, and use `curl -d @/tmp/file.json`:

```bash
# Write payload on host
python3 -c "import json; ..."  # write to /tmp/conduit_msg.json

# Copy into container
docker cp /tmp/conduit_msg.json ddev-pl-d11-test-web:/tmp/conduit_msg.json

# Send from inside container
ddev exec bash -c "curl -s -X PUT -H '...' -d @/tmp/conduit_msg.json 'conduit:6167/...'"
```

### 5. Conduit is Distroless — No Shell Utilities

The `matrixconduit/matrix-conduit` Docker image is built on a distroless base. There is no `cat`, `ls`, `sh`, or `bash` inside the container. You cannot `docker exec` into it for debugging. All interaction must go through the Matrix API or Docker logs.

### 6. Sync API for Reading Admin Room Responses

Reading the admin room timeline via `/messages` endpoint is unreliable (can return empty `chunk`). Use `/sync` instead:

```
GET /_matrix/client/v3/sync?since=<batch_token>&timeout=3000
```

The `since` token ensures you only get new messages after your command was sent.

### 7. Hanging Process Prevention (from os_HANGING_PROCESSES.md)

These issues were encountered during this build:

| Issue | Cause | Fix |
|---|---|---|
| `ddev restart` hung 20+ min | Stuck waiting for container that couldn't pull image (no internet) | Kill process, use `ddev stop` + `ddev start` separately |
| Agent commands stuck | VS Code approval gate (#21 in HANGING doc) — invisible approval UI | Always use `SafeToAutoRun: true` for safe commands |
| Zombie terminals blocking new commands | Old stuck processes show as "running" in VS Code metadata | Close terminal tabs in VS Code, not just `kill` the PIDs |
| Python heredoc stuck | Shell quoting issues with inline Python | Write to `.py` file, run separately |

---

## Phase 1 File Inventory

```
.ddev/
├── docker-compose.conduit.yaml     # Conduit sidecar service
└── conduit/
    ├── conduit.toml                 # Homeserver config
    └── appservice-drupal.yaml       # Appservice registration YAML
```

---

## Next Steps — Phase 2

Create `web/modules/custom/matrix_bridge/` with:
- `MatrixClient` service — Guzzle wrapper for Matrix Client-Server API with `as_token` masquerading
- `ChatMessage` entity — lightweight content entity (`id, uuid, uid, group_id, matrix_event_id, body, created`)
- Module scaffolding (`info.yml`, `services.yml`, `config/install/`)

See [Implementation Plan](../doc/../../../.gemini/antigravity/brain/e0b92a72-1aeb-4c8a-83cc-4aeedaf73dd8/implementation_plan.md) for full details.
