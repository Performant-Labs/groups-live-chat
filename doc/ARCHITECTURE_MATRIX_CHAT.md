# Matrix Chat Architecture — Drupal 11.3

## Overview

A real-time group chat system built into Drupal 11.3, using the Matrix protocol (Conduit homeserver) as a transport layer. Drupal owns all identity, ACLs, and persistence. The browser never talks to Matrix directly.

```
┌─────────────────────────────────────────────────┐
│                    Browser                       │
│  ┌────────────┐  ┌──────────────────────────┐   │
│  │ HTMX Form  │  │ JS Long-Poll (fetch)     │   │
│  │ POST /send │  │ GET /sync?since=last_id   │   │
│  └─────┬──────┘  └───────────┬──────────────┘   │
└────────┼─────────────────────┼───────────────────┘
         │                     │
         ▼                     ▼
┌─────────────────────────────────────────────────┐
│              Drupal (nginx-fpm)                  │
│  ┌──────────────────────────────────────────┐   │
│  │           ChatController                  │   │
│  │  panel()  → Render HTMX chat UI          │   │
│  │  send()   → Save entity + relay to Matrix │   │
│  │  messages()→ Load entities → HTML Response│   │
│  │  edit()   → Validate owner + update body  │   │
│  │  delete() → Validate owner + soft-delete  │   │
│  ├──────────────────────────────────────────┤   │
│  │           SyncController                  │   │
│  │  sync()   → Query new + mutated messages  │   │
│  │             → JSON with type per message  │   │
│  ├──────────────────────────────────────────┤   │
│  │           MatrixBridgeHooks               │   │
│  │  Group created  → ensureGroupRoles()      │   │
│  │                   + createRoom()           │   │
│  │  Member added   → auto-assign role        │   │
│  │                   + inviteUser() + join    │   │
│  │  Member removed → kickUser()              │   │
│  │  Group deleted  → tombstone message       │   │
│  ├──────────────────────────────────────────┤   │
│  │           MatrixClient (Guzzle)           │   │
│  │  All calls masquerade via ?user_id=       │   │
│  │  Bot user owns all rooms                  │   │
│  └───────────────────┬──────────────────────┘   │
│             chat_message table (source of truth) │
└──────────────────────┼──────────────────────────┘
                       │ HTTP (internal Docker network)
                       ▼
          ┌────────────────────────┐
          │   Conduit (sidecar)    │
          │   conduit:6167         │
          │   Matrix homeserver    │
          │   - Rooms              │
          │   - Events             │
          │   - Appservice bridge  │
          └────────────────────────┘
```

---

## Design Principles

### 1. Drupal Is the Source of Truth

Every message exists in Drupal's `chat_message` entity table. Matrix is a relay — if Conduit goes down, all messages are still in Drupal. The browser never reads from Matrix; it reads from Drupal via the sync endpoint.

### 2. Drupal Controls All Identity

Users are Drupal users. Matrix user IDs (`@_drupal_42:chat.ddev.site`) are synthetic — created and managed by the appservice bridge. The browser never sees a Matrix ID, token, or any Matrix protocol detail.

### 3. Drupal Enforces All ACLs

Route-level: `_permission: 'access content'` on all chat endpoints. Group membership is managed through Drupal's Group module. Matrix room membership mirrors Drupal group membership via hooks — it doesn't define it.

### 5. Group Roles Are Auto-Managed

The Group module requires explicit role-based permissions for entity access. Without group roles, even admin users cannot see or access groups. The module auto-creates three roles per group type:

| Role | Scope | Permissions |
|---|---|---|
| `{type}-member` | Individual (members only) | `view group`, `view group_membership content` |
| `{type}-admin` | Individual (members only) | Full admin (all permissions) |
| `{type}-outsider` | Outsider (authenticated non-members) | `view group` |

When a user is added as a group member, they're automatically assigned the `member` role. If they're the group owner, they also get the `admin` role.

### 4. Matrix Is an Internal Transport

Conduit is only accessible within the Docker network (`conduit:6167`). No port is exposed to the host. The browser cannot reach Conduit. All Matrix operations go through `MatrixClient` inside Drupal.

---

## Current Capabilities

| Feature | Status | How |
|---|---|---|
| Group → auto-creates chat room | ✅ | `entity_insert` hook → `createRoom()` |
| Group → auto-creates group roles | ✅ | `ensureGroupRoles()` → Member, Admin, Outsider |
| Member added → auto-assigned role | ✅ | `onMembershipGranted()` → member role (+ admin for owner) |
| Member added → joins room | ✅ | `entity_insert` hook → `inviteUser()` |
| Member removed → kicked from room | ✅ | `entity_delete` hook → `kickUser()` |
| Send message (browser) | ✅ | HTMX POST → `ChatController::send()` |
| Receive messages (browser) | ✅ | JS short-poll → `SyncController::sync()` |
| Edit own messages | ✅ | Hover menu → inline edit → PATCH → Matrix `m.replace` |
| Delete own messages | ✅ | Hover menu → confirm → DELETE → soft-delete + Matrix redaction |
| Mutation-aware sync | ✅ | Sync detects new, edited, and deleted messages via `changed` timestamp |
| Message persistence | ✅ | `chat_message` entity in Drupal DB |
| Author attribution | ✅ | Drupal session `$currentUser->id()` |
| Ownership validation | ✅ | Only message author can edit/delete (403 for others) |
| Dark-mode chat UI | ✅ | CSS with gradient bubbles, animations |
| Auto-scroll on new messages | ✅ | Drupal behaviors JS |
| Production deployment | ✅ | Docker Compose on Spiderman, SSL via host nginx |

---

## Current Limitations

| Feature | Status | Why |
|---|---|---|
| Typing indicators | ❌ | Requires sub-second updates; 2s polling is too slow |
| Read receipts | ❌ | Same — needs persistent connection |
| Presence (online/offline) | ❌ | Same |
| Bidirectional Matrix sync | ❌ | Webhook receives events but doesn't ingest them |
| File/image attachments | ❌ | Not implemented |
| End-to-end encryption | ❌ | Conduit supports it, but unnecessary for internal transport |

---

## Why the Limitations Exist

### The PHP Request-Response Problem

Drupal runs on PHP behind nginx-fpm. Each request ties up a PHP worker for its duration, then releases it. This model works well for:

- ✅ **Sending messages** — POST, save, respond, done
- ✅ **Polling for messages** — GET, query, respond, done (even every 2s)

It does **not** work well for:

- ❌ **Holding long-lived connections** — SSE or WebSocket from PHP would tie up a worker per connected user indefinitely
- ❌ **Sub-second push** — there's no way to push data to the browser without the browser asking first

### The Conduit /sync Bug

Conduit v0.10.12's `/sync` endpoint returns `500 Internal Server Error` with an empty body when called with appservice masquerading (`?user_id=`). This parameter works correctly for every other endpoint (room creation, invites, message sending, kicks). This is likely a Conduit bug, not a spec issue — Synapse handles this correctly.

This means we can't use Matrix's native sync mechanism to relay real-time events to the browser through Drupal, even if we solved the PHP problem.

### 2-Second Polling Is Fine for Messages

For actual message delivery, a 2-second latency is acceptable. Slack's web client historically used similar-interval polling before switching to WebSockets. Users typing a response need at least a few seconds anyway.

The issue is specifically with **ephemeral state** (typing indicators, presence) where latency > 500ms breaks the illusion of real-time awareness.

---

## What Would Be Needed for Extra Features

### Typing Indicators + Presence + Read Receipts

These all require **sub-second push** from server to browser. Three viable options:

#### Option A: Mercure Hub (Recommended)

[Mercure](https://mercure.rocks/) is a protocol for real-time push built for PHP apps. It's a standalone Go binary that acts as an SSE hub.

```
Browser ←──SSE──→ Mercure Hub ←──POST──→ Drupal
```

- Add as a DDEV sidecar (~10MB binary)
- Drupal publishes events via HTTP POST to Mercure
- Browser subscribes via native `EventSource` API
- Has a Drupal contrib module (`mercure`)
- **Pros**: Purpose-built for PHP, lightweight, standards-based
- **Cons**: Another container, Drupal contrib module maturity

#### Option B: Node.js WebSocket Sidecar

A small Node.js or Bun service that maintains WebSocket connections and receives events from Drupal via internal HTTP.

```
Browser ←──WS──→ Node sidecar ←──HTTP──→ Drupal
```

- Add as a DDEV sidecar
- Drupal POSTs typing/presence events to the sidecar
- Sidecar broadcasts to connected browsers
- **Pros**: Full control, WebSocket support, can also proxy Matrix /sync
- **Cons**: Another language/runtime, more code to maintain

#### Option C: Fix Conduit (or Switch to Synapse)

If Conduit's `/sync` bug is fixed (or if we switch to Synapse), we could proxy Matrix EDUs (Ephemeral Data Units) through Drupal:

```
Browser ←──poll──→ Drupal ←──/sync──→ Conduit
```

- Matrix natively supports `m.typing`, `m.receipt`, `m.presence`
- Drupal would proxy these from `/sync` to the browser
- Still limited to polling speed unless combined with Option A or B
- **Pros**: No new services, uses existing Matrix protocol
- **Cons**: Still bounded by polling latency; Synapse is heavier than Conduit (~500MB vs ~60MB)

### Bidirectional Matrix Sync

To ingest messages sent from external Matrix clients (e.g., Element):

1. Expand `AppserviceController::handleTransaction()` to parse `m.room.message` events
2. Map Matrix sender (`@_drupal_N:server`) back to Drupal uid
3. Create `ChatMessage` entities for each incoming event
4. Deduplicate via `matrix_event_id` field (already stored)

This is straightforward (~50 lines of code) but only useful if external Matrix clients are in scope.

### File/Image Attachments

1. Add a `matrix_content_uri` field to `ChatMessage`
2. Upload via Drupal's file system (or Matrix's `/_matrix/media/v3/upload`)
3. Render as `<img>` or download link in the message bubble
4. Would need HTMX multipart form support or a separate upload endpoint

### Message Editing/Deletion ✅ (Implemented in Phase 7)

See current implementation in `ChatController::edit()` and `ChatController::delete()`.

---

## File Inventory

```
web/modules/custom/matrix_bridge/
├── matrix_bridge.info.yml              # Module definition
├── matrix_bridge.services.yml          # MatrixClient service + autowire alias
├── matrix_bridge.routing.yml           # Routes: webhook, panel, messages, send, sync, edit, delete
├── matrix_bridge.libraries.yml         # CSS + JS library definition
├── matrix_bridge.module                # hook_theme() for templates
├── matrix_bridge.install               # Update hooks (10001: add changed + deleted fields)
├── config/install/
│   └── matrix_bridge.settings.yml      # as_token, hs_token, homeserver_url
├── src/
│   ├── MatrixClient.php                # Guzzle wrapper: send, edit (m.replace), redact, masquerade
│   ├── Entity/ChatMessage.php          # Content entity (uid, group_id, body, changed, deleted)
│   ├── Controller/
│   │   ├── ChatController.php          # panel(), messages(), send(), edit(), delete()
│   │   ├── SyncController.php          # sync() — mutation-aware DB polling
│   │   └── AppserviceController.php    # Webhook (hs_token auth, no-op body)
│   └── Hook/
│       └── MatrixBridgeHooks.php       # Group lifecycle → Matrix room + role operations
├── css/chat.css                        # Dark-mode chat UI + edit/delete UI
├── js/chat.js                          # Short-poll sync + hover menu + inline edit
├── templates/
│   ├── matrix-chat-panel.html.twig     # Full chat panel with HTMX attributes
│   └── matrix-chat-messages.html.twig  # Message bubbles (with data-message-id, deleted/edited)
└── tests/
    ├── full_e2e_test.php               # 19-test comprehensive suite
    ├── multi_user_test.php             # Multi-user conversation proof
    ├── test_auto_role.php              # Auto role creation + assignment test
    └── phase3_test.php                 # Group lifecycle hook tests

.ddev/                                  # Local development
├── docker-compose.conduit.yaml         # Conduit sidecar definition
└── conduit/
    ├── conduit.toml                    # Homeserver config
    └── appservice-drupal.yaml          # Appservice registration

deploy/                                 # Production (Spiderman)
├── entrypoint.sh                       # Generates settings.php from env vars, starts FPM + nginx
├── nginx-drupal.conf                   # Container-internal nginx config
└── settings.php                        # Template (used by docker cp as fallback)

Dockerfile                              # drupal:11-php8.3-fpm-alpine + nginx
docker-compose.yml                      # web, db (MariaDB), conduit services
.dockerignore
```

---

## API Contract

### Endpoints

| Method | Path | Returns | Purpose |
|---|---|---|---|
| `GET` | `/group/{id}/chat` | HTML page | Full chat panel (render array) |
| `GET` | `/group/{id}/chat/messages` | HTML Response | Message list fragment (bare HTML, not wrapped in page layout) |
| `POST` | `/group/{id}/chat/send` | HTML Response | Send + return updated message list |
| `GET` | `/group/{id}/chat/sync?since={id}&since_ts={ts}` | JSON | New + mutated messages |
| `PATCH` | `/group/{id}/chat/message/{msg_id}/edit` | JSON | Edit message body (owner only) |
| `DELETE` | `/group/{id}/chat/message/{msg_id}/delete` | JSON | Soft-delete message (owner only) |
| `PUT` | `/matrix/appservice/transactions/{txnId}` | JSON `{}` | Matrix webhook (no-op) |

> [!IMPORTANT]
> The `messages` and `send` endpoints return **bare `Response` objects**, not Drupal render arrays. This prevents Drupal from wrapping the fragment in the full page layout (html, head, toolbar, etc.) which would break HTMX's `innerHTML` swap.

### Sync JSON Schema

```json
{
  "messages": [
    {
      "id": 15,
      "type": "new|edited|deleted",
      "author": "string",
      "body": "string",
      "time": "HH:MM",
      "is_own": true,
      "edited": false
    }
  ],
  "last_id": 42,
  "sync_ts": 1711468800,
  "has_new": true
}
```

The `since_ts` parameter enables mutation detection: the sync query finds messages where `changed > since_ts AND id <= since_id`, catching edits and deletes to previously-seen messages.

---

## Production Deployment (Spiderman)

```
┌──────────────────────────────────────────────────────┐
│  Spiderman (172.232.174.154)                         │
│                                                      │
│  ┌──────────────────────────────┐                    │
│  │  Host nginx (port 443)      │                    │
│  │  SSL via Certbot            │                    │
│  │  chat.performantlabs.com    │                    │
│  └──────────┬───────────────────┘                    │
│             │ proxy_pass :8083                       │
│  ┌──────────▼───────────────────────────────────┐   │
│  │  Docker Compose stack                         │   │
│  │  ┌─────────────────┐  ┌────────────────────┐ │   │
│  │  │ web (port 8083) │  │ conduit (6167)     │ │   │
│  │  │ nginx + PHP-FPM │  │ Matrix homeserver  │ │   │
│  │  │ Drupal 11       │  │ Internal only      │ │   │
│  │  └────────┬────────┘  └────────────────────┘ │   │
│  │           │                                   │   │
│  │  ┌────────▼────────┐                          │   │
│  │  │ db (MariaDB)    │                          │   │
│  │  │ groups_chat DB  │                          │   │
│  │  └─────────────────┘                          │   │
│  └───────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────┘
```

**Key design decisions:**
- The entrypoint generates `settings.php` from environment variables on every container start (solves `COPY web/` wiping the file on rebuilds)
- Host nginx manages SSL and reverse-proxies to the container's port 8083
- Conduit is only accessible within the Docker network — no external port
- DB data persists in a Docker volume across rebuilds

---

## Credentials (Development)

| Key | Location | Purpose |
|---|---|---|
| `as_token` | `matrix_bridge.settings.yml` | Authenticate Drupal → Conduit |
| `hs_token` | `matrix_bridge.settings.yml` | Authenticate Conduit → Drupal webhook |
| Bot user | `@_drupal_bot:chat.ddev.site` | Owns all rooms, performs admin ops |
| User namespace | `@_drupal_*` | All Drupal users mapped here |
