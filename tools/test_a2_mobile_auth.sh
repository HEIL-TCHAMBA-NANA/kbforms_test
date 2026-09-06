#!/usr/bin/env bash
# A2 — Chemin d'auth mobile : l'en-tête X-KBF-Client-Secret contourne le CAPTCHA.
# Prérequis : reCAPTCHA ACTIF (clés renseignées) + app/config/mobile.php rempli.
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
[ -n "$SECRET" ] || { echo "ÉCHEC: secret client introuvable (app/config/mobile.php)"; exit 1; }
EMAIL="mobile-test@kbforms.local"; PASS="secret123"
NEW="a2-$(date +%s)@kbforms.local"

echo "== 1. POST /login SANS captcha ni en-tête → doit être REFUSÉ (400 captcha)"
R=$(curl -s -o /tmp/a2_1.json -w '%{http_code}' -X POST "$BASE/login" \
  -H 'Content-Type: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")
cat /tmp/a2_1.json; echo " [HTTP $R]"
grep -q 'anti-robot' /tmp/a2_1.json || { echo "ÉCHEC: le CAPTCHA aurait dû bloquer"; exit 1; }

echo
echo "== 2. POST /login AVEC X-KBF-Client-Secret → OK + tokens"
R=$(curl -s -X POST "$BASE/login" -H 'Content-Type: application/json' \
  -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")
echo "$R" | python3 -m json.tool
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert d.get('token') and d.get('refresh_token'),'tokens manquants';print('  → token + refresh_token présents')"

echo
echo "== 3. POST /register AVEC X-KBF-Client-Secret → OK"
R=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' \
  -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"A2\",\"last_name\":\"Test\",\"email\":\"$NEW\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
echo "$R" | python3 -m json.tool
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert d.get('success') and d.get('token'),'register KO';print('  → compte créé + tokens')"

echo
echo "== 4. POST /login avec MAUVAIS secret → refusé (400 captcha)"
R=$(curl -s -o /tmp/a2_4.json -w '%{http_code}' -X POST "$BASE/login" \
  -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: mauvais" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")
cat /tmp/a2_4.json; echo " [HTTP $R]"
grep -q 'anti-robot' /tmp/a2_4.json || { echo "ÉCHEC: un mauvais secret ne doit pas passer"; exit 1; }

echo
echo "TOUS LES TESTS A2 PASSENT ✅"
