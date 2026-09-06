#!/usr/bin/env bash
# A5 — forms.version + GET /forms/{id}/version + bump sur modif de structure
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* kbforms -N -B -e"
py() { python3 -c "import sys,json;$1"; }
ver() { curl -s "$BASE/forms/$1/version" -H "Authorization: Bearer $2" | py "print(json.load(sys.stdin).get('version'))"; }

TS=$(date +%s)
U=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"A5\",\"last_name\":\"T\",\"email\":\"a5-$TS@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
TOK=$(echo "$U"|py "print(json.load(sys.stdin)['token'])"); USERID=$(echo "$U"|py "print(json.load(sys.stdin)['user_id'])")
FID=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"A5\"}" | py "print(json.load(sys.stdin)['form_id'])")

V0=$(ver $FID $TOK); echo "version initiale = $V0"; [ "$V0" = "1" ] || { echo "ÉCHEC: attendu 1"; exit 1; }

echo "-- création d'une section → +1"
SID=$(curl -s -X POST "$BASE/forms/$FID/sections" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"title":"S","position":0}' | py "print(json.load(sys.stdin)['section_id'])")
V1=$(ver $FID $TOK); echo "  version = $V1"; [ "$V1" -gt "$V0" ] || { echo ÉCHEC; exit 1; }

echo "-- création d'une question → +1"
QID=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"type\":\"short_text\",\"label\":\"Q\",\"required\":false,\"position\":0,\"section_index\":0}" | py "print(json.load(sys.stdin)['question_id'])")
V2=$(ver $FID $TOK); echo "  version = $V2"; [ "$V2" -gt "$V1" ] || { echo ÉCHEC; exit 1; }

echo "-- update de la question → +1"
curl -s -X PUT "$BASE/questions/$QID" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"label\":\"Q modifiée\",\"type\":\"short_text\",\"required\":true,\"position\":0,\"section_index\":0}" >/dev/null
V3=$(ver $FID $TOK); echo "  version = $V3"; [ "$V3" -gt "$V2" ] || { echo ÉCHEC; exit 1; }

echo "-- update du thème → +1"
curl -s -X PATCH "$BASE/forms/$FID/theme" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"theme_color":"#123456"}' >/dev/null
V4=$(ver $FID $TOK); echo "  version = $V4"; [ "$V4" -gt "$V3" ] || { echo ÉCHEC; exit 1; }

echo "-- suppression de la question → +1"
curl -s -X DELETE "$BASE/questions/$QID" -H "Authorization: Bearer $TOK" >/dev/null
V5=$(ver $FID $TOK); echo "  version = $V5"; [ "$V5" -gt "$V4" ] || { echo ÉCHEC; exit 1; }

echo "-- bundle expose la même version"
BV=$(curl -s "$BASE/forms/$FID/bundle" -H "Authorization: Bearer $TOK" | py "print(json.load(sys.stdin)['version'])")
echo "  bundle.version = $BV"; [ "$BV" = "$V5" ] || { echo "ÉCHEC: bundle $BV != version $V5"; exit 1; }

echo "-- /me/forms expose la version"
MV=$(curl -s "$BASE/me/forms" -H "Authorization: Bearer $TOK" | py "print([f['version'] for f in json.load(sys.stdin) if f['id']==$FID][0])")
echo "  /me/forms version = $MV"; [ "$MV" = "$V5" ] || { echo ÉCHEC; exit 1; }

echo "-- accès tiers → 403"
S=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"X\",\"last_name\":\"Y\",\"email\":\"a5x-$TS@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}" | py "print(json.load(sys.stdin)['token'])")
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/forms/$FID/version" -H "Authorization: Bearer $S")
echo "  HTTP $CODE"; [ "$CODE" = "403" ] || { echo ÉCHEC; exit 1; }

curl -s -X DELETE "$BASE/forms/$FID" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE email LIKE 'a5%-$TS@kbforms.local'"
echo
echo "TOUS LES TESTS A5 PASSENT ✅"
