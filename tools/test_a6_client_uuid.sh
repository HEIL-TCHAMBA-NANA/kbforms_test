#!/usr/bin/env bash
# A6 — responses.client_uuid (idempotence) + métadonnées de collecte
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* kbforms -N -B -e"
py() { python3 -c "import sys,json;$1"; }

TS=$(date +%s)
U=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"A6\",\"last_name\":\"T\",\"email\":\"a6-$TS@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
TOK=$(echo "$U"|py "print(json.load(sys.stdin)['token'])"); USERID=$(echo "$U"|py "print(json.load(sys.stdin)['user_id'])")
FID=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"A6\"}" | py "print(json.load(sys.stdin)['form_id'])")
QID=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"type\":\"short_text\",\"label\":\"Q\",\"required\":false,\"position\":0,\"section_index\":0}" | py "print(json.load(sys.stdin)['question_id'])")

UUID="11111111-2222-3333-4444-$(printf '%012d' $TS)"
BODY="{\"form_id\":$FID,\"client_uuid\":\"$UUID\",\"device_id\":\"dev-abc\",\"app_version\":\"1.0.0\",\"gps_lat\":3.8480,\"gps_lng\":11.5021,\"gps_accuracy\":12.5,\"started_at\":\"2026-09-01T08:00:00Z\",\"duration_s\":95,\"mock_location\":true,\"submitted_at\":\"2026-09-01T08:01:35Z\",\"answers\":[{\"question_id\":$QID,\"value\":\"terrain\"}]}"

echo "== 1. POST /responses avec client_uuid + métadonnées"
R1=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "$BODY")
echo "$R1" | python3 -m json.tool
RID=$(echo "$R1" | py "print(json.load(sys.stdin)['response_id'])")

echo
echo "== 2. Rejeu du MÊME client_uuid → duplicate:true, même response_id, pas de 2e ligne"
R2=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "$BODY")
echo "$R2" | python3 -m json.tool
echo "$R2" | py "d=json.load(sys.stdin);assert d.get('duplicate') is True and d['response_id']==$RID,d;print('  OK duplicate')"
CNT=$($MYSQL "SELECT COUNT(*) FROM responses WHERE client_uuid='$UUID'")
echo "  lignes avec ce client_uuid = $CNT"; [ "$CNT" = "1" ] || { echo ÉCHEC; exit 1; }

echo
echo "== 3. Métadonnées bien persistées"
$MYSQL "SELECT client_uuid,device_id,app_version,gps_lat,gps_lng,gps_accuracy,started_at,duration_s,mock_location,submitted_at FROM responses WHERE id=$RID" | tr '\t' '\n'
ROW=$($MYSQL "SELECT CONCAT_WS('|',device_id,app_version,gps_lat,duration_s,mock_location,submitted_at) FROM responses WHERE id=$RID")
echo "$ROW" | grep -q 'dev-abc|1.0.0|3.8480000|95|1|2026-09-01 08:01:35' || { echo "ÉCHEC: métadonnées inattendues → $ROW"; exit 1; }
echo "  OK (submitted_at = horodatage client, mock_location=1)"

echo
echo "== 4. POST /responses SANS client_uuid → comportement inchangé"
R4=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"answers\":[{\"question_id\":$QID,\"value\":\"web\"}]}")
echo "$R4" | python3 -m json.tool
echo "$R4" | py "d=json.load(sys.stdin);assert d.get('success') and 'response_id' in d and not d.get('duplicate');print('  OK')"

echo
curl -s -X DELETE "$BASE/forms/$FID" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE email LIKE 'a6-$TS@kbforms.local'"
echo "TOUS LES TESTS A6 PASSENT ✅"
