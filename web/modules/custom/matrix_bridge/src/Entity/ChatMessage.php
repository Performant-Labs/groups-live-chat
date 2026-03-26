<?php

declare(strict_types=1);

namespace Drupal\matrix_bridge\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the Chat Message entity.
 *
 * Lightweight content entity storing messages sent through the Matrix bridge.
 * Each message is linked to a Drupal user (uid), a group (group_id), and the
 * corresponding Matrix event (matrix_event_id) for cross-referencing.
 *
 * @ContentEntityType(
 *   id = "chat_message",
 *   label = @Translation("Chat Message"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "chat_message",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "uid",
 *   },
 * )
 */
class ChatMessage extends ContentEntityBase implements ContentEntityInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The Drupal user who sent the message.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['group_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Group ID'))
      ->setDescription(t('The Drupal group ID this message belongs to.'))
      ->setRequired(TRUE);

    $fields['matrix_event_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Matrix Event ID'))
      ->setDescription(t('The Matrix event ID (e.g. "$abc123:server").'))
      ->setSetting('max_length', 255);

    $fields['matrix_room_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Matrix Room ID'))
      ->setDescription(t('The Matrix room ID (e.g. "!xyz:server").'))
      ->setSetting('max_length', 255);

    $fields['body'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Message Body'))
      ->setDescription(t('The message content.'))
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the message was sent.'));

    return $fields;
  }

  /**
   * Gets the message body.
   */
  public function getBody(): string {
    return $this->get('body')->value ?? '';
  }

  /**
   * Gets the Matrix event ID.
   */
  public function getMatrixEventId(): ?string {
    return $this->get('matrix_event_id')->value;
  }

  /**
   * Gets the Matrix room ID.
   */
  public function getMatrixRoomId(): ?string {
    return $this->get('matrix_room_id')->value;
  }

  /**
   * Gets the group ID.
   */
  public function getGroupId(): int {
    return (int) $this->get('group_id')->value;
  }

  /**
   * Gets the author user ID.
   */
  public function getAuthorId(): int {
    return (int) $this->get('uid')->target_id;
  }

}
