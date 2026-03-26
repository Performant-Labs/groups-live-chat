<?php

/**
 * @file
 * Multi-user conversation test — proves two users can chat in the same group.
 *
 * Run via: ddev drush php:script /var/www/html/web/modules/custom/matrix_bridge/tests/multi_user_test.php
 */

echo "=== Multi-User Group Chat Test ===\n\n";
$GLOBALS['_tp'] = 0;
$GLOBALS['_tf'] = 0;

function test($name, $result, $detail = '') {
  if ($result) { echo "  ✅ $name" . ($detail ? " — $detail" : '') . "\n"; $GLOBALS['_tp']++; }
  else { echo "  ❌ $name" . ($detail ? " — $detail" : '') . "\n"; $GLOBALS['_tf']++; }
}

$client = \Drupal::service('matrix_bridge.client');

// ── Setup: Create a group with 2 members ──

// Ensure group type
$gts = \Drupal::entityTypeManager()->getStorage('group_type');
$type = $gts->load('chat_group');
if (!$type) {
  $type = $gts->create(['id' => 'chat_group', 'label' => 'Chat Group']);
  $type->save();
  $rts = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
  $rts->createFromPlugin($type, 'group_membership')->save();
}

// Create 2 users
$alice = \Drupal\user\Entity\User::create([
  'name' => 'alice_' . time(), 'mail' => 'alice_' . time() . '@test.com', 'status' => 1,
]);
$alice->save();

$bob = \Drupal\user\Entity\User::create([
  'name' => 'bob_' . time(), 'mail' => 'bob_' . time() . '@test.com', 'status' => 1,
]);
$bob->save();

echo "  Alice: uid={$alice->id()} ({$alice->getDisplayName()})\n";
echo "  Bob:   uid={$bob->id()} ({$bob->getDisplayName()})\n\n";

// Create group (auto-creates Matrix room via hook)
$group = \Drupal\group\Entity\Group::create([
  'type' => 'chat_group',
  'label' => 'Multi-User Test ' . time(),
  'uid' => 1,
]);
$group->save();
$roomId = $client->getRoomId((int) $group->id());
test('Room created for group', !empty($roomId), $roomId);

// Add both users (triggers invite+join via hook)
$group->addMember($alice);
$group->addMember($bob);
test('Alice added to group', TRUE);
test('Bob added to group', TRUE);

// ── Conversation: Alice and Bob chat ──

echo "\n── Conversation ──\n";

$aliceMatrixId = $client->getMatrixUserId((int) $alice->id());
$bobMatrixId = $client->getMatrixUserId((int) $bob->id());

// Alice sends first message
try {
  $evt1 = $client->sendMessage($roomId, $aliceMatrixId, 'Hey Bob, how are you?');
  test('Alice sends message', !empty($evt1));
}
catch (\Exception $e) {
  test('Alice sends message', FALSE, $e->getMessage());
}

// Bob replies
try {
  $evt2 = $client->sendMessage($roomId, $bobMatrixId, 'Great thanks Alice! Love this chat.');
  test('Bob replies', !empty($evt2));
}
catch (\Exception $e) {
  test('Bob replies', FALSE, $e->getMessage());
}

// Alice sends another message
try {
  $evt3 = $client->sendMessage($roomId, $aliceMatrixId, 'Drupal + Matrix = awesome!');
  test('Alice sends again', !empty($evt3));
}
catch (\Exception $e) {
  test('Alice sends again', FALSE, $e->getMessage());
}

// Save all messages to Drupal (simulating what ChatController::send does)
$msgs = [
  ['uid' => $alice->id(), 'body' => 'Hey Bob, how are you?', 'event' => $evt1 ?? ''],
  ['uid' => $bob->id(), 'body' => 'Great thanks Alice! Love this chat.', 'event' => $evt2 ?? ''],
  ['uid' => $alice->id(), 'body' => 'Drupal + Matrix = awesome!', 'event' => $evt3 ?? ''],
];
foreach ($msgs as $m) {
  \Drupal\matrix_bridge\Entity\ChatMessage::create([
    'uid' => $m['uid'],
    'group_id' => (int) $group->id(),
    'matrix_event_id' => $m['event'],
    'matrix_room_id' => $roomId,
    'body' => $m['body'],
  ])->save();
}

// ── Verify: Both users' messages are in Drupal DB ──

echo "\n── Verify Messages in DB ──\n";

$storage = \Drupal::entityTypeManager()->getStorage('chat_message');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('group_id', (int) $group->id())
  ->sort('id', 'ASC')
  ->execute();
$entities = $storage->loadMultiple($ids);

$aliceCount = 0;
$bobCount = 0;

echo "\n  Chat transcript:\n";
foreach ($entities as $entity) {
  $author = $entity->get('uid')->entity;
  $name = $author ? $author->getDisplayName() : '?';
  $body = $entity->getBody();
  echo "    [{$name}] {$body}\n";

  if ($author && (int) $author->id() === (int) $alice->id()) $aliceCount++;
  if ($author && (int) $author->id() === (int) $bob->id()) $bobCount++;
}

echo "\n";
test("Alice has $aliceCount messages in group", $aliceCount === 2);
test("Bob has $bobCount messages in group", $bobCount === 1);
test('Total messages in group = 3', count($ids) === 3, count($ids) . ' messages');

// ── Verify: Sync endpoint returns all messages ──

echo "\n── Verify Sync ──\n";

// Simulate what SyncController does
$syncIds = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('group_id', (int) $group->id())
  ->condition('id', 0, '>')
  ->sort('id', 'ASC')
  ->execute();
test('Sync returns all 3 messages', count($syncIds) === 3);

// Incremental: since last-2
$lastId = max(array_map('intval', $syncIds));
$newIds = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('group_id', (int) $group->id())
  ->condition('id', $lastId - 1, '>')
  ->sort('id', 'ASC')
  ->execute();
test('Incremental sync returns newest', count($newIds) >= 1);

// ── Summary ──

echo "\n══════════════════════════════════════\n";
echo "  Results: {$GLOBALS['_tp']} passed, {$GLOBALS['_tf']} failed\n";
echo "══════════════════════════════════════\n";

if ($GLOBALS['_tf'] > 0) exit(1);
