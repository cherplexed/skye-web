# Zips con licencia

Bricks y WPML no se pueden descargar con una clave, así que `install.sh` los instala desde los zips que
encuentre en esta carpeta. Los zips **no se versionan** (están en `.gitignore`). Sin ellos, el stack
arranca igual, sin Bricks ni WPML.

| Archivo (el nombre puede llevar versión) | De dónde se descarga | Qué es |
|---|---|---|
| `bricks*.zip` | Cuenta de Bricks → Downloads | Tema Bricks |
| `sitepress-multilingual-cms*.zip` | Cuenta de WPML → Downloads → "WPML Multilingual CMS" | Núcleo de WPML |
| `wpml-string-translation*.zip` | Cuenta de WPML → Downloads → "String Translation" | Traducción de cadenas |
| `acfml*.zip` | Cuenta de WPML → Downloads → "Advanced Custom Fields Multilingual" | Puente WPML ↔ ACF |

ACF Pro no necesita zip: se descarga solo con `ACF_PRO_KEY` del `.env`.

Después de copiar los zips:

```bash
docker compose --env-file .env -f docker/docker-compose.yml up -d
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli bash /scripts/install.sh
```

- **WPML** se registra solo con `WPML_SITE_KEY` del `.env` (la clave de sitio de `http://localhost:8080`).
- **Bricks** se activa a mano una vez: wp-admin → Bricks → License, y pegar la clave.
