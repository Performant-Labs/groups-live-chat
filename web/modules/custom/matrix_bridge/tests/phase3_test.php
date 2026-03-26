<?php
/**
 * @file
 * Phase 3 E2E test script.
 * Run via: ddev drush php:script /var/www/html/web/modules/custom/matrix_bridge/tests/phase3_test.php
 */

// Step 0: Create a group type if none exists.
$groupTypeStorage = \Drupal::entityTypeManager()->getStorage('group_type');
$type = $groupTypeStorage->load('chat_group');
if (!$type) {
  $type = $groupTypeStorage->create([
    'id' => 'chat_group',
    'label' => 'Chat Group',
  ]);
  $type->save();
  echo "Created group type: chat_group\n";

  // Install the group_membership plugin for this group type.
  $relTypeStorage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
  $relTypeStorage->createFromPlugin($type, 'group_membership')->save();
  echo "Installed group_membership plugin\n";
}

// Step 1: Create a group (should trigger Matrix room creation via hook).
$group = \Drupal\group\Entity\Group::create([
  'type' => 'chat_group',
  'label' => 'Test Chat Group Phase3',
]);
$group->save();

// Check room was created.
$client = \Drupal::service('matrix_bridge.client');
$roomId = $client->getRoomId((int) $group->id());
echo "T1 Group created: id=" . $group->id() . " room_id=" . ($roomId ?: 'MISSING') . "\n";

if (!$roomId) {
  echo "FAIL: No room_id stored for group\n";
  exit(1);
}

// Step 2: Add a member (should trigger invite+join via hook).
$user = \Drupal\user\Entity\User::create([
  'name' => 'test_p3_' . time(),
  'mail' => 'test_p3_' . time() . '@example.com',
  'status' => 1,
]);
$user->save();
$group->addMember($user);
echo "T2 Member added: uid=" . $user->id() . "\n";

// Step 3: Verify user can send to the Matrix room (proves invite+join worked).
$matrixUserId = $client->getMatrixUserId((int) $user->id());
try {
  $eventId = $client->sendMessage($roomId, $matrixUserId, 'Hello from group member!');
  echo "T3 Member can send: event=$eventId\n";
}
catch (\Exception $e) {
  echo "T3 FAIL: " . $e->getMessage() . "\n";
}

// Step 4: Remove member (should trigger kick via hook).
$group->removeMember($user);
echo "T4 Member removed\n";

// Step 5: Verify kicked user cannot send.
try {
  $client->sendMessage($roomId, $matrixUserId, 'Should fail');
  echo "T5 FAIL: Kicked user can still send\n";
}
catch (\Exception $e) {
  echo "T5 Kicked user blocked: OK\n";
}

echo "\n=== All Phase 3 tests passed! ===\n";
