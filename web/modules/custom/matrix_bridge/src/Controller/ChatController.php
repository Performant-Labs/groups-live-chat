<?php

declare(strict_types=1);

namespace Drupal\matrix_bridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\matrix_bridge\Entity\ChatMessage;
use Drupal\matrix_bridge\MatrixClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the HTMX chat panel for a group and handles message posting.
 */
class ChatController extends ControllerBase {

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
   * Renders the full chat panel for a group.
   *
   * Returns a render array with the chat UI, message list, and input form.
   * The message list uses hx-get for polling/refresh.
   *
   * @param int $group_id
   *   The Drupal group ID.
   *
   * @return array
   *   Render array.
   */
  public function panel(int $group_id): array {
    $roomId = $this->matrixClient->getRoomId($group_id);

    return [
      '#theme' => 'matrix_chat_panel',
      '#group_id' => $group_id,
      '#room_id' => $roomId,
      '#current_user' => $this->currentUser()->getDisplayName(),
      '#attached' => [
        'library' => [
          'matrix_bridge/chat',
          'core/drupal.htmx',
        ],
      ],
    ];
  }

  /**
   * Returns the message list fragment (HTMX partial).
   *
   * Called via hx-get to refresh the message list without a full page reload.
   *
   * @param int $group_id
   *   The Drupal group ID.
   *
   * @return array
   *   Render array for just the messages.
   */
  public function messages(int $group_id): array {
    $messages = $this->entityTypeManager()
      ->getStorage('chat_message')
      ->loadByProperties(['group_id' => $group_id]);

    // Sort by created timestamp.
    uasort($messages, fn($a, $b) => $a->get('created')->value <=> $b->get('created')->value);

    $items = [];
    foreach ($messages as $message) {
      $author = $message->get('uid')->entity;
      $items[] = [
        'author' => $author ? $author->getDisplayName() : 'Unknown',
        'body' => $message->getBody(),
        'time' => date('H:i', (int) $message->get('created')->value),
        'is_own' => $author && $author->id() == $this->currentUser()->id(),
      ];
    }

    return [
      '#theme' => 'matrix_chat_messages',
      '#messages' => $items,
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Handles message submission via HTMX POST.
   *
   * Saves the message to Drupal, sends it to Matrix, then returns the
   * updated message list fragment.
   *
   * @param int $group_id
   *   The Drupal group ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request containing the message body.
   *
   * @return array
   *   Render array for the updated message list.
   */
  public function send(int $group_id, Request $request): array {
    $body = trim($request->request->get('message', ''));
    if (empty($body)) {
      return $this->messages($group_id);
    }

    $roomId = $this->matrixClient->getRoomId($group_id);
    $currentUser = $this->currentUser();
    $eventId = '';

    if ($roomId) {
      try {
        $matrixUserId = $this->matrixClient->ensureUserExists((int) $currentUser->id());
        // Make sure user is in the room.
        try {
          $this->matrixClient->joinRoom($roomId, $matrixUserId);
        }
        catch (\Exception $e) {
          // Already joined — ignore.
        }
        $eventId = $this->matrixClient->sendMessage($roomId, $matrixUserId, $body);
      }
      catch (\Exception $e) {
        $this->getLogger('matrix_bridge')->error('Failed to send to Matrix: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Save to Drupal.
    $message = ChatMessage::create([
      'uid' => $currentUser->id(),
      'group_id' => $group_id,
      'matrix_event_id' => $eventId,
      'matrix_room_id' => $roomId ?? '',
      'body' => $body,
    ]);
    $message->save();

    return $this->messages($group_id);
  }

}
