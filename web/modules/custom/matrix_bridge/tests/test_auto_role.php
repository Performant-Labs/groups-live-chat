<?php

/**
 * Full test: create group type → create group → add member → verify access.
 * Roles should be auto-created and auto-assigned.
 */

echo "=== Full Auto-Role E2E Test ===\n\n";

// Delete any previous test roles to test fresh creation.
$roleStorage = \Drupal::entityTypeManager()->getStorage('group_role');
foreach (['test_e2e-member', 'test_e2e-admin', 'test_e2e-outsider'] as $rid) {
  $r = $roleStorage->load($rid);
  if ($r) { $r->delete(); }
}

$gts = \Drupal::entityTypeManager()->getStorage('group_type');
$type = $gts->load('test_e2e');
if (!$type) {
  $type = $gts->create(['id' => 'test_e2e', 'label' => 'Test E2E']);
  $type->save();
  $rts = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
  if (!$rts->load('test_e2e-group_membership')) {
    $rts->createFromPlugin($type, 'group_membership')->save();
  }
  echo "✓ Created group type 'test_e2e'\n";
} else {
  echo "✓ Group type 'test_e2e' exists\n";
}

// Verify no roles yet.
$before = $roleStorage->loadByProperties(['group_type' => 'test_e2e']);
echo "  Roles before: " . count($before) . "\n";
assert(count($before) === 0, 'Expected 0 roles before');

// Step 1: Create group (triggers ensureGroupRoles).
$admin = \Drupal\user\Entity\User::load(1);
$group = \Drupal\group\Entity\Group::create([
  'type' => 'test_e2e',
  'label' => 'E2E Role Test ' . time(),
  'uid' => 1,
  'status' => 1,
]);
$group->save();
echo "✓ Group created: ID=" . $group->id() . "\n";

// Verify roles were created.
$roleStorage->resetCache();
$after = $roleStorage->loadByProperties(['group_type' => 'test_e2e']);
echo "  Roles after: " . count($after) . "\n";
assert(count($after) === 3, 'Expected 3 roles after group creation');

foreach ($after as $r) {
  echo "  → " . $r->id() . " (" . $r->label() . ")\n";
}

// Step 2: Add admin as member (triggers auto-assign of member + admin roles).
$group->addMember($admin);
echo "✓ Added admin as member\n";

// Verify roles were assigned.
$member = $group->getMember($admin);
$memberRoles = $member->getRoles();
echo "  Member roles: " . implode(", ", array_keys($memberRoles)) . "\n";
$hasAdmin = isset($memberRoles['test_e2e-admin']);
$hasMember = isset($memberRoles['test_e2e-member']);
echo "  Has member role: " . ($hasMember ? "YES ✓" : "NO ✗") . "\n";
echo "  Has admin role:  " . ($hasAdmin ? "YES ✓" : "NO ✗") . "\n";

// Step 3: Verify access.
\Drupal::entityTypeManager()->getAccessControlHandler('group')->resetCache();
$access = $group->access('view', $admin, TRUE);
echo "  Admin can view: " . ($access->isAllowed() ? "YES ✓" : "NO ✗") . "\n";

if ($hasMember && $hasAdmin && $access->isAllowed()) {
  echo "\n=== ALL TESTS PASSED ===\n";
} else {
  echo "\n=== SOME TESTS FAILED ===\n";
}
