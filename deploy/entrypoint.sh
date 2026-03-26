#!/bin/sh
set -e

# Ensure sites/default is writable for Drupal install
chmod 755 /var/www/html/web/sites/default 2>/dev/null || true

# If settings.php exists and has DB config, append production overrides
SETTINGS="/var/www/html/web/sites/default/settings.php"
if [ -f "$SETTINGS" ]; then
  # Only append if our marker isn't already there
  if ! grep -q 'matrix_bridge production overrides' "$SETTINGS" 2>/dev/null; then
    cat >> "$SETTINGS" <<'PHPEOF'

// --- matrix_bridge production overrides ---
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['127.0.0.1'];
$settings['trusted_host_patterns'] = [
  '^chat\.performantlabs\.com$',
  '^localhost$',
];
$settings['file_private_path'] = '/var/www/html/private';
$config['matrix_bridge.settings']['homeserver_url'] = 'http://conduit:6167';
$config['matrix_bridge.settings']['server_name'] = 'chat.performantlabs.com';
PHPEOF
    echo "Production settings appended to settings.php"
  fi
fi

# Start PHP-FPM in the background
php-fpm -D

# Start nginx in the foreground
nginx -g 'daemon off;'
