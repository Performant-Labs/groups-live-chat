# Real-Time Chat Architecture for Drupal 11.3
## Replacing Slack with a Drupal-Native + Matrix Stack

*Prepared for Performant Labs — Drupal automated testing specialists*

---

## Table of Contents

1. [Background: The Core Tension](#1-background-the-core-tension)
2. [Is There a PHP/HTMX Discussion Library?](#2-is-there-a-phphtmx-discussion-library)
3. [The Five Architecture Options](#3-the-five-architecture-options)
4. [How Drupal 11.3 HTMX Changes the Equation](#4-how-drupal-113-htmx-changes-the-equation)
5. [Recommendation: Notification Server (Baseline)](#5-recommendation-notification-server-baseline)
6. [Upgraded Recommendation: Swap in Matrix.org](#6-upgraded-recommendation-swap-in-matrixorg)
7. [Matrix Architecture Deep Dive](#7-matrix-architecture-deep-dive)
8. [Drawback Comparison](#8-drawback-comparison)
9. [Suggested Module/Component Stack](#9-suggested-modulecomponent-stack)
10. [Resources](#10-resources)

---

## 1. Background: The Core Tension

PHP and therefore Drupal cannot hold open connections without tying up server resources. Every connection kept alive consumes memory, database connection pool slots, and process capacity — making native long-polling impractical beyond single-user dashboards.

Every viable approach involves accepting either a performance trade-off or a small sidecar service alongside Drupal.

**Drupal must remain first.** All writes and all ACL decisions flow through Drupal. No client ever submits a message directly to any sidecar.

---

## 2. Is There a PHP/HTMX Discussion Library?

**No.** As of 2026, no purpose-built, packaged PHP library for threaded discussion using HTMX exists. What does exist:

- Generic PHP/HTMX helper utilities (e.g. `htmxphp` on GitHub)
- Tutorial-level chat demos using HTMX + PHP
- No packaged, standalone threaded-discussion library

You would be building it. The Drupal 11.3 HTMX integration (see §4) makes that substantially more tractable than it was before.

---

## 3. The Five Architecture Options

### Option 1 — JavaScript Polling

A JavaScript timer fires on an interval and requests a lightweight endpoint to check for new messages.

| | |
|---|---|
| **How it works** | Drupal behavior or JS fires AJAX request every N seconds; endpoint returns messages newer than last-seen timestamp |
| **Pros** | No extra infrastructure; simple to debug; works on shared hosting |
| **Cons** | 100 users × 10s polling = 60,000 Drupal bootstraps/hour; latency = polling interval |
| **Verdict** | Acceptable for low-traffic sites only |

### Option 2 — Server-Sent Events in PHP (Long Polling)

PHP holds an HTTP connection open indefinitely, pushing data to the client.

| | |
|---|---|
| **How it works** | Browser opens `EventSource`; PHP process stays alive flushing new messages |
| **Why it fails in Drupal** | Each open SSE = one PHP process alive. ~8 MB per connection. A 2 GB server handles ~200-250 concurrent connections before exhaustion |
| **Verdict** | Admin-only dashboards only. Not viable for community platforms |

### Option 3 — Mercure Hub

An open-source Go binary that takes persistent connection handling out of Drupal entirely.

| | |
|---|---|
| **How it works** | Drupal publishes to Mercure via HTTP POST (fire-and-forget); clients subscribe directly to the Go binary via `EventSource` |
| **Pros** | No persistent PHP connections; single Go executable; one-way by design keeps Drupal in control of validation |
| **Cons** | Requires managing the Go binary; one-directional (no WebSocket); Redis not needed but access control setup is manual |
| **Verdict** | Cleanest minimal-infrastructure option. Strong if you don't need bidirectional features |

### Option 4 — Shibin Das's Notification Server

A self-hosted Node.js sidecar with Redis, presented at DrupalCon Vienna 2025.

See §5 for the full breakdown.

### Option 5 — Third-Party Services (Pusher, Firebase)

Hosted services that offload connection management entirely.

| | |
|---|---|
| **Pros** | Easiest setup; scales without involvement; good SDKs |
| **Cons** | Ongoing cost scaling with usage; data through third-party servers (GDPR); session revocation is hard — many apps keep delivering notifications after logout |
| **Verdict** | Good for prototyping. Not appropriate for group membership privacy requirements |

### Side-by-Side Comparison

| Approach | Real-Time? | Infrastructure | Scale | Complexity |
|---|---|---|---|---|
| JS Polling | 5–60s delay | Drupal only | Poor at volume | Low |
| SSE in PHP | Near-instant | Drupal only | Limited by PHP | Medium |
| Mercure Hub | Instant | Go sidecar + Drupal | Very good | Medium |
| Notification Server | Instant | Node.js + Redis + Drupal | 10K+ clients tested | Medium |
| Third-party (Pusher/Firebase) | Instant | External SaaS | Excellent | Low–Medium |

---

## 4. How Drupal 11.3 HTMX Changes the Equation

Drupal 11.3 vendors HTMX 2.0.4 in core and provides a fluent PHP builder class. This changes the frontend story significantly, though **it does not solve the transport layer problem** — you still need a sidecar for true real-time push.

### What changes

**1. HTMX polling becomes first-class**

```php
// Clean declarative polling — no custom JS required
(new Htmx())
  ->get('/api/chat/group/42/since')
  ->trigger('every 5s')
  ->swap('beforeend')
  ->target('#chat-messages')
  ->applyTo($chat_container);
```

For low-to-medium traffic this is a genuinely viable approach. No Drupal behaviors, no custom AJAX framework callbacks.

**2. Out-of-band swaps update multiple regions atomically**

One response can simultaneously update the message list, unread badge, and presence sidebar via `hx-swap-oob`. Previously awkward with Drupal's AJAX framework.

**3. The WebSocket-to-DOM bridge gets simpler**

Instead of custom JS handlers updating multiple UI regions when a WebSocket/Matrix event arrives:

```javascript
// matrix-js-sdk receives event → dispatch HTMX trigger
client.on('Room.timeline', (event, room) => {
  htmx.trigger('#chat-container', 'newMessage', { eventId: event.getId() });
});
```

HTMX then fetches Drupal-rendered HTML for that message — Drupal handles theming, permissions-aware rendering, and attachments.

**4. Reconnect fallback is trivial**

When the WebSocket drops, `hx-trigger="every 5s"` on a catch-up REST endpoint automatically fills gaps. Users never silently miss messages.

### Key Drupal 11.3 HTMX classes

| Class | Purpose |
|---|---|
| `Htmx` (factory) | Fluent builder — maps every HTMX attribute and response header to a PHP method |
| `HtmxRequestInfoTrait` | 7 helpers for reading HTMX request headers; already on `FormBase` |
| `HtmxRenderer` | Returns minimal HTML response (no theme chrome) for HTMX swaps |
| `HtmxContentViewSubscriber` | Auto-routes `_htmx_route: TRUE` routes through `HtmxRenderer` |

> **Note:** Drupal uses `data-hx-*` prefix (not `hx-*`) for valid HTML output.

---

## 5. Recommendation: Notification Server (Baseline)

For a groups.drupal.org-style groups platform, Shibin Das's Notification Server module is the most complete Drupal-native solution available — before considering Matrix.

### Architecture

```
Browser (client)
  │
  ├─── HTTP POST (chat message) ──────────────────────► Drupal
  │                                                          │
  │                                              saves entity, fires hook
  │                                                          │
  │                                          private HTTP POST to Node.js
  │                                                          │
  │                                                          ▼
  └─── WebSocket (subscribe) ──────────────────► Notification Server
                                                    (Node.js + Redis)
                                                          │
                                          broadcasts to all subscribers
                                          on that group's channel
```

### Security model

Drupal issues client IDs via a private (non-public-facing) endpoint. No client ID = no WebSocket connection. Drupal can revoke a client ID at any time — on logout, ban, session expiry. Redis stores ACL config pushed by Drupal.

### Performance (DrupalCon Vienna 2025 test results)

| Test | Result |
|---|---|
| 10,000 concurrent clients | < 200 MB RAM |
| 1,000 clients + 100 notifications/sec | Server stable |
| Instability threshold | ACL loop bottleneck (still far above typical community site needs) |

### Honest drawbacks

| Drawback | Notes |
|---|---|
| No auto-reconnect | WebSocket API doesn't reconnect; users silently fall out |
| No presence indicators | Requires separate polling |
| No typing indicators | Architectural constraint — WebSocket is receive-only |
| No delivery receipts | Gap between POST to Drupal and receipt via notification server |
| No catch-up on reconnect | Must build REST fallback endpoint yourself |
| Multi-tab via localStorage | Works but not seamless; edge cases hard to QA |
| Redis dependency | Extra service to monitor and keep in sync |
| Module maturity | Young module, one maintainer, no security advisory coverage |
| ACL loop performance ceiling | JS loop over client IDs; solvable but current technical debt |
| Client ID issuance bottleneck | Under sudden spikes, Drupal becomes chokepoint for new connections |

---

## 6. Upgraded Recommendation: Swap in Matrix.org

Matrix directly addresses every drawback in §5. The key substitution:

| Old Stack | Matrix Equivalent | Net Gain |
|---|---|---|
| Node.js (Notification Server) | **Synapse** homeserver | Production-hardened, battle-tested |
| Redis (ACL store) | **Matrix Application Service** | ACL lives in Drupal, not Redis |
| Custom WebSocket client JS | **matrix-js-sdk** | Auto-reconnect, sync tokens, multi-tab — built in |
| localStorage cross-tab hack | matrix-js-sdk SharedWorker | Proper cross-tab handling |
| ❌ No presence | **Matrix presence events** | Native |
| ❌ No typing indicators | **Matrix `m.typing`** | Native |
| ❌ No read receipts | **Matrix `m.receipt`** | Native |
| ❌ No catch-up on reconnect | **Matrix `/sync` with `since` token** | Native |
| ❌ No delivery confirmation | **Matrix event lifecycle** | Native |

**Drupal remains first.** All writes still go through Drupal. The browser never submits messages directly to Synapse.

---

## 7. Matrix Architecture Deep Dive

### 7.1 Overall Flow

```
Browser (client)
  │
  ├─── HTTP POST (new message) ────────────────────► Drupal
  │                                                      │
  │                                         validate, ACL check,
  │                                         save chat_message entity
  │                                                      │
  │                                      Drupal Appservice Module
  │                                      sends m.room.message event
  │                                      to Synapse via Client-Server API
  │                                                      │
  │                                                      ▼
  └─── matrix-js-sdk (WebSocket /sync) ────────────► Synapse
                                                    homeserver
                                                        │
                                           delivers to all room members:
                                           • new messages
                                           • presence updates
                                           • typing indicators
                                           • read receipts
                                                        │
                                                        ▼
                                            All connected browsers
                                            (matrix-js-sdk handles
                                             reconnect, catch-up,
                                             multi-tab automatically)
```

### 7.2 The Application Service: Drupal as Gatekeeper

A custom Drupal module registers with Synapse as a Matrix Application Service (appservice), giving Drupal administrative authority over the entire Matrix namespace for this deployment.

#### Group ↔ Room Lifecycle (Drupal owns it)

| Drupal Event | Matrix Action |
|---|---|
| Group created | Drupal creates Matrix room via appservice |
| User joins Group | Drupal invites user to Matrix room |
| User leaves / removed | Drupal kicks user from room (immediate revocation) |
| User banned | Drupal bans user from room + invalidates access token |
| Group deleted | Drupal tombstones / deactivates room |

#### Token Issuance (Drupal, not Matrix, issues credentials)

```php
// In Drupal: on session start / group access grant
$matrix_token = $appservice->registerUserAndIssueToken($drupal_uid);
// Token is scoped: this user, these rooms only
// Delivered via drupalSettings — same pattern as Notification Server client IDs
// Revoked on logout, ban, group removal — Drupal calls Synapse admin API
```

This preserves the security model from the Notification Server (Drupal retains revocation authority) while replacing Redis with Matrix's own auth infrastructure.

### 7.3 Browser Integration

```javascript
// drupalSettings delivers homeserver URL + access token (Drupal-issued)
const { homeserverUrl, accessToken, matrixUserId, roomId } = drupalSettings.matrix;

const client = matrixSdk.createClient({
  baseUrl: homeserverUrl,
  accessToken,
  userId: matrixUserId,
});

await client.startClient({ initialSyncLimit: 20 });

// New messages
client.on('Room.timeline', (event, room) => {
  if (event.getType() === 'm.room.message' && room.roomId === roomId) {
    // Option A: Let HTMX fetch Drupal-rendered HTML for the message
    htmx.trigger('#chat-container', 'newMessage', { eventId: event.getId() });
    // Option B: Render directly from Matrix event data (no Drupal round-trip)
    appendMessage(event.getContent());
  }
});

// Typing indicators — free
client.on('RoomMember.typing', (event, member) => {
  updateTypingIndicator(member);
});

// Presence — free
client.on('User.presence', (event, user) => {
  updatePresenceIndicator(user);
});
```

### 7.4 HTMX's Role in the Matrix Stack

HTMX is no longer the real-time transport — matrix-js-sdk owns that. It retains a clear role:

| Responsibility | Tool |
|---|---|
| Message rendering | Matrix event → `htmx:trigger` → HTMX fetches Drupal-rendered HTML |
| Unread badge / notification count | HTMX OOB swap (`hx-swap-oob`) |
| Sending messages / reactions | HTTP POST to Drupal via HTMX |
| Presence sidebar (authoritative membership) | `hx-trigger="every 30s"` polling Drupal |
| Thread replies / form interactions | HTMX forms posting to Drupal |

**The split:** Matrix handles delivery and UX state. Drupal handles rendering and authority.

### 7.5 Homeserver Choice

| Option | Language | Notes |
|---|---|---|
| **Synapse** | Python | Production-proven, most features, heavier memory footprint |
| **Dendrite** | Go | Lighter, faster startup, less mature — closer to Mercure's operational profile |

For a community platform starting out, Dendrite is worth evaluating — it has a similar operational footprint to the Node.js + Redis sidecar it replaces.

---

## 8. Drawback Comparison

| Notification Server Drawback | Status with Matrix |
|---|---|
| No auto-reconnect | ✅ Solved — matrix-js-sdk built-in with exponential backoff |
| No presence / typing indicators | ✅ Solved — native Matrix events |
| No read receipts / delivery confirmation | ✅ Solved — native |
| Multi-tab localStorage hack | ✅ Solved — matrix-js-sdk SharedWorker |
| No catch-up on reconnect | ✅ Solved — `/sync?since=<token>` |
| Redis dependency | ✅ Eliminated |
| ACL loop performance ceiling | ✅ Eliminated — Synapse handles room ACL natively |
| Module maturity risk | ⚠️ Shifted — Synapse is mature; custom appservice Drupal module is new |
| Infrastructure complexity | ⚠️ Comparable — Synapse replaces Node.js + Redis; heavier but more capable |
| Client ID issuance bottleneck | ✅ Solved — token issuance is cheap; `/sync` load goes to Synapse, not Drupal |

---

## 9. Suggested Module/Component Stack

| Layer | Component | Notes |
|---|---|---|
| Group ACL | `group` module | Unchanged from baseline recommendation |
| Message persistence | Custom `chat_message` entity | Lightweight; no node/comment overhead |
| Write buffer | Drupal Queue API | Optional; decouples real-time delivery from DB persistence |
| Matrix bridge | Custom `matrix_bridge` Drupal module | Implements appservice, token issuance, room lifecycle hooks |
| Homeserver | Synapse or Dendrite | Self-hosted; no federation needed |
| Browser SDK | matrix-js-sdk | Handles WebSocket, sync, reconnect, multi-tab |
| DOM updates | Drupal 11.3 HTMX | Rendering + OOB swaps for badges, sidebars, forms |
| Catch-up fallback | REST endpoint + HTMX polling | `/api/chat/{group}/{since}` for reconnect gap-fill |

### Message Flow

1. User types message → JavaScript fires HTTP POST to Drupal
2. Drupal validates, checks group membership via `group` module, saves `chat_message` entity
3. `hook_entity_insert()` fires → `matrix_bridge` appservice sends `m.room.message` to Synapse
4. Synapse broadcasts to all WebSocket subscribers in that Matrix room
5. matrix-js-sdk receives event → dispatches HTMX trigger → HTMX fetches Drupal-rendered HTML
6. Browser appends rendered message to chat UI
7. If user missed the WebSocket event (reconnect, background tab) → matrix-js-sdk `/sync?since=<token>` delivers all missed events automatically

---

## 10. Resources

- [Notification Server module](https://www.drupal.org/project/notification_server) — drupal.org/project/notification_server
- [Group module](https://www.drupal.org/project/group) — drupal.org/project/group
- [Mercure Hub](https://mercure.rocks) — open-source Go SSE server
- [Synapse homeserver](https://github.com/element-hq/synapse) — element-hq/synapse
- [Dendrite homeserver](https://github.com/matrix-org/dendrite) — matrix-org/dendrite
- [matrix-js-sdk](https://github.com/matrix-org/matrix-js-sdk) — matrix-org/matrix-js-sdk
- [Matrix Application Service spec](https://spec.matrix.org/latest/application-service-api/)
- [HTMX in Drupal 11.3](./HTMX_IN_DRUPAL_11.3.md) — local orientation guide
- [DrupalCon Vienna 2025](https://events.drupal.org/vienna2025) — Shibin Das: *Supercharging Drupal with Real-Time Notifications Using WebSockets and Redis*
- [OpenLucius reference implementation](https://www.drupal.org/project/openlucius)
