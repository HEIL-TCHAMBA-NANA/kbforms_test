#!/usr/bin/env bash
# A1 — POST /auth/refresh + refresh_tokens : test bout-en-bout.
# Prérequis : backend en service sur $BASE, compte de test existant.
set -euo pipefail
BASE="${BASE:-http://localhost}"
EMAIL="${EMAIL:-mobile-test@kbforms.local}"
PASS="${PASS:-secret123}"
# reCAPTCHA est actif (A2) → on s'identifie comme l'app mobile pour /login.
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
jq() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))"; }

echo "== 1. POST /login → doit renvoyer token + refresh_token + refresh_expires_at"
LOGIN=$(curl -s -X POST "$BASE/login" -H 'Content-Type: application/json' \
  -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")
echo "$LOGIN" | python3 -m json.tool
JWT=$(echo "$LOGIN" | jq token)
RT1=$(echo "$LOGIN" | jq refresh_token)
[ -n "$JWT" ] && [ -n "$RT1" ] || { echo "ÉCHEC: tokens manquants"; exit 1; }

echo
echo "== 2. POST /auth/refresh {refresh_token: RT1} → nouvelle paire (rotation)"
R2=$(curl -s -X POST "$BASE/auth/refresh" -H 'Content-Type: application/json' \
  -d "{\"refresh_token\":\"$RT1\"}")
echo "$R2" | python3 -m json.tool
JWT2=$(echo "$R2" | jq token)
RT2=$(echo "$R2" | jq refresh_token)
[ -n "$JWT2" ] && [ -n "$RT2" ] && [ "$RT2" != "$RT1" ] || { echo "ÉCHEC: pas de rotation"; exit 1; }

echo
echo "== 3. POST /auth/refresh {refresh_token: RT1 (ancien)} → 401 (révoqué)"
CODE=$(curl -s -o /tmp/a1_r3.json -w '%{http_code}' -X POST "$BASE/auth/refresh" \
  -H 'Content-Type: application/json' -d "{\"refresh_token\":\"$RT1\"}")
cat /tmp/a1_r3.json; echo " [HTTP $CODE]"
[ "$CODE" = "401" ] || { echo "ÉCHEC: l'ancien refresh aurait dû être refusé"; exit 1; }

echo
echo "== 4. POST /auth/refresh {refresh_token: RT2} → OK (RT2 encore valide)"
R4=$(curl -s -X POST "$BASE/auth/refresh" -H 'Content-Type: application/json' \
  -d "{\"refresh_token\":\"$RT2\"}")
echo "$R4" | python3 -m json.tool
[ -n "$(echo "$R4" | jq token)" ] || { echo "ÉCHEC"; exit 1; }

echo
echo "== 5. Rétro-compat : POST /auth/refresh avec Bearer <jwt valide> → { token }"
R5=$(curl -s -X POST "$BASE/auth/refresh" -H "Authorization: Bearer $JWT2")
echo "$R5" | python3 -m json.tool
[ -n "$(echo "$R5" | jq token)" ] || { echo "ÉCHEC"; exit 1; }

echo
echo "== 6. POST /auth/refresh {refresh_token: 'bidon'} → 401"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/refresh" \
  -H 'Content-Type: application/json' -d '{"refresh_token":"deadbeef"}')
echo "HTTP $CODE"
[ "$CODE" = "401" ] || { echo "ÉCHEC"; exit 1; }

echo
echo "TOUS LES TESTS A1 PASSENT ✅"
