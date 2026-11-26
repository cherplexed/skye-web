#!/usr/bin/env bash
# Prueba de humo contra el stack local: la API responde y devuelve lugares.
set -euo pipefail
BASE="${1:-http://localhost:8080}"

echo "→ GET $BASE/wp-json/skye/v1/places"
body=$(curl -sf "$BASE/wp-json/skye/v1/places?per_page=100")
total=$(echo "$body" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)($d["meta"]["total"]??0);')
echo "   total=$total"
[ "$total" -ge 1 ] || { echo "FAIL: no places returned"; exit 1; }

slug=$(echo "$body" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"][0]["slug"]??"";')
echo "→ GET $BASE/wp-json/skye/v1/places/$slug"
curl -sf "$BASE/wp-json/skye/v1/places/$slug" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo "   ", $d["data"]["title"], " (", $d["data"]["location"]["lat"], ",", $d["data"]["location"]["lng"], ")\n";'

echo "→ GET $BASE/wp-json/skye/v1/audioguide/stops"
curl -sf "$BASE/wp-json/skye/v1/audioguide/stops" >/dev/null && echo "   ok"
for ep in routes tours brands lodging motorhome-spots experiences; do
  echo "→ GET $BASE/wp-json/skye/v1/$ep"
  curl -sf "$BASE/wp-json/skye/v1/$ep" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!isset($d["data"],$d["meta"]["total"])){fwrite(STDERR,"FAIL: bad response\n");exit(1);} echo "   total=", $d["meta"]["total"], "\n";'
done
echo "PASS"
