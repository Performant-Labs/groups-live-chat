<?php

declare(strict_types=1);

namespace Drupal\matrix_bridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\matrix_bridge\MatrixClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sync endpoint for real-time chat updates.
 *
 * Instead of proxying Matrix /sync (which has bugs in Conduit with
 * appservice-masqueraded users), this endpoint polls Drupal's own
 * ChatMessage entity table for new messages. The browser sends the last
 * seen message ID, and this returns any newer messages as JSON.
 *
 * This keeps Drupal as the single source of truth for messages while
 * providing near-real-time delivery to the browser.
 */
class SyncController extends ControllerBase {

  protected MatrixClient $matrixClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->matrixClient = $container->get('matrix_bridge.client');
    return $instance;
  }

  /**
   * Returns new messages since a given ID.
   *
   * The browser sends:
   *   GET /group/{id}/chat/sync?since={last_message_id}
   *
   * Returns JSON with new messages:
   *   { "messages": [...], "last_id": 42, "has_new": true }
   *
   * @param int $group_id
   *   The Drupal group ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request with optional 'since' query param (last seen message ID).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with messages and new last_id.
   */
  public function sync(int $group_id, Request $request): JsonResponse {
    $since = (int) $request->query->get('since', '0');
    $currentUserId = (int) $this->currentUser()->id();

    // Query ChatMessage entities newer than $since for this group.
    $storage = $this->entityTypeManager()->getStorage('chat_message');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('group_id', $group_id)
      ->sort('id', 'ASC');

    if ($since > 0) {
      $query->condition('id', $since, '>');
    }

    $ids = $query->execute();
    $messages = [];
    $lastId = $since;

    if (!empty($ids)) {
      $entities = $storage->loadMultiple($ids);
      foreach ($entities as $entity) {
        $author = $entity->get('uid')->entity;
        $authorId = $author ? (int) $author->id() : 0;

        $messages[] = [
          'author' => $author ? $author->getDisplayName() : 'Unknown',
          'body' => $entity->getBody(),
          'time' => date('H:i', (int) $entity->get('created')->value),
          'is_own' => ($authorId === $currentUserId),
        ];

        $lastId = max($lastId, (int) $entity->id());
      }
    }

    return new JsonResponse([
      'messages' => $messages,
      'last_id' => $lastId,
      'has_new' => !empty($messages),
    ]);
  }

}
