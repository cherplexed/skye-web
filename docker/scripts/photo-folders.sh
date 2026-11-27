#!/usr/bin/env bash
# Crea en docker/photos/ una carpeta por cada ficha de los datos de carga. Ejecutar desde la raíz del repo.
set -euo pipefail
cd "$(dirname "$0")/../.."
for type in places routes tours; do
  grep -o '"slug": *"[^"]*"' "seed/$type.json" | sed 's/.*"\([^"]*\)"$/\1/' | while read -r slug; do
    mkdir -p "docker/photos/$type/$slug"
    echo "docker/photos/$type/$slug/"
  done
done
