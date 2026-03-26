# Matrix Bridge for Drupal

Real-time group chat for Drupal 11, powered by the [Matrix](https://matrix.org/) protocol and [HTMX](https://htmx.org/).

Drupal manages all identity, permissions, and message persistence. A [Conduit](https://conduit.rs/) Matrix homeserver runs as a DDEV sidecar for real-time transport. The browser never talks to Matrix directly.

```
Browser ←→ Drupal (HTMX + long-poll) ←→ Conduit Matrix (transport)
```

## Features

- **Automatic room provisioning** — creating a Drupal Group auto-creates a Matrix room
- **Membership sync** — adding/removing group members auto-invites/kicks from the Matrix room
- **Real-time messaging** — 2-second long-poll sync with exponential backoff
- **Dark-mode chat UI** — gradient message bubbles, auto-scroll, connected status badge
- **Drupal-native ACLs** — Group module roles + permissions enforced on every endpoint
- **Auto-managed group roles** — Member, Admin, Outsider roles created automatically

## Requirements

- [DDEV](https://ddev.readthedocs.io/) v1.23+
- Docker Desktop (or OrbStack / Colima)
- PHP 8.3
- Drupal 11.3
- [Group](https://www.drupal.org/project/group) module (`drupal/group`)

## Quick Start

### 1. Clone and start

```bash
git clone <repo-url> pl-d11-test
cd pl-d11-test
ddev start
ddev composer install
```

### 2. Install Drupal + enable modules

```bash
ddev drush site:install --account-name=admin --account-pass=admin -y
ddev drush en group matrix_bridge -y
ddev drush cr
```

### 3. Register the Matrix appservice

The Conduit sidecar starts automatically with `ddev start`. You need to register the appservice once via Conduit's admin room.

**a) Enable registration temporarily:**

Edit `.ddev/conduit/conduit.toml` and set `allow_registration = true`, then restart:

```bash
ddev restart
```

**b) Create the admin user:**

```bash
ddev exec curl -s -X POST \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme","auth":{"type":"m.login.dummy"}}' \
  'http://conduit:6167/_matrix/client/v3/register'
```

Save the `access_token` from the response.

**c) Register the appservice via the admin room:**

This requires sending a Matrix message to `@conduit:chat.ddev.site` in the admin room. See `doc/BUILD_LOG_MATRIX_CHAT.md` → Lesson 1 for the full procedure. The short version:

1. Create the admin room and invite `@conduit:chat.ddev.site`
2. Send the `register-appservice` command with the YAML from `.ddev/conduit/appservice-drupal.yaml`
3. Conduit confirms registration

**d) Lock down registration:**

Set `allow_registration = false` in `conduit.toml` and restart:

```bash
ddev restart
```

### 4. Create a group type and group

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

Then create a group (this auto-creates the Matrix room + group roles):

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

### 5. Open the chat

```bash
ddev drush uli
```

Log in and navigate to `/group/1/chat`.

## Project Structure

```
web/modules/custom/matrix_bridge/
├── matrix_bridge.info.yml          # Module definition
├── matrix_bridge.services.yml      # Service definitions
├── matrix_bridge.routing.yml       # Routes (chat, sync, webhook)
├── matrix_bridge.libraries.yml     # CSS + JS
├── matrix_bridge.module            # hook_theme()
├── config/install/
│   └── matrix_bridge.settings.yml  # Tokens + homeserver URL
├── src/
│   ├── MatrixClient.php            # Guzzle wrapper with appservice masquerading
│   ├── Entity/ChatMessage.php      # Message content entity
│   ├── Controller/
│   │   ├── ChatController.php      # Chat panel, messages, send
│   │   ├── SyncController.php      # Long-poll sync endpoint
│   │   └── AppserviceController.php # Matrix webhook
│   └── Hook/
│       └── MatrixBridgeHooks.php   # Group lifecycle → Matrix + role management
├── css/chat.css
├── js/chat.js
└── templates/
    ├── matrix-chat-panel.html.twig
    └── matrix-chat-messages.html.twig

.ddev/
├── docker-compose.conduit.yaml     # Conduit sidecar
└── conduit/
    ├── conduit.toml                # Homeserver config
    └── appservice-drupal.yaml      # Appservice registration
```

## API Endpoints

| Method | Path | Returns | Purpose |
|---|---|---|---|
| `GET` | `/group/{id}/chat` | HTML | Full chat panel |
| `GET` | `/group/{id}/chat/messages` | HTML fragment | Message list (HTMX target) |
| `POST` | `/group/{id}/chat/send` | HTML fragment | Send message |
| `GET` | `/group/{id}/chat/sync?since={id}` | JSON | Incremental new messages |
| `PUT` | `/matrix/appservice/transactions/{txnId}` | JSON | Matrix webhook |

## Running Tests

```bash
# Full E2E suite (19 tests)
ddev drush php:script web/modules/custom/matrix_bridge/tests/full_e2e_test.php

# Multi-user conversation test (11 tests)
ddev drush php:script web/modules/custom/matrix_bridge/tests/multi_user_test.php

# Auto-role creation test
ddev drush php:script web/modules/custom/matrix_bridge/tests/test_auto_role.php
```

## Architecture

See [doc/ARCHITECTURE_MATRIX_CHAT.md](doc/ARCHITECTURE_MATRIX_CHAT.md) for the full design document, including:

- System diagram
- Design principles (why Drupal owns everything)
- Current capabilities and limitations
- What's needed for typing indicators, presence, and read receipts
- API contract and JSON schemas

## Build Log

See [doc/BUILD_LOG_MATRIX_CHAT.md](doc/BUILD_LOG_MATRIX_CHAT.md) for 16 lessons learned during development.

## Known Limitations

- **No typing indicators, presence, or read receipts** — requires a push layer (Mercure or WebSocket sidecar)
- **No bidirectional Matrix sync** — external Matrix clients can't send messages into Drupal yet
- **No file attachments or message editing** — not implemented
- **Conduit /sync bug** — appservice-masqueraded `/sync` returns 500 on Conduit v0.10.12

## Roadmap

1. **Mercure sidecar** for sub-second push (typing, presence, read receipts)
2. **Bidirectional sync** — ingest Matrix events into Drupal via the appservice webhook
3. **File attachments** — upload via Drupal, relay to Matrix media API
4. **Message editing/deletion** — Matrix `m.replace` events

## Guided Tour

After completing the Quick Start, walk through these steps to see everything in action.

### 1. Log in as admin

Run `ddev drush uli` and open the one-time link in your browser.

### 2. View all groups → `/admin/group`

You should see your groups listed with type "Chat Group", status "Published", and owner "admin". Each has Edit + dropdown operations.

### 3. Click into a group

Click a group name to see the group page with tabs: View, Edit, Delete, Members, etc. Click **Members** to see who's in the group.

### 4. Open the chat → `/group/1/chat`

The dark-mode chat panel appears with:
- 💬 **Group Chat** header + green **Connected** badge
- Message history (if any messages have been sent)
- Text input + purple **Send** button

### 5. Send a message

Type something in the input box and click Send. Your message appears as a right-aligned purple bubble with a timestamp. The long-poll sync picks up new messages within ~2 seconds.

### 6. Check a different group's chat

Navigate to `/group/2/chat` (if you have a second group). Different group = different Matrix room = different message history.

### 7. View the raw sync API → `/group/1/chat/sync?since=0`

Returns JSON with all messages:
```json
{ "messages": [...], "last_id": 42, "has_new": true }
```
Try `?since=99999` to get an empty response.

### 8. View the messages fragment → `/group/1/chat/messages`

Returns the bare HTML fragment that HTMX swaps into the chat panel.

### 9. What's happening at each step

| Step | Layer | What's happening |
|---|---|---|
| 2–3 | Drupal Group module | Entity list + access via auto-created roles |
| 4 | `ChatController::panel()` | Renders Twig template, attaches HTMX + chat.js |
| 5 | `ChatController::send()` | Saves `ChatMessage` entity + relays to Matrix |
| 6 | Per-group isolation | Each group maps to a unique Matrix room via `key_value` |
| 7 | `SyncController::sync()` | DB query: `SELECT * FROM chat_message WHERE id > :since` |
| 8 | `ChatController::messages()` | Entity load → Twig render → HTML fragment |

## License

This project is licensed under the GPL-2.0-or-later license, consistent with Drupal core.
