<?php

/**
 * Production settings for chat.performantlabs.com.
 *
 * Copy this to web/sites/default/settings.php inside the container,
 * or mount it as a volume in docker-compose.yml.
 */

// Database connection from environment variables.
$databases['default']['default'] = [
  'database' => getenv('MYSQL_DATABASE') ?: 'groups_chat',
  'username' => getenv('MYSQL_USER') ?: 'drupal',
  'password' => getenv('MYSQL_PASSWORD'),
  'host' => getenv('MYSQL_HOST') ?: 'db',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// Hash salt from environment.
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'CHANGE_ME';

// Trusted host patterns.
$settings['trusted_host_patterns'] = [
  '^chat\.performantlabs\.com$',
  '^localhost$',
];

// File paths.
$settings['file_private_path'] = '/var/www/html/private';

// Config sync directory.
$settings['config_sync_directory'] = '/var/www/html/config/sync';

// Reverse proxy settings (behind host nginx + Cloudflare).
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['127.0.0.1', '172.0.0.0/8'];

// Override matrix_bridge settings for production.
$config['matrix_bridge.settings']['homeserver_url'] = 'http://conduit:6167';
$config['matrix_bridge.settings']['server_name'] = 'chat.performantlabs.com';
