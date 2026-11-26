#!/usr/bin/env bash
# Instala WordPress en el stack local y activa skye-core. Idempotente.
set -euo pipefail

cd /var/www/html

if ! wp core is-installed --quiet 2>/dev/null; then
  echo "→ Installing WordPress at ${WP_HOME}"
  wp core install \
    --url="${WP_HOME}" \
    --title="Skye Guide (local)" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
else
  echo "→ WordPress already installed"
fi

wp option update permalink_structure '/%postname%/' >/dev/null
wp option update timezone_string 'Europe/London' >/dev/null
wp option update blog_public 0 >/dev/null   # local/test: no indexar

# ACF Pro si hay licencia; si no, ACF gratuito (campos básicos) para poder desarrollar.
if [ -n "${ACF_PRO_KEY:-}" ]; then
  if ! wp plugin is-installed advanced-custom-fields-pro; then
    echo "→ Installing ACF Pro"
    wp plugin install "https://connect.advancedcustomfields.com/v2/plugins/download?p=pro&k=${ACF_PRO_KEY}" --activate
  fi
else
  wp plugin is-installed advanced-custom-fields || wp plugin install advanced-custom-fields --activate
fi

wp plugin is-active skye-core || wp plugin activate skye-core
wp plugin delete hello akismet 2>/dev/null || true

echo "→ Done. Admin: ${WP_HOME}/wp-admin  (${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD})"
