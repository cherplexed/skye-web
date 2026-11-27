# CONTRACTS — modelo de datos y API de skye-web

Versión 1.8 (27 nov 2026), derivada del modelo de datos v0.5 del proyecto. Es la fuente de verdad para `skye-core`, `skye-bot`, `skye-audioguide` y `skye-booking-widget`. Un cambio de modelo se hace aquí primero, luego en `acf-json/`, luego en el código.

## 0. Reglas transversales

- **Almacenamiento:** métrico (km, m, minutos, litros, °C) y GBP. La API devuelve siempre estas unidades; convierte quien muestra (§9).
- **Traducible / no traducible** marcado por campo. Lo no traducible se escribe una vez (WPML: "copiar").
- **Relaciones en una dirección:** Ruta → Lugares; Tour → Lugares; Tour → Marca; Lugar → Tours.
- **Verificación (§10)** en todos los tipos.
- Identificadores: `id` (entero de WordPress), `slug` por idioma, `uuid` estable (meta `skye_uuid`, generado al crear) para que bot y app no dependan de IDs de WordPress.

## 1. Lugar — post type `place`

| Campo (ACF) | Tipo | Trad. | Notas |
|---|---|---|---|
| `title` (post_title) | texto | sí | |
| `gaelic_name` | texto | no | opcional |
| `summary` | texto ≤ 160 | sí | primera frase = la respuesta; meta description |
| `description` (post_content) | HTML | sí | |
| `skye_category` | taxonomía multi | términos sí | viewpoint · hike · castle · distillery · beach · waterfall · village · wildlife · museum · restaurant · cafe · shop · fuel |
| `skye_zone` | taxonomía | términos sí | trotternish · portree · duirinish-waternish · minginish-cuillin · sleat · broadford · raasay · en-route |
| `skye_profile` | taxonomía multi | términos sí | instagram · nature · hiking · history · whisky · wildlife · families · rainy-day |
| `location` | lat, lng | no | |
| `parking_location` | lat, lng | no | si difiere |
| `directions` | texto | sí | |
| `duration_min` / `duration_typical` | minutos | no | |
| `difficulty` | enum | no | none · easy · moderate · hard · alpine |
| `distance_km` / `elevation_gain_m` | número | no | rutas a pie |
| `price_type` | enum | no | free · ticket · paid_parking |
| `price_gbp` | número | no | |
| `opening_hours` | repetidor: season, text | text sí | o "open access" |
| `best_time` | texto corto | sí | |
| `suitable_for` | checkboxes | no | children · stroller · dogs · reduced_mobility |
| `motorhome_parking` | enum | no | yes · yes_length_limit · no |
| `overnight_allowed` | bool | no | |
| `services_nearby` | checkboxes | no | toilets · cafe · restaurant · shop · fuel |
| `gallery` | imágenes | alt sí | |
| `tips` | repetidor: text | sí | |
| `faq` | repetidor: `question`, `answer` | sí | Preguntas propias de ese lugar. La ficha añade delante las que se responden solas con los datos (tiempo, precio, caminata, sin coche). Se muestran plegadas y van al JSON-LD como `FAQPage` |
| `sources` | repetidor: `title`, `url` | no | De dónde salen los datos de la ficha; se muestran al pie |
| `audioguide_enabled` | bool | no | |
| `audioguide_stop_number` | entero | no | |
| `audioguide_tracks` | repetidor: lang, audio_url, transcript | audio y transcript sí | lo que lee la app |
| `audioguide_radius_m` | entero | no | 150 por defecto |
| `nearby_places` | relación multi (place) | no | opcional; si vacío → por distancia |
| `nearby_lodging` | relación multi (lodging) | no | opcional; "dormir cerca" se calcula |
| `experiences_here` | relación multi (experience) | no | |
| `in_tours` | relación multi (tour) | no | bloque "sin coche" |
| verificación | §10 | no | |

## 2. Ruta — post type `route`

`title`, `summary`, `description` (sí). `route_type`: itinerary · drive · walk. `skye_profile` multi. `days_count`. `vehicle`: no_car · car · motorhome · any. `days` repetidor → `stops` repetidor: `place` (relación), `time_hint`, `note` (sí), `stop_type`: visit · meal · night · en_route. `night_zone` + `night_lodging` por día. `total_km`, `total_drive_minutes`. `gpx_file`. `equivalent_tour` (relación tour). `motorhome_ok` bool + `motorhome_notes` (sí). Verificación.

## 3. Marca operadora — post type `brand`

| Marca | `domain` | `markets` | `tour_language` |
|---|---|---|---|
| Viajar por Escocia | viajarporescocia.com | es | es |
| Scozia Tour | scoziatour.com | it | it |
| Loch Ness Bus | lochnessbus.com | `*` (todos los demás) | en |

Campos: `title`, `domain`, `markets` (texto: códigos separados por comas; `*` = comodín), `tour_language`, `bokun_vendor_id`, `bokun_channel_uuid` (UUID del canal de reserva de Bókun de ShyPig que vende los productos de esa marca en esta web: un canal por idioma; es público, va en el código del widget), `attribution_param`, `ga4_property`, `logo`, `rating`, `review_count`, `operator_legal_name` (TA Scotland Limited).

Regla de enrutado: `brand_for_market(market)` devuelve la marca cuyo `markets` contiene el código; si ninguna, la del comodín.

## 4. Tour — post type `tour`

`title`, `summary`, `description`, `includes`, `excludes` (sí). `product_code` (SK2D, SK3D). `days_count`, `nights_count`. `departure_city`: edinburgh · glasgow. `departure_time`, `meeting_point` (sí). `days` repetidor (sí) → `title` (sí), `summary` (sí), `stops` repetidor (sí): cada parada del día, en orden, con `place` (relación, opcional: solo si el lugar tiene ficha), `title` (sí; nombra la parada sin ficha o matiza la que la tiene), `stop_type` (visit · photo · break · free_time · overnight), `duration_min` (minutos en la parada) y `note` (sí). Las duraciones salen de la ficha técnica del operador; cuando esta da variantes según el vehículo, se guarda la de más recorrido o más tiempo. La página las muestra con el aviso de que son estimadas. `products` repetidor: `brand` (relación), `bokun_product_id`, `fallback_booking_url`. **Sin precio almacenado**: `price_from` y disponibilidad llegan de Bókun (caché 15–60 min). `accommodation_intro` (sí), `accommodation_options` repetidor (sí) → `name` (sí), `description` (sí): las formas de alojarse que se pueden elegir al reservar, sin precio (el precio de cada opción lo da Bókun). `accommodation_notes` (sí). `gallery` (fotos de cabecera además de la destacada; cada parada del itinerario muestra la foto de portada de su lugar, sin subir nada al tour). `rating`, `review_count`. `includes_audioguide` bool. Verificación.

## 5. Alojamiento — post type `lodging`

`title`, `summary` (sí). `lodging_type`: hotel · bnb · cabin · hostel · campsite. `skye_zone`. `location`. `price_from_gbp`. `amenities`: breakfast · parking · dogs · family · kitchen · free_cancellation. `good_for` (sí). `booking_url`, `provider`: booking · direct. Calculado: minutos a los 3 Lugares más cercanos. Verificación.

## 6. Área de autocaravana — post type `motorhome_spot`

`title`. `spot_type`: campsite · aire · parking · dump_station · ev_charger. `location`. `services`: electricity · water · dump · showers · wifi; `ev_kw`. `overnight_allowed`. `max_length_m`. `price_gbp`. `booking_url` o `no_booking` (bool). `season` (sí). `notes` (sí). `sources` repetidor → `title`, `url`. Verificación.

`price_gbp` es el precio desde por noche (por uso en `dump_station`). Sin `location`, la página enlaza a una búsqueda del nombre en el mapa. Las áreas no tienen página propia: se muestran todas en el archivo del tipo (`/motorhome/`, shortcode `[skye_motorhome]`), agrupadas en campings, paradas nocturnas de pago y puntos de servicio, junto con los consejos de conducción y pernocta y las rutas con `motorhome_ok`. Datos piloto en `seed/spots.json` (`wp skye seed spots`).

## 7. Experiencia — post type `experience`

`title`, `summary`, `description` (sí). `experience_type`: tasting · cruise · wildlife · kayak · fishing · mountain_guide · private_tour · ticket. `departure_place` (relación). `duration_min`. `price_from_gbp`. `languages` (códigos de idioma). `season` (sí). `suitable_for`. `provider`, `booking_url`. `commission_pct` (interno, no público). Verificación.

## 8. Taxonomías

`skye_zone`, `skye_category`, `skye_profile`, `skye_suitability`. Traducibles, con slug fijo por idioma.

**Directorio de lugares.** `/places/` lista todos los lugares agrupados por zona; cada zona tiene su página en `/zone/{slug}/`, con la descripción del término como entradilla. La zona va siempre en la ruta; `category` y `profile` son parámetros (`/zone/trotternish/?category=hike`), con los mismos nombres y slugs que en la API. Las vistas con parámetros llevan `noindex, follow`: solo el directorio y las páginas de zona son canónicas. Un filtro solo ofrece opciones que dan al menos un resultado. Ambas páginas publican un `ItemList` en JSON-LD.

## 9. Unidades, moneda y mercados

| Mercado | Distancia | Altitud | Combustible | Temp. | Moneda | Marca |
|---|---|---|---|---|---|---|
| en-gb | mi | ft | l, mpg (UK) | °C | GBP | Loch Ness Bus |
| en-us | mi | ft | gal US, mpg (US) | °F | USD | Loch Ness Bus |
| es | km | m | l, l/100 km | °C | EUR | Viajar por Escocia |
| it | km | m | l, l/100 km | °C | EUR | Scozia Tour |
| de, fr, pt, nl | km | m | l, l/100 km | °C | EUR | Loch Ness Bus |
| zh, ja | km | m | l | °C | CNY / JPY | Loch Ness Bus |

Distancias en coche siempre en ambos sistemas ("72 km / 45 mi"). Galón US 3,785 l; imperial 4,546 l. Precios base GBP; conversión orientativa con fecha.

## 10. Verificación y registro de cambios

Meta en todos los tipos: `skye_verified_by` (user ID), `skye_verified_at` (fecha), `skye_valid_until` (fecha; por defecto +12 meses), `skye_change_log` (lista: fecha, user ID, campos cambiados). Al vencer `valid_until`, la ficha se marca "pendiente de revisión" en el panel. En público: "Comprobado por [nombre] el [fecha]" y "Actualizado el [fecha]" si hubo cambios posteriores. JSON-LD: `dateModified`, `reviewedBy`.

## 11. API REST — namespace `skye/v1`

Lectura pública. Escritura solo con autenticación de WordPress (no en v1).

Parámetros comunes: `lang` (código WPML; por defecto el de la petición), `market` (ver §9; por defecto derivado de `lang`), `page`, `per_page` (≤ 100), `fields` (lista para reducir respuesta).

| Endpoint | Filtros | Devuelve |
|---|---|---|
| `GET /places` | `zone`, `category`, `profile`, `suitable_for`, `audioguide=1`, `near=lat,lng&radius_km=` | lista de Lugar |
| `GET /places/{id|uuid|slug}` | | Lugar completo + `nearby` calculado |
| `GET /routes`, `GET /routes/{id}` | `type`, `profile`, `vehicle`, `days` | Ruta; en detalle, `days[].stops[]` con `place` expandido (id, uuid, slug, título, coordenadas) y `display` (distancia en ambos sistemas, tiempo) |
| `GET /tours`, `GET /tours/{id}` | `days`, `departure` | Tour con `offer` resuelto para `market` (ver abajo); nunca la lista cruda de productos |
| `GET /brands`, `GET /brands/{id}` | | Marca |
| `GET /lodging`, `GET /motorhome-spots`, `GET /experiences` (y `/{id}`) | `type`, `zone`, `near=lat,lng&radius_km=`; además `overnight=1` en áreas y `suitable_for` en experiencias | listas; con `near`, ordenadas por `distance_km` |
| `GET /audioguide/stops` | `lang` | Lugares con `audioguide_enabled`, ordenados por `audioguide_stop_number`, con pista del idioma pedido y radio GPS |

`{id}` admite ID numérico, `uuid` o slug en todos los tipos. Las listas omiten repetidores, relaciones y galerías; el detalle lo trae todo más `description_html`. Los valores de filtro admiten varios separados por comas. Los campos marcados `internal` en el esquema (p. ej. `commission_pct`) no salen nunca.

`offer` de un Tour:

```json
{
  "market": "de",
  "brand": { "id": 31, "slug": "loch-ness-bus", "title": "Loch Ness Bus", "domain": "lochnessbus.com", "tour_language": "en" },
  "bokun_product_id": "60870",
  "price_from": 399.2, "currency": "EUR", "next_available": "2026-11-30", "price_stale": false,
  "booking_url": "https://lochnessbus.com/",
  "booking_widget": { "channel_uuid": "bf60449b-9be3-4c80-b568-7d869491a1f4", "product_id": "60870" }
}
```

La marca sale de §3; la moneda pedida a Bókun, de §9. `price_from`, `currency` y `next_available` son `null` si la marca no tiene producto, Bókun no está configurado o no hay salidas. `booking_url` es el `fallback_booking_url` del producto o, si falta, la portada del dominio de la marca, con el `attribution_param` de la marca añadido. `booking_widget` es `null` salvo que la marca tenga `bokun_channel_uuid` válido y producto: entonces la página del tour incrusta el calendario de Bókun (`https://widgets.bokun.io/online-sales/{channel_uuid}/experience-calendar/{product_id}`) en el bloque `[skye_tour part="booking"]`, la reserva y el pago se hacen en esta web y `booking_url` queda solo como alternativa sin JavaScript. Será sustituido por `skye-booking-widget` (issue #33) sin cambiar la plantilla.

Forma de respuesta:

```json
{
  "data": [ { "id": 12, "uuid": "…", "slug": "old-man-of-storr", "title": "Old Man of Storr", "…": "…" } ],
  "meta": { "lang": "en", "market": "en-gb", "total": 1, "page": 1, "generated_at": "2026-11-26T19:00:00Z" }
}
```

Cada objeto incluye `verification: { verified_by, verified_at, valid_until, updated_at }`.

## 12. Bókun

`skye-core` guarda credenciales en `.env` (`BOKUN_ACCESS_KEY`, `BOKUN_SECRET_KEY`, `BOKUN_VENDOR_ID`, y `BOKUN_BASE_URL` opcional: vacío = producción `https://api.bokun.io`; pruebas `https://api.bokuntest.com`). La creación de reservas y el pago los hace `skye-booking-widget` (ShyPig como revendedora), no este repo: aquí solo se lee.

- **Firma** (`Skye\Bokun\Signer`): cabeceras `X-Bokun-Date` (UTC, `yyyy-MM-dd HH:mm:ss`), `X-Bokun-AccessKey` y `X-Bokun-Signature` = base64(HMAC-SHA1(secreto, fecha + clave de acceso + MÉTODO + ruta con query)).
- **Lectura** (`Skye\Bokun\Client`): `GET /activity.json/{id}/availabilities?start=&end=&currency=`.
- **Precio "desde"** (`Skye\Bokun\Pricing`): entre las salidas reservables (ni `soldOut` ni `unavailable`, con plazas), el menor precio por persona de la tarifa por defecto (`defaultRateId`). Categoría de precio: la de adulto si se indica su ID; si no, la más cara de la tarifa, para no mostrar nunca un precio de niño.
- **Caché** (`Skye\Bokun\Offers`): 30 min por producto y moneda. Si Bókun falla se sirve la última respuesta buena (hasta 7 días) marcada `stale: true`, y no se reintenta en 5 min (o lo que diga `Retry-After` en un 429; el límite de Bókun es 400 peticiones/min por vendedor). Se consulta en ventanas de 60 días, hasta 6, y se para en la primera con salidas.
- **Forma del resultado:** `{ price_from, currency, next_available (Y-m-d), departures, fetched_at, stale }` o `null` si no hay nada a la venta o Bókun no está configurado.
- Comprobación manual: `wp skye bokun <product_id> [--currency=EUR] [--category=<id>]`.

## 13. JSON-LD

| Tipo | schema.org |
|---|---|
| place | `TouristAttraction` (+ `geo`, `openingHoursSpecification`, `isAccessibleForFree`) |
| route, tour | `TouristTrip` (`itinerary` = `ItemList` con las paradas que tienen ficha, como `TouristAttraction`); el tour añade `offers` (`Offer` con el precio desde de Bókun en la moneda del mercado, y la marca como `provider` y `seller`). Sin precio de Bókun no se publica `offers` |
| brand | `Organization` (`parentOrganization` TA Scotland Limited) |
| lodging | `LodgingBusiness` |
| experience | `Product` |
| motorhome_spot | `Place` + `amenityFeature` |

Todos con `dateModified` y `reviewedBy` (§10).

## Idiomas (WPML)

- La configuración vive en `skye-core/wpml-config.xml` (tipos y taxonomías traducibles, meta propia) y en `wpml_cf_preferences` de cada campo ACF, generado desde `Fields.php`. No se configura nada a mano en el panel.
- Idioma por directorio: `/` inglés (por defecto), `/es/` español, `/it/` italiano. Un idioma se puede cargar por etapas: lo que aún no tiene archivo en `seed/{idioma}/` sigue mostrando el original.
- Los tipos públicos están en modo "traducible, con el original como respaldo": mientras una ficha no tenga traducción, en `/es/` se ve la inglesa. Las marcas no se traducen.
- `skye_uuid` y la verificación se copian a las traducciones: una ficha tiene una sola identidad y una sola comprobación.
- **Slugs canónicos.** Los slugs de términos (zona, categoría, perfil) en la API, en los parámetros de URL y en los datos de carga son siempre los del idioma por defecto, se sirva el idioma que se sirva. Una traducción de una ficha conserva el slug de su original (`/es/places/old-man-of-storr/`). Toda la relación con WPML pasa por `Skye\Lang`.
- **Fotos.** Se suben una vez, a la ficha del idioma por defecto; las traducciones muestran las de su original.
- **Contenido traducido.** `seed/{idioma}/places.json`, `tours.json`, `routes.json` y `terms.json` contienen solo los textos, por slug; se superponen al original al cargar (`wp skye seed all --lang=es`), de modo que coordenadas, precios y paradas se escriben una sola vez. `?lang=es` en la API devuelve las fichas en ese idioma.
- Textos de interfaz: `skye-core/languages/skye-core-{locale}.l10n.php`. Una prueba exige que cada texto del plugin tenga traducción con los mismos marcadores.

## Decisiones abiertas

- Antes de publicar: pasar los tipos a "solo lo traducido" (`translate="1"`) o terminar las traducciones; con el respaldo activo, `/es/` sirve contenido en inglés.

- EN-GB / EN-US como idiomas separados en WPML o detección de país.
- Pago externo para revendedores en Bókun (afecta a `skye-booking-widget`, no a este contrato).
