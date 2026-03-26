#!/bin/sh
set -e

SETTINGS="/var/www/html/web/sites/default/settings.php"

# Ensure sites/default is writable
chmod 755 /var/www/html/web/sites/default 2>/dev/null || true

# Always generate settings.php from environment variables.
# This is needed because COPY web/ in the Dockerfile replaces sites/default/
# on every rebuild, wiping any previously-installed settings.php.
cat > "$SETTINGS" <<PHPEOF
<?php
\$databases['default']['default'] = [
  'database' => '${MYSQL_DATABASE}',
  'username' => '${MYSQL_USER}',
  'password' => '${MYSQL_PASSWORD}',
  'host' => '${MYSQL_HOST}',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];
\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['trusted_host_patterns'] = [
  '^chat\\.performantlabs\\.com$',
  '^localhost$',
];
\$settings['file_private_path'] = '/var/www/html/private';
\$settings['config_sync_directory'] = '/var/www/html/config/sync';
\$settings['reverse_proxy'] = TRUE;
\$settings['reverse_proxy_addresses'] = ['127.0.0.1'];
\$config['matrix_bridge.settings']['homeserver_url'] = 'http://conduit:6167';
\$config['matrix_bridge.settings']['server_name'] = 'chat.performantlabs.com';
PHPEOF

echo "Generated settings.php from environment variables"

# Start PHP-FPM in the background
php-fpm -D

# Start nginx in the foreground
nginx -g 'daemon off;'
