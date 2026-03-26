<?php

declare(strict_types=1);

namespace Drupal\matrix_bridge\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives Matrix Application Service transaction pushes from the homeserver.
 *
 * The homeserver sends HTTP PUT requests to this endpoint whenever events
 * occur in rooms that match the appservice's namespace. This allows Drupal
 * to react to Matrix-side events (e.g., messages sent via a Matrix client
 * rather than through the Drupal UI).
 */
class AppserviceController extends ControllerBase {

  /**
   * The logger.
   */
  protected LoggerInterface $matrixLogger;

  /**
   * {@inheritdoc}
   */
  public static function create($container) {
    $instance = new static();
    $instance->matrixLogger = $container->get('logger.factory')->get('matrix_bridge');
    return $instance;
  }

  /**
   * Handles an incoming transaction from the homeserver.
   *
   * @param string $txnId
   *   The transaction ID (provided by the homeserver).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Empty JSON object (required by the Matrix appservice spec).
   */
  public function handleTransaction(string $txnId, Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE) ?? [];
    $events = $body['events'] ?? [];

    $this->matrixLogger->info('Received transaction @txn with @count events.', [
      '@txn' => $txnId,
      '@count' => count($events),
    ]);

    foreach ($events as $event) {
      $type = $event['type'] ?? 'unknown';
      $sender = $event['sender'] ?? 'unknown';
      $this->matrixLogger->debug('Event: type=@type sender=@sender', [
        '@type' => $type,
        '@sender' => $sender,
      ]);
    }

    // Matrix spec requires an empty JSON object response.
    return new JsonResponse(new \stdClass());
  }

  /**
   * Access check: verifies the homeserver token (hs_token).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function access(Request $request): AccessResultInterface {
    $config = $this->config('matrix_bridge.settings');
    $expectedToken = $config->get('hs_token') ?? '';

    // Check Authorization header first, then query param.
    $providedToken = '';
    $authHeader = $request->headers->get('Authorization', '');
    if (str_starts_with($authHeader, 'Bearer ')) {
      $providedToken = substr($authHeader, 7);
    }
    elseif ($request->query->has('access_token')) {
      $providedToken = $request->query->get('access_token');
    }

    if (!empty($expectedToken) && hash_equals($expectedToken, $providedToken)) {
      return AccessResult::allowed()->addCacheableDependency($config);
    }

    $this->matrixLogger->warning('Appservice webhook rejected: invalid hs_token.');
    return AccessResult::forbidden('Invalid homeserver token.')
      ->addCacheableDependency($config);
  }

}
