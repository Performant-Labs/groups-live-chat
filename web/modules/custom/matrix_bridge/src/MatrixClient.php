<?php

declare(strict_types=1);

namespace Drupal\matrix_bridge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Guzzle wrapper for the Matrix Client-Server API.
 *
 * All calls use the appservice `as_token` with `?user_id=` masquerading.
 * No per-user token table is needed — Drupal impersonates any `@_drupal_*`
 * user via the application service namespace.
 */
class MatrixClient {

  /**
   * The homeserver base URL (e.g. "http://conduit:6167").
   */
  protected string $homeserverUrl;

  /**
   * The server name (e.g. "chat.ddev.site").
   */
  protected string $serverName;

  /**
   * The application service token for authenticating with the homeserver.
   */
  protected string $asToken;

  /**
   * The bot user localpart (e.g. "_drupal_bot").
   */
  protected string $botLocalpart;

  /**
   * Whether the bot user has been verified to exist this request.
   */
  protected bool $botRegistered = FALSE;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new MatrixClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $config = $configFactory->get('matrix_bridge.settings');
    $this->homeserverUrl = rtrim($config->get('homeserver_url') ?? '', '/');
    $this->serverName = $config->get('server_name') ?? '';
    $this->asToken = $config->get('as_token') ?? '';
    $this->botLocalpart = $config->get('bot_localpart') ?? '_drupal_bot';
    $this->logger = $loggerFactory->get('matrix_bridge');
  }

  /**
   * Creates a Matrix room.
   *
   * @param string $name
   *   Human-readable room name.
   * @param string $alias
   *   Local alias (without # prefix or :server suffix).
   *
   * @return string
   *   The Matrix room ID (e.g. "!abc123:chat.ddev.site").
   *
   * @throws \RuntimeException
   *   If the room creation fails.
   */
  public function createRoom(string $name, string $alias): string {
    $this->ensureBotExists();
    $response = $this->request('POST', '/_matrix/client/v3/createRoom', [
      'name' => $name,
      'room_alias_name' => $alias,
      'visibility' => 'private',
      'preset' => 'private_chat',
    ], $this->getBotUserId());
    return $response['room_id'];
  }

  /**
   * Invites a user to a Matrix room and auto-joins them.
   *
   * Since all @_drupal_* users are appservice-controlled, we can immediately
   * accept the invite on their behalf — no user interaction needed.
   *
   * @param string $roomId
   *   The Matrix room ID.
   * @param string $matrixUserId
   *   The fully-qualified Matrix user ID (e.g. "@_drupal_42:server").
   */
  public function inviteUser(string $roomId, string $matrixUserId): void {
    $this->ensureBotExists();
    $this->request('POST', "/_matrix/client/v3/rooms/{$this->encodeRoomId($roomId)}/invite", [
      'user_id' => $matrixUserId,
    ], $this->getBotUserId());

    // Auto-join: accept the invite on behalf of the appservice user.
    $this->joinRoom($roomId, $matrixUserId);
  }

  /**
   * Joins a user to a Matrix room (accepts an invite).
   *
   * @param string $roomId
   *   The Matrix room ID.
   * @param string $matrixUserId
   *   The fully-qualified Matrix user ID to join as.
   */
  public function joinRoom(string $roomId, string $matrixUserId): void {
    $this->request('POST', "/_matrix/client/v3/rooms/{$this->encodeRoomId($roomId)}/join", [], $matrixUserId);
  }

  /**
   * Kicks a user from a Matrix room.
   *
   * @param string $roomId
   *   The Matrix room ID.
   * @param string $matrixUserId
   *   The fully-qualified Matrix user ID.
   */
  public function kickUser(string $roomId, string $matrixUserId): void {
    $this->ensureBotExists();
    $this->request('POST', "/_matrix/client/v3/rooms/{$this->encodeRoomId($roomId)}/kick", [
      'user_id' => $matrixUserId,
    ], $this->getBotUserId());
  }

  /**
   * Bans a user from a Matrix room.
   *
   * @param string $roomId
   *   The Matrix room ID.
   * @param string $matrixUserId
   *   The fully-qualified Matrix user ID.
   */
  public function banUser(string $roomId, string $matrixUserId): void {
    $this->ensureBotExists();
    $this->request('POST', "/_matrix/client/v3/rooms/{$this->encodeRoomId($roomId)}/ban", [
      'user_id' => $matrixUserId,
    ], $this->getBotUserId());
  }

  /**
   * Sends a message to a Matrix room, masquerading as a Drupal user.
   *
   * @param string $roomId
   *   The Matrix room ID.
   * @param string $userId
   *   The fully-qualified Matrix user ID to masquerade as.
   * @param string $body
   *   The message body text.
   *
   * @return string
   *   The Matrix event ID of the sent message.
   */
  public function sendMessage(string $roomId, string $userId, string $body): string {
    $txnId = 'drupal_' . bin2hex(random_bytes(8));
    $response = $this->request(
      'PUT',
      "/_matrix/client/v3/rooms/{$this->encodeRoomId($roomId)}/send/m.room.message/{$txnId}",
      [
        'msgtype' => 'm.text',
        'body' => $body,
      ],
      $userId,
    );
    return $response['event_id'];
  }

  /**
   * Ensures a Drupal user has a corresponding Matrix user registered.
   *
   * Uses appservice registration (type: m.login.application_service) to create
   * a user in the exclusive @_drupal_* namespace. Safe to call repeatedly —
   * returns the Matrix user ID whether the user was just created or already
   * existed.
   *
   * @param int $drupalUid
   *   The Drupal user ID.
   *
   * @return string
   *   The fully-qualified Matrix user ID (e.g. "@_drupal_42:chat.ddev.site").
   */
  public function ensureUserExists(int $drupalUid): string {
    $localpart = "_drupal_{$drupalUid}";
    $matrixUserId = "@{$localpart}:{$this->serverName}";

    try {
      $this->request('POST', '/_matrix/client/v3/register', [
        'type' => 'm.login.application_service',
        'username' => $localpart,
      ]);
      $this->logger->info('Created Matrix user @localpart for Drupal uid @uid.', [
        '@localpart' => $localpart,
        '@uid' => $drupalUid,
      ]);
    }
    catch (\RuntimeException $e) {
      // M_USER_IN_USE is expected if user already exists — not an error.
      if (!str_contains($e->getMessage(), 'M_USER_IN_USE')) {
        throw $e;
      }
    }

    return $matrixUserId;
  }

  /**
   * Gets the Matrix user ID for a Drupal user.
   *
   * @param int $drupalUid
   *   The Drupal user ID.
   *
   * @return string
   *   The fully-qualified Matrix user ID.
   */
  public function getMatrixUserId(int $drupalUid): string {
    return "@_drupal_{$drupalUid}:{$this->serverName}";
  }

  /**
   * Gets the bot's fully-qualified Matrix user ID.
   *
   * @return string
   *   e.g. "@_drupal_bot:chat.ddev.site"
   */
  public function getBotUserId(): string {
    return "@{$this->botLocalpart}:{$this->serverName}";
  }

  /**
   * Ensures the appservice bot user is registered on the homeserver.
   *
   * Called automatically before room management operations. Safe to call
   * repeatedly — only registers on first call per request.
   */
  protected function ensureBotExists(): void {
    if ($this->botRegistered) {
      return;
    }
    try {
      $this->request('POST', '/_matrix/client/v3/register', [
        'type' => 'm.login.application_service',
        'username' => $this->botLocalpart,
      ]);
      $this->logger->info('Registered Matrix bot user @bot.', [
        '@bot' => $this->getBotUserId(),
      ]);
    }
    catch (\RuntimeException $e) {
      if (!str_contains($e->getMessage(), 'M_USER_IN_USE')) {
        throw $e;
      }
    }
    $this->botRegistered = TRUE;
  }

  /**
   * Makes an authenticated request to the Matrix homeserver.
   *
   * @param string $method
   *   HTTP method (GET, POST, PUT, DELETE).
   * @param string $path
   *   API path (e.g. "/_matrix/client/v3/createRoom").
   * @param array $body
   *   Request body (will be JSON-encoded for POST/PUT).
   * @param string|null $masqueradeAs
   *   Optional Matrix user ID to masquerade as via ?user_id= query param.
   *
   * @return array
   *   Decoded JSON response.
   *
   * @throws \RuntimeException
   *   On request failure or non-2xx status.
   */
  protected function request(string $method, string $path, array $body = [], ?string $masqueradeAs = NULL): array {
    $options = [
      'headers' => [
        'Authorization' => "Bearer {$this->asToken}",
        'Content-Type' => 'application/json',
      ],
    ];

    if (!empty($body)) {
      $options['json'] = $body;
    }

    // Appservice masquerading: add ?user_id= query param.
    if ($masqueradeAs !== NULL) {
      $options['query'] = ['user_id' => $masqueradeAs];
    }

    $url = $this->homeserverUrl . $path;

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $responseBody = (string) $response->getBody();
      return json_decode($responseBody, TRUE) ?? [];
    }
    catch (RequestException $e) {
      $errorBody = '';
      if ($e->hasResponse()) {
        $errorBody = (string) $e->getResponse()->getBody();
      }
      $this->logger->error('Matrix API error: @method @path — @error', [
        '@method' => $method,
        '@path' => $path,
        '@error' => $errorBody ?: $e->getMessage(),
      ]);
      throw new \RuntimeException("Matrix API error: {$errorBody}", (int) $e->getCode(), $e);
    }
  }

  /**
   * URL-encodes a Matrix room ID for use in API paths.
   *
   * Room IDs contain ! and : which must be percent-encoded.
   *
   * @param string $roomId
   *   The raw room ID.
   *
   * @return string
   *   The URL-encoded room ID.
   */
  protected function encodeRoomId(string $roomId): string {
    return rawurlencode($roomId);
  }

}
