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

## Phase 2 — MatrixClient + ChatMessage Entity

### What Was Done

1. **Created `matrix_bridge` module** — `web/modules/custom/matrix_bridge/`
2. **MatrixClient service** (`src/MatrixClient.php`) — Guzzle wrapper with 7 methods:
   - `ensureUserExists(uid)` — registers `@_drupal_{uid}:server` via appservice
   - `createRoom(name, alias)` — creates room masquerading as bot
   - `inviteUser(roomId, userId)` — invite + auto-join (appservice users are Drupal-controlled)
   - `joinRoom(roomId, userId)` — explicit join (used internally by inviteUser)
   - `kickUser(roomId, userId)` / `banUser(roomId, userId)` — room management
   - `sendMessage(roomId, userId, body)` — sends m.room.message masquerading as user
3. **ChatMessage entity** (`src/Entity/ChatMessage.php`) — fields: uid, group_id, matrix_event_id, matrix_room_id, body, created
4. **Config** — settings.yml with homeserver URL, tokens; config schema for validation

### Phase 2 Tests

| Test | Result |
|---|---|
| T1 `ensureUserExists` | ✅ `@_drupal_2:chat.ddev.site` |
| T2 `createRoom` | ✅ Room ID returned |
| T3 `invite+join` | ✅ OK |
| T4 `sendMessage` | ✅ Event ID returned |
| T5 `ChatMessage save` | ✅ id=1 |
| T6 `ChatMessage load` | ✅ body + event_id match |

### Lesson 8: Conduit Requires `?user_id=` Masquerade for All Non-Register Calls

> [!IMPORTANT]
> Conduit rejects API calls that use only the `as_token` without a `?user_id=` query param (returns `M_FORBIDDEN: User does not exist`). Every room management call must masquerade as a real registered user — typically the bot user `@_drupal_bot:server`.

The bot user is auto-registered on first room operation via `ensureBotExists()` (cached per request).

### Lesson 9: Matrix Requires Explicit Join After Invite

Inviting a user to a room is not enough — they must also **join** (`POST /rooms/{id}/join`). Since all `@_drupal_*` users are appservice-controlled, `inviteUser()` auto-joins on their behalf.

### Phase 2 File Inventory

```
web/modules/custom/matrix_bridge/
├── matrix_bridge.info.yml
├── matrix_bridge.services.yml
├── config/
│   ├── install/matrix_bridge.settings.yml
│   └── schema/matrix_bridge.schema.yml
└── src/
    ├── MatrixClient.php
    └── Entity/ChatMessage.php
```

## Phase 3 — Group Lifecycle Hooks

### What Was Done

1. **MatrixBridgeHooks** (`src/Hook/MatrixBridgeHooks.php`) — D11 `#[Hook]` attribute hooks:
   - `entity_insert(Group)` → `createRoom()` + store room_id in `key_value`
   - `entity_insert(GroupRelationship)` → `ensureUserExists()` + `inviteUser()` (auto-join)
   - `entity_delete(GroupRelationship)` → `kickUser()`
   - `entity_delete(Group)` → tombstone message to Matrix room
2. **AppserviceController** (`src/Controller/AppserviceController.php`) — webhook endpoint:
   - Route: `PUT /matrix/appservice/transactions/{txnId}`
   - `hs_token` validated via timing-safe `hash_equals()`
   - Returns `{}` per Matrix appservice spec
3. **MatrixClient additions** — `getRoomId()`/`setRoomId()` using Drupal `key_value` store

### Phase 3 Tests

| Test | Result |
|---|---|
| T1 Group created → room auto-created | ✅ Room ID stored |
| T2 Member added → invite + join | ✅ |
| T3 Member can send to room | ✅ Event ID returned |
| T4 Member removed → kick | ✅ |
| T5 Kicked user blocked | ✅ `M_FORBIDDEN` |
| Webhook valid `hs_token` | ✅ 200 |
| Webhook invalid token | ✅ 403 |

### Lesson 10: Group Is a Content Entity — No `getThirdPartySetting()`

> [!CAUTION]
> `getThirdPartySetting()` only exists on **config entities** (implementing `ThirdPartySettingsInterface`). The Group module's `Group` entity is a **content entity**. Attempting to call it throws a fatal error.

**Solution:** Use Drupal's `key_value` store (`\Drupal::keyValue('matrix_bridge.rooms')`) for the group→room mapping.

### Lesson 11: D11 `#[Hook]` Auto-Discovery Needs Autowire Aliases

> [!IMPORTANT]
> Drupal 11's `#[Hook]` attribute scanner auto-discovers hook classes and attempts autowiring independently of `services.yml`. If a hook class type-hints a custom service class (like `MatrixClient`), the DI container errors out unless you provide an autowire alias:

```yaml
Drupal\matrix_bridge\MatrixClient:
  alias: matrix_bridge.client
```

### Lesson 12: `ControllerBase::$configFactory` Property Conflict

`ControllerBase` declares `$configFactory` without a type in PHP. If your subclass uses constructor promotion with a typed `$configFactory`, PHP throws a fatal error. Use `$this->config()` from `ControllerBase` instead.

### Phase 3 File Inventory (additions)

```
web/modules/custom/matrix_bridge/
├── matrix_bridge.routing.yml              [NEW]
├── src/
│   ├── Controller/AppserviceController.php [NEW]
│   └── Hook/MatrixBridgeHooks.php         [NEW]
└── tests/phase3_test.php                  [NEW]
```

## Phase 4 — HTMX Chat UI

### What Was Done

1. **ChatController** (`src/Controller/ChatController.php`) — 3 endpoints:
   - `panel()` — Render array with full chat UI (attaches HTMX + custom CSS/JS)
   - `messages()` — HTMX fragment returning message list (cached with max-age=0)
   - `send()` — POST handler: saves ChatMessage + sends to Matrix, returns updated fragment
2. **Routes** — 3 new routes added to `matrix_bridge.routing.yml`:
   - `GET /group/{id}/chat` — full panel
   - `GET /group/{id}/chat/messages` — message fragment (hx-get target)
   - `POST /group/{id}/chat/send` — message submission (hx-post target)
3. **Templates**:
   - `matrix-chat-panel.html.twig` — HTMX attributes: `hx-get` polling (5s), `hx-post` form, auto-reset
   - `matrix-chat-messages.html.twig` — message bubbles with own/other styling
4. **CSS** (`css/chat.css`) — dark-mode, gradient bubbles, smooth animations
5. **JS** (`js/chat.js`) — auto-scroll via Drupal behaviors
6. **Library** (`matrix_bridge.libraries.yml`) — depends on `core/drupal.htmx`

### Phase 4 Tests

| Test | Result |
|---|---|
| Chat panel renders (200) | ✅ |
| Messages fragment renders (200) | ✅ |
| Message input + Send button visible | ✅ Browser screenshot |
| HTMX form submission | ✅ "Hello from browser!" saved (id=3) |
| Message sent to Matrix | ✅ |
| Dark-mode UI styling | ✅ |

### Lesson 13: Missing `<?php` Tag in `.module` File

> [!CAUTION]
> When creating a `.module` file, the `<?php` opening tag MUST be present. Without it, the entire file contents are output as plain text on every Drupal page load, breaking all theme hooks.

### Phase 4 File Inventory (additions)

```
web/modules/custom/matrix_bridge/
├── matrix_bridge.module             [NEW]
├── matrix_bridge.libraries.yml      [NEW]
├── css/chat.css                     [NEW]
├── js/chat.js                       [NEW]
├── templates/
│   ├── matrix-chat-panel.html.twig  [NEW]
│   └── matrix-chat-messages.html.twig [NEW]
└── src/Controller/ChatController.php [NEW]
```

---
## Phase 5 — Browser Real-Time Integration

### What Was Done

1. **SyncController** (`src/Controller/SyncController.php`) — Drupal DB-based sync endpoint:
   - `GET /group/{id}/chat/sync?since={last_id}` — returns new ChatMessage entities as JSON
   - Incremental: only returns messages with `id > since`
   - ACL-safe: uses `_permission: 'access content'`
2. **Upgraded chat.js** — intelligent long-poll loop:
   - Initial sync fetches all messages (since=0)
   - Subsequent polls use `last_id` for incremental sync
   - 500ms re-poll on new messages, 2s on empty, exponential backoff on error (up to 30s)
   - DOM manipulation appends new messages (no full-page refresh)
3. **ChatController update** — `panel()` now ensures current user is registered + invited + joined to the Matrix room on page load

### Design Decision: Drupal DB vs Matrix /sync

> [!IMPORTANT]
> Conduit v0.10.12 has a bug: `/sync` with appservice masquerading (`?user_id=`) returns `500 Internal Server Error` with an empty body. This appears to be a known limitation — the sync endpoint doesn't work properly for appservice-controlled users.

**Solution:** Poll Drupal's own `chat_message` table instead of Matrix `/sync`. This is:
- More reliable (uses standard Drupal entity queries)
- Simpler (no Matrix token management or filter parsing)
- Consistent with the architecture principle that Drupal is the source of truth

Matrix remains the transport layer — messages are sent to Matrix rooms in real-time, and the appservice webhook (`AppserviceController`) can ingest Matrix-side events in the future.

### Phase 5 Tests

| Test | Result |
|---|---|
| Sync returns all messages (since=0) | ✅ 2 messages |
| Incremental sync (since=1) | ✅ 2 newer messages |
| Empty sync (since=999) | ✅ 0 messages |
| Cache rebuild clean | ✅ |

### Lesson 14: Conduit /sync Bug with Appservice Masquerading

> [!CAUTION]
> Conduit v0.10.12's `/sync` endpoint returns `500 Internal Server Error` with empty body when called with `?user_id=` masquerading for appservice-controlled users. This is unexpected — the same `?user_id=` parameter works correctly for all other endpoints (room creation, invites, messages, etc.).

### Phase 5 File Inventory (additions/changes)

```
web/modules/custom/matrix_bridge/
├── src/Controller/SyncController.php  [NEW]
├── src/Controller/ChatController.php  [MODIFIED — auto-join on panel load]
├── js/chat.js                         [MODIFIED — long-poll sync]
└── templates/matrix-chat-panel.html.twig [MODIFIED — removed 5s polling]
```

---

## Phase 6 — Group Roles & Permissions

### What Was Done

1. **Auto-role creation** — `ensureGroupRoles()` in `MatrixBridgeHooks.php` creates three group roles (Member, Admin, Outsider) on first group creation per type
2. **Auto-role assignment** — `onMembershipGranted()` automatically assigns `member` role to all new members and `admin` role to the group owner
3. **Group visibility** — Groups are now visible in `/admin/group` and accessible by members

### Phase 6 Tests

| Test | Result |
|---|---|
| New group type → 0 roles before, 3 after | ✅ |
| Member role assigned on addMember() | ✅ |
| Admin role assigned to group owner | ✅ |
| Admin can view group after role assignment | ✅ |

### Lesson 15: Group Module Requires Explicit Role-Based Permissions

> [!CAUTION]
> The Drupal Group module's entity access handler **denies all access by default**. Even uid=1 (superadmin) cannot view or access a group unless:
> 1. Group roles exist for the group type (Member, Admin, Outsider)
> 2. Those roles grant `view group` permission
> 3. The user is a group member with one of those roles assigned
>
> Setting `uid` and `status` fields via SQL is **not sufficient** — the Group module uses its own access handler that checks group membership + role permissions, not Drupal's standard entity access.

### Lesson 16: `setSyncing(TRUE)` Prevents Hook Re-Entry

> [!TIP]
> When modifying a `GroupRelationship` entity inside an `entity_insert` hook (e.g., to assign group roles during membership creation), call `$relationship->setSyncing(TRUE)` before `save()`. This prevents the save from re-triggering the same `entity_insert` hook, avoiding an infinite loop.

### Phase 6 File Inventory (changes)

```
web/modules/custom/matrix_bridge/
├── src/Hook/MatrixBridgeHooks.php     [MODIFIED — ensureGroupRoles() + auto-assign]
└── tests/test_auto_role.php           [NEW]
```

---

## Summary

All 6 phases complete. The full architecture:

```
Browser ←→ Drupal (HTMX + long-poll sync) ←→ Conduit Matrix (real-time transport)
```

### Total File Count

| Phase | Files | Lines |
|---|---|---|
| Phase 1 — Conduit Sidecar | 3 | ~60 |
| Phase 2 — MatrixClient + Entity | 6 | ~550 |
| Phase 3 — Group Lifecycle Hooks | 4 | ~410 |
| Phase 4 — HTMX Chat UI | 7 | ~560 |
| Phase 5 — Real-Time Integration | 1 new + 3 modified | ~200 |
| Phase 6 — Group Roles & Permissions | 1 modified + 1 new | ~120 |
| **Total** | **~22 files** | **~1,900 lines** |
