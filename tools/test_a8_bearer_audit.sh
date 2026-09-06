#!/usr/bin/env bash
# A8 — Audit Bearer : toute route `protected` doit
#   (a) répondre 401 SANS Authorization,
#   (b) NE PAS répondre 401 AVEC un Bearer valide (aucune dépendance cookie/Origin).
# Les {id} sont remplacés par un id volontairement inexistant → aucune mutation réelle.
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
ROUTER="$(dirname "$0")/../app/src/Core/Router.php"
BOGUS=999999999

TOK=$(curl -s -X POST "$BASE/login" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d '{"email":"mobile-test@kbforms.local","password":"secret123"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")
[ -n "$TOK" ] || { echo "ÉCHEC: pas de token"; exit 1; }

# Extrait: METHOD<TAB>PATH  pour chaque route marquée protégée (…, true);
mapfile -t ROUTES < <(grep -E '^\$router->add\(' "$ROUTER" \
  | grep -E 'true\);\s*$' \
  | sed -E 's/^\$router->add\("([A-Z]+)", *"([^"]+)".*/\1\t\2/')

echo "Routes protégées détectées : ${#ROUTES[@]}"
FAIL=0
for line in "${ROUTES[@]}"; do
  METHOD="${line%%$'\t'*}"
  RAW="${line##*$'\t'}"
  URL="$RAW"
  URL="${URL//\{token\}/deadbeef}"
  URL="${URL//\{id\}/$BOGUS}"
  # sans en-tête
  C_NO=$(curl -s -o /dev/null -w '%{http_code}' -X "$METHOD" "$BASE$URL" -H 'Content-Type: application/json' -d '{}')
  # avec Bearer
  C_YES=$(curl -s -o /dev/null -w '%{http_code}' -X "$METHOD" "$BASE$URL" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{}')
  STATUS="ok"
  [ "$C_NO" = "401" ]  || { STATUS="NON PROTÉGÉ (sans token → $C_NO)"; FAIL=1; }
  [ "$C_YES" != "401" ] || { STATUS="401 MÊME AVEC BEARER"; FAIL=1; }
  printf '  %-6s %-38s  sans=%s  avec=%s  %s\n' "$METHOD" "$RAW" "$C_NO" "$C_YES" "$STATUS"
done

echo
if [ "$FAIL" = "0" ]; then echo "AUDIT A8 : toutes les routes protégées OK ✅"; else echo "AUDIT A8 : anomalies ci-dessus ❌"; exit 1; fi
