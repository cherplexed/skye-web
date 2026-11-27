# Fotos

Las fotos originales se dejan aquí y se suben a WordPress con un comando. **No se versionan** (están en
`.gitignore`): pesan demasiado para git y tienen derechos de autor.

> **Pendiente (issue #9):** las 13 portadas cargadas el 27 nov 2026 son provisionales. Hay que
> sustituirlas por las que se compren con la cuenta de iStock de ShyPig antes de publicar la web.

## Dónde va cada foto

Una carpeta por ficha, con el identificador (slug) de la ficha como nombre:

```
docker/photos/
  places/old-man-of-storr/01.jpg   ← la primera por orden alfabético es la portada
  places/old-man-of-storr/02.jpg   ← el resto, la galería (solo los lugares tienen galería)
  routes/trotternish-loop/01.jpg
  tours/isle-of-skye-3-day-tour/01.jpg
  photos.csv                       ← opcional: texto alternativo y crédito
```

Tipos admitidos: `places`, `routes`, `tours`, `lodging`, `motorhome-spots`, `experiences`.
Para crear las carpetas de las fichas piloto: `bash docker/scripts/photo-folders.sh`.

## Cómo tienen que ser

- JPG en sRGB (también PNG o WebP). Una sola versión: WordPress genera los tamaños para móvil.
- Entre 2000 y 2560 px de lado largo, hasta unos 2 MB.
- Portada horizontal (3:2 o 16:9) con el sujeto centrado: en móvil se recorta por los lados.
- Sin texto, marcos ni marcas de agua. Solo fotos propias o con licencia.

## Texto alternativo y crédito (opcional)

Copia `photos.example.csv` como `photos.csv` y pon una línea por foto. Sin esa hoja, el texto
alternativo es el nombre de la ficha y no hay crédito. Se puede rellenar después y volver a importar.

## Subirlas

Desde la raíz del repo:

```bash
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli wp skye photos import --dry-run   # ver qué haría
docker compose --env-file .env -f docker/docker-compose.yml run --rm wpcli wp skye photos import
```

Se puede repetir las veces que haga falta: una foto ya subida no se duplica, y una que has cambiado
se sustituye. Si quitas una foto de la carpeta, deja de mostrarse en la ficha pero sigue en la
biblioteca de medios.
