# skye-web — guía para sesiones de Claude Code

Web de la Isla de Skye operada por **ShyPig** (Portugal). Vende los tours de 2 y 3 días de **TA Scotland** (marcas Viajar por Escocia / Scozia Tour / Loch Ness Bus, motor de reservas Bókun) y hace cross-selling de coche, autocaravana, alojamiento, seguros, actividades y audioguía. Se plantea como "la oficina de turismo de Skye": directorio completo, rutas por perfil, bot planificador y audioguía multilingüe.

El contexto de negocio completo está en el proyecto "Skye Website" de claude.ai. Lo que este repo necesita saber está aquí y en `CONTRACTS.md`.

## Qué es este repo

WordPress + Bricks Builder para todo el **contenido**. Los servicios (bot, app de audioguía, widget de reserva) viven en otros repos y consumen la API que este repo expone.

```
.
├── docker/                 # entorno local (docker compose)
├── wp-content/
│   ├── plugins/skye-core/  # TODO el código propio de WordPress vive aquí
│   └── themes/skye-child/  # tema hijo de Bricks (solo estilos base y helpers)
├── seed/                   # datos piloto y comandos de carga
├── .github/workflows/      # despliegue a test
├── CONTRACTS.md            # modelo de datos y API: la fuente de verdad
└── CLAUDE.md
```

No se versiona el core de WordPress, ni plugins de terceros (Bricks, ACF Pro, WPML, Rank Math): se instalan en el entorno con licencias que viven en `.env`. Solo se versiona lo nuestro.

## Principios que no se negocian

1. **Una sola fuente de verdad para el contenido:** los tipos de contenido y sus campos están definidos en `CONTRACTS.md` y en los JSON de ACF (`wp-content/plugins/skye-core/acf-json/`). Cualquier cambio de modelo empieza en `CONTRACTS.md`, luego el JSON, luego el código.
2. **Se almacena en métrico y GBP; se muestra según el mercado.** Nada se escribe dos veces. La conversión la hace la capa de presentación (`Skye\Units`).
3. **Todo lo que se filtra o va al JSON-LD es un campo estructurado**, nunca prosa.
4. **Verificación visible:** toda ficha tiene "comprobado por / fecha / válido hasta" e historial de cambios. No se publica una ficha sin verificador.
5. **Plantillas, no páginas:** cada tipo de contenido tiene una plantilla de Bricks alimentada por campos. Nunca se maqueta una ficha a mano.
6. **Nada esencial solo en JavaScript:** el contenido debe existir en HTML servido para buscadores e IAs.
7. **Sin credenciales en el repo ni en el chat.** `.env` y secretos de GitHub.

## Convenciones de código

- PHP 8.2+, namespace `Skye\`, autoload PSR-4 desde `skye-core/src/`. Una clase por archivo. Prefijo `skye_` para hooks, opciones y meta.
- Tipos de contenido (post types): `place`, `route`, `tour`, `brand`, `lodging`, `motorhome_spot`, `experience`. Taxonomías: `skye_zone`, `skye_category`, `skye_profile`, `skye_suitability`.
- Campos ACF guardados como JSON en `acf-json/` (ACF los sincroniza). Nombres de campo en inglés, `snake_case`, con el prefijo del tipo cuando hay ambigüedad.
- API REST: namespace `skye/v1`. Respuestas en JSON con claves en `snake_case`, unidades métricas, moneda GBP, y un objeto `meta` con `lang`, `generated_at`, `verified`.
- Textos de interfaz en inglés como idioma base, traducibles con `__( '…', 'skye-core' )`.
- Commits en inglés, imperativo, con prefijo de área: `core:`, `api:`, `acf:`, `seed:`, `docker:`, `ci:`, `theme:`.

## Cómo arrancar en local

```bash
cp .env.example .env          # rellenar licencias si las hay; sin ellas el stack arranca igual
docker compose --env-file .env -f docker/docker-compose.yml up -d
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli bash /scripts/install.sh
# → http://localhost:8080  (admin: ver .env)
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli wp skye seed all      # 46 lugares, 3 marcas, 2 tours, 4 rutas y 13 áreas de autocaravana (borradores sin verificar)
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli wp skye seed all --lang=es   # traducciones (necesita WPML con ese idioma; igual con --lang=it)
curl http://localhost:8080/wp-json/skye/v1/places
```

## Cómo probar

- `php -l` sobre todo PHP y `composer run lint` (PHPCS, estándar WordPress) antes de cada PR.
- `composer run test` ejecuta las pruebas unitarias de `skye-core` (PHPUnit, sin WordPress) para unidades, validación de JSON y serialización de la API.
- Pruebas de integración contra el stack de Docker: `bash docker/scripts/smoke.sh`.

## Ciclo de trabajo

Issue → rama `feat/…` → PR con descripción → Sergio prueba en local → merge → GitHub Actions despliega a test → etiqueta `vX.Y.Z` → producción.

## Idiomas y mercados

Idioma ≠ mercado. Marca por idioma: ES → Viajar por Escocia; IT → Scozia Tour; cualquier otro → Loch Ness Bus (tour en inglés). Unidades por mercado en `CONTRACTS.md` §9.
