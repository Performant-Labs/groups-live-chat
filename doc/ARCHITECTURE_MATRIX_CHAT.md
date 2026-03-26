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
│  │  messages()→ Load entities → HTML fragment │   │
│  ├──────────────────────────────────────────┤   │
│  │           SyncController                  │   │
│  │  sync()   → Query chat_message WHERE      │   │
│  │             id > since → JSON             │   │
│  ├──────────────────────────────────────────┤   │
│  │           MatrixBridgeHooks               │   │
│  │  Group created  → createRoom()            │   │
│  │  Member added   → inviteUser() + join     │   │
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

### 4. Matrix Is an Internal Transport

Conduit is only accessible within the Docker network (`conduit:6167`). No port is exposed to the host. The browser cannot reach Conduit. All Matrix operations go through `MatrixClient` inside Drupal.

---

## Current Capabilities

| Feature | Status | How |
|---|---|---|
| Group → auto-creates chat room | ✅ | `entity_insert` hook → `createRoom()` |
| Member added → joins room | ✅ | `entity_insert` hook → `inviteUser()` |
| Member removed → kicked from room | ✅ | `entity_delete` hook → `kickUser()` |
| Send message (browser) | ✅ | HTMX POST → `ChatController::send()` |
| Receive messages (browser) | ✅ | JS long-poll → `SyncController::sync()` |
| Message persistence | ✅ | `chat_message` entity in Drupal DB |
| Author attribution | ✅ | Drupal session `$currentUser->id()` |
| Dark-mode chat UI | ✅ | CSS with gradient bubbles, animations |
| Auto-scroll on new messages | ✅ | Drupal behaviors JS |

---

## Current Limitations

| Feature | Status | Why |
|---|---|---|
| Typing indicators | ❌ | Requires sub-second updates; 2s polling is too slow |
| Read receipts | ❌ | Same — needs persistent connection |
| Presence (online/offline) | ❌ | Same |
| Bidirectional Matrix sync | ❌ | Webhook receives events but doesn't ingest them |
| Message editing/deletion | ❌ | Not implemented |
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

### Message Editing/Deletion

1. Add `PUT /group/{id}/chat/message/{msg_id}` and `DELETE` routes
2. Send Matrix `m.room.message` with `m.relates_to.rel_type: "m.replace"` for edits
3. Update/soft-delete the `ChatMessage` entity
4. Sync endpoint already handles incremental updates

---

## File Inventory

```
web/modules/custom/matrix_bridge/
├── matrix_bridge.info.yml              # Module definition
├── matrix_bridge.services.yml          # MatrixClient service + autowire alias
├── matrix_bridge.routing.yml           # All routes (webhook, panel, messages, send, sync)
├── matrix_bridge.libraries.yml         # CSS + JS library definition
├── matrix_bridge.module                # hook_theme() for templates
├── config/install/
│   └── matrix_bridge.settings.yml      # as_token, hs_token, homeserver_url
├── src/
│   ├── MatrixClient.php                # Guzzle wrapper, appservice masquerading
│   ├── Entity/ChatMessage.php          # Content entity (uid, group_id, body, matrix_event_id)
│   ├── Controller/
│   │   ├── ChatController.php          # panel(), messages(), send()
│   │   ├── SyncController.php          # sync() — DB-based long-poll
│   │   └── AppserviceController.php    # Webhook (hs_token auth, no-op body)
│   └── Hook/
│       └── MatrixBridgeHooks.php       # Group lifecycle → Matrix room operations
├── css/chat.css                        # Dark-mode chat UI
├── js/chat.js                          # Long-poll sync + auto-scroll
├── templates/
│   ├── matrix-chat-panel.html.twig     # Full chat panel with HTMX attributes
│   └── matrix-chat-messages.html.twig  # Message bubbles fragment
└── tests/
    ├── full_e2e_test.php               # 19-test comprehensive suite
    ├── multi_user_test.php             # Multi-user conversation proof
    └── phase3_test.php                 # Group lifecycle hook tests

.ddev/
├── docker-compose.conduit.yaml         # Conduit sidecar definition
└── conduit/
    ├── conduit.toml                    # Homeserver config
    └── appservice-drupal.yaml          # Appservice registration
```

---

## API Contract

### Endpoints

| Method | Path | Returns | Purpose |
|---|---|---|---|
| `GET` | `/group/{id}/chat` | HTML page | Full chat panel |
| `GET` | `/group/{id}/chat/messages` | HTML fragment | Message list (HTMX) |
| `POST` | `/group/{id}/chat/send` | HTML fragment | Send + return updated list |
| `GET` | `/group/{id}/chat/sync?since={id}` | JSON | Incremental new messages |
| `PUT` | `/matrix/appservice/transactions/{txnId}` | JSON `{}` | Matrix webhook (no-op) |

### Sync JSON Schema

```json
{
  "messages": [
    {
      "author": "string",
      "body": "string",
      "time": "HH:MM",
      "is_own": true
    }
  ],
  "last_id": 42,
  "has_new": true
}
```

---

## Credentials (Development)

| Key | Location | Purpose |
|---|---|---|
| `as_token` | `matrix_bridge.settings.yml` | Authenticate Drupal → Conduit |
| `hs_token` | `matrix_bridge.settings.yml` | Authenticate Conduit → Drupal webhook |
| Bot user | `@_drupal_bot:chat.ddev.site` | Owns all rooms, performs admin ops |
| User namespace | `@_drupal_*` | All Drupal users mapped here |
