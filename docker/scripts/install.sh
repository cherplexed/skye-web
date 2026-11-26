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
    # --quiet: WP-CLI would otherwise print the download URL, which contains the licence key.
    wp plugin install "https://connect.advancedcustomfields.com/v2/plugins/download?p=pro&k=${ACF_PRO_KEY}" --activate --quiet
  fi
  wp plugin is-active advanced-custom-fields-pro || wp plugin activate advanced-custom-fields-pro
  # Pro sustituye al gratuito: con los dos activos los campos salen duplicados.
  if wp plugin is-installed advanced-custom-fields; then
    wp plugin deactivate advanced-custom-fields 2>/dev/null || true
    wp plugin delete advanced-custom-fields
  fi
else
  wp plugin is-installed advanced-custom-fields || wp plugin install advanced-custom-fields --activate
fi

# Bricks y WPML no se pueden descargar con una clave: se instalan desde los zips que haya en
# docker/licensed/ (ver docker/licensed/README.md). Sin zips, se omiten y el stack funciona igual.
ZIPS=/vendor-zips
first_zip() { { ls "$ZIPS"/$1 2>/dev/null || true; } | sort | tail -n 1; }

bricks_zip="$(first_zip 'bricks*.zip')"
if wp theme is-installed bricks; then
  echo "→ Bricks already installed"
elif [ -n "$bricks_zip" ]; then
  echo "→ Installing Bricks from $(basename "$bricks_zip")"
  wp theme install "$bricks_zip"
else
  echo "→ Bricks: no zip in docker/licensed/, skipped"
fi
if wp theme is-installed bricks && ! wp theme is-active skye-child; then
  wp theme activate skye-child
fi

# El orden importa: primero el núcleo de WPML, luego sus complementos.
for spec in 'sitepress-multilingual-cms:sitepress-multilingual-cms*.zip' 'wpml-string-translation:wpml-string-translation*.zip' 'acfml:acfml*.zip'; do
  slug="${spec%%:*}"; pattern="${spec#*:}"
  zip="$(first_zip "$pattern")"
  if wp plugin is-installed "$slug"; then
    wp plugin is-active "$slug" || wp plugin activate "$slug"
  elif [ -n "$zip" ]; then
    echo "→ Installing $slug from $(basename "$zip")"
    wp plugin install "$zip" --activate
  else
    echo "→ $slug: no zip in docker/licensed/, skipped"
  fi
done

wp plugin is-active skye-core || wp plugin activate skye-core
wp plugin delete hello akismet 2>/dev/null || true

echo "→ Done. Admin: ${WP_HOME}/wp-admin  (${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD})"
