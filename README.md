# skye-web

Web de la Isla de Skye: directorio turístico, rutas, tours de 2 y 3 días desde Edimburgo, audioguía y planificador. WordPress + Bricks Builder; el código propio vive en `wp-content/plugins/skye-core`.

- Convenciones y arranque: [`CLAUDE.md`](CLAUDE.md)
- Modelo de datos y API: [`CONTRACTS.md`](CONTRACTS.md)

## Arranque rápido

```bash
cp .env.example .env
docker compose --env-file .env -f docker/docker-compose.yml up -d
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli bash /scripts/install.sh
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli wp skye seed places
open http://localhost:8080
curl http://localhost:8080/wp-json/skye/v1/places | jq .
```

## Repos relacionados

`skye-bot` · `skye-audioguide` · `skye-booking-widget` (consumen la API `skye/v1`).
