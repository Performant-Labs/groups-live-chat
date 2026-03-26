<?php

declare(strict_types=1);

namespace Drupal\matrix_bridge\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\matrix_bridge\MatrixClient;
use Psr\Log\LoggerInterface;

/**
 * Entity lifecycle hooks bridging Drupal Group events to Matrix room actions.
 *
 * Hook mapping:
 * - Group created   → createRoom() + store room_id in key_value
 * - User joins      → ensureUserExists() + inviteUser() (auto-join)
 * - User leaves     → kickUser()
 * - Group deleted   → tombstone room (message)
 */
class MatrixBridgeHooks {

  protected LoggerInterface $logger;

  public function __construct(
    protected MatrixClient $matrixClient,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('matrix_bridge');
  }

  /**
   * Creates a Matrix room when a Group is created.
   */
  #[Hook('entity_insert')]
  public function onEntityInsert(EntityInterface $entity): void {
    if ($entity instanceof GroupInterface) {
      $this->onGroupCreated($entity);
    }
    elseif ($entity instanceof GroupRelationshipInterface) {
      $this->onMembershipGranted($entity);
    }
  }

  /**
   * Handles membership removal (user leaves or is removed).
   */
  #[Hook('entity_delete')]
  public function onEntityDelete(EntityInterface $entity): void {
    if ($entity instanceof GroupInterface) {
      $this->onGroupDeleted($entity);
    }
    elseif ($entity instanceof GroupRelationshipInterface) {
      $this->onMembershipRevoked($entity);
    }
  }

  /**
   * Group created → create a Matrix room, ensure roles, and store the room ID.
   */
  protected function onGroupCreated(GroupInterface $group): void {
    // Ensure the group type has proper roles/permissions.
    $this->ensureGroupRoles($group->getGroupType()->id());

    try {
      $alias = '_drupal_group_' . $group->id();
      $roomId = $this->matrixClient->createRoom(
        $group->label() ?? "Group {$group->id()}",
        $alias,
      );

      // Store the group → room mapping.
      $this->matrixClient->setRoomId((int) $group->id(), $roomId);

      $this->logger->info('Created Matrix room @room for group @group.', [
        '@room' => $roomId,
        '@group' => $group->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create Matrix room for group @group: @error', [
        '@group' => $group->id(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Ensures group roles exist for a given group type.
   *
   * Creates Member, Admin, and Outsider roles with sensible default
   * permissions if they don't already exist.
   *
   * @param string $groupTypeId
   *   The group type machine name (e.g. 'chat_group').
   */
  public function ensureGroupRoles(string $groupTypeId): void {
    $roleStorage = \Drupal::entityTypeManager()->getStorage('group_role');

    // Member role — can view group and memberships.
    $memberId = $groupTypeId . '-member';
    if (!$roleStorage->load($memberId)) {
      $member = $roleStorage->create([
        'id' => $memberId,
        'label' => 'Member',
        'group_type' => $groupTypeId,
        'scope' => 'individual',
        'global_role' => NULL,
        'admin' => FALSE,
        'weight' => 0,
      ]);
      $member->grantPermissions([
        'view group',
        'view group_membership content',
      ]);
      $member->save();
      $this->logger->info('Created group role @role for type @type.', [
        '@role' => $memberId,
        '@type' => $groupTypeId,
      ]);
    }

    // Admin role — full admin.
    $adminId = $groupTypeId . '-admin';
    if (!$roleStorage->load($adminId)) {
      $admin = $roleStorage->create([
        'id' => $adminId,
        'label' => 'Admin',
        'group_type' => $groupTypeId,
        'scope' => 'individual',
        'global_role' => NULL,
        'admin' => TRUE,
        'weight' => 1,
      ]);
      $admin->save();
      $this->logger->info('Created group role @role for type @type.', [
        '@role' => $adminId,
        '@type' => $groupTypeId,
      ]);
    }

    // Outsider role — authenticated users can view published groups.
    $outsiderId = $groupTypeId . '-outsider';
    if (!$roleStorage->load($outsiderId)) {
      $outsider = $roleStorage->create([
        'id' => $outsiderId,
        'label' => 'Outsider',
        'group_type' => $groupTypeId,
        'scope' => 'outsider',
        'global_role' => 'authenticated',
        'admin' => FALSE,
        'weight' => 0,
      ]);
      $outsider->grantPermissions([
        'view group',
      ]);
      $outsider->save();
      $this->logger->info('Created group role @role for type @type.', [
        '@role' => $outsiderId,
        '@type' => $groupTypeId,
      ]);
    }
  }

  /**
   * Membership granted → auto-assign role, ensure Matrix user, invite.
   */
  protected function onMembershipGranted(GroupRelationshipInterface $relationship): void {
    if (!str_starts_with($relationship->getPluginId(), 'group_membership')) {
      return;
    }

    $group = $relationship->getGroup();
    $groupTypeId = $group->getGroupType()->id();
    $drupalUser = $relationship->getEntity();
    if (!$drupalUser) {
      return;
    }

    // Auto-assign group roles — admin role for group owner, member for everyone.
    $rolesToAdd = [];
    $memberRoleId = $groupTypeId . '-member';
    if (\Drupal::entityTypeManager()->getStorage('group_role')->load($memberRoleId)) {
      $rolesToAdd[] = $memberRoleId;
    }
    if ((int) $drupalUser->id() === (int) $group->getOwnerId()) {
      $adminRoleId = $groupTypeId . '-admin';
      if (\Drupal::entityTypeManager()->getStorage('group_role')->load($adminRoleId)) {
        $rolesToAdd[] = $adminRoleId;
      }
    }
    if (!empty($rolesToAdd)) {
      $rolesField = $relationship->get('group_roles');
      foreach ($rolesToAdd as $roleId) {
        $rolesField->appendItem($roleId);
      }
      // Save without re-triggering hooks.
      $relationship->setSyncing(TRUE);
      $relationship->save();
    }

    // Matrix room invite.
    $roomId = $this->matrixClient->getRoomId((int) $group->id());
    if (!$roomId) {
      return;
    }

    try {
      $matrixUserId = $this->matrixClient->ensureUserExists((int) $drupalUser->id());
      $this->matrixClient->inviteUser($roomId, $matrixUserId);

      $this->logger->info('Invited @user to Matrix room @room (group @group).', [
        '@user' => $matrixUserId,
        '@room' => $roomId,
        '@group' => $group->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to invite user @uid to Matrix room @room: @error', [
        '@uid' => $drupalUser->id(),
        '@room' => $roomId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Membership revoked → kick user from Matrix room.
   */
  protected function onMembershipRevoked(GroupRelationshipInterface $relationship): void {
    if (!str_starts_with($relationship->getPluginId(), 'group_membership')) {
      return;
    }

    $group = $relationship->getGroup();
    $roomId = $this->matrixClient->getRoomId((int) $group->id());
    if (!$roomId) {
      return;
    }

    $drupalUser = $relationship->getEntity();
    if (!$drupalUser) {
      return;
    }

    try {
      $matrixUserId = $this->matrixClient->getMatrixUserId((int) $drupalUser->id());
      $this->matrixClient->kickUser($roomId, $matrixUserId);

      $this->logger->info('Kicked @user from Matrix room @room (group @group).', [
        '@user' => $matrixUserId,
        '@room' => $roomId,
        '@group' => $group->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to kick user @uid from Matrix room @room: @error', [
        '@uid' => $drupalUser->id(),
        '@room' => $roomId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Group deleted → send archive message to Matrix room.
   */
  protected function onGroupDeleted(GroupInterface $group): void {
    $roomId = $this->matrixClient->getRoomId((int) $group->id());
    if (!$roomId) {
      return;
    }

    try {
      $this->matrixClient->sendMessage(
        $roomId,
        $this->matrixClient->getBotUserId(),
        '⚠️ This group has been deleted in Drupal. This chat room is now archived.',
      );

      $this->logger->info('Tombstoned Matrix room @room for deleted group @group.', [
        '@room' => $roomId,
        '@group' => $group->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to tombstone Matrix room @room: @error', [
        '@room' => $roomId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
