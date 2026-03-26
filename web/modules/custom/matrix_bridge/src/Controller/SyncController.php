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
 * seen message ID and a timestamp, and this returns any newer or mutated
 * messages as JSON.
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
   * Returns new and mutated messages since a given ID/timestamp.
   *
   * The browser sends:
   *   GET /group/{id}/chat/sync?since={last_message_id}&since_ts={unix_ts}
   *
   * Returns JSON with messages and their mutation type:
   *   {
   *     "messages": [{"id": 15, "type": "new|edited|deleted", ...}],
   *     "last_id": 42,
   *     "sync_ts": 1711468800,
   *     "has_new": true
   *   }
   *
   * @param int $group_id
   *   The Drupal group ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request with 'since' (last ID) and 'since_ts' (timestamp) params.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with messages and new last_id.
   */
  public function sync(int $group_id, Request $request): JsonResponse {
    $since = (int) $request->query->get('since', '0');
    $sinceTs = (int) $request->query->get('since_ts', '0');
    $currentUserId = (int) $this->currentUser()->id();
    $now = \Drupal::time()->getRequestTime();

    $storage = $this->entityTypeManager()->getStorage('chat_message');
    $messages = [];
    $lastId = $since;

    // 1. New messages (id > since).
    $newQuery = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('group_id', $group_id)
      ->condition('id', $since, '>')
      ->sort('id', 'ASC');
    $newIds = $newQuery->execute();

    // 2. Mutated messages (changed > since_ts AND id <= since).
    $mutatedIds = [];
    if ($sinceTs > 0 && $since > 0) {
      $mutQuery = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('group_id', $group_id)
        ->condition('id', $since, '<=')
        ->condition('changed', $sinceTs, '>')
        ->sort('id', 'ASC');
      $mutatedIds = $mutQuery->execute();
    }

    // Merge and load.
    $allIds = array_unique(array_merge(array_values($newIds), array_values($mutatedIds)));
    if (!empty($allIds)) {
      $entities = $storage->loadMultiple($allIds);
      foreach ($entities as $entity) {
        $entityId = (int) $entity->id();
        $author = $entity->get('uid')->entity;
        $authorId = $author ? (int) $author->id() : 0;
        $isNew = isset($newIds[$entityId]) || in_array($entityId, array_values($newIds));
        $isDeleted = $entity->isDeleted();

        // Determine type.
        if ($isDeleted) {
          $type = 'deleted';
        }
        elseif ($isNew) {
          $type = 'new';
        }
        else {
          $type = 'edited';
        }

        $messages[] = [
          'id' => $entityId,
          'type' => $type,
          'author' => $author ? $author->getDisplayName() : 'Unknown',
          'body' => $isDeleted ? '' : $entity->getBody(),
          'time' => date('H:i', (int) $entity->get('created')->value),
          'is_own' => ($authorId === $currentUserId),
          'edited' => ($type === 'edited'),
        ];

        $lastId = max($lastId, $entityId);
      }
    }

    return new JsonResponse([
      'messages' => $messages,
      'last_id' => $lastId,
      'sync_ts' => $now,
      'has_new' => !empty($messages),
    ]);
  }

}
