#!/usr/bin/env bash
# A7 — POST /responses en lot (mobile, authentifié) : idempotent, rejouable.
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* kbforms -N -B -e"
py() { python3 -c "import sys,json;$1"; }

TS=$(date +%s)
U=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"A7\",\"last_name\":\"T\",\"email\":\"a7-$TS@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
TOK=$(echo "$U"|py "print(json.load(sys.stdin)['token'])"); USERID=$(echo "$U"|py "print(json.load(sys.stdin)['user_id'])")
FID=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"A7\"}" | py "print(json.load(sys.stdin)['form_id'])")
QID=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"type\":\"short_text\",\"label\":\"Q\",\"required\":false,\"position\":0,\"section_index\":0}" | py "print(json.load(sys.stdin)['question_id'])")

U1="aaaaaaaa-0000-0000-0000-$(printf '%012d' $TS)"
U2="bbbbbbbb-0000-0000-0000-$(printf '%012d' $TS)"
U3="cccccccc-0000-0000-0000-$(printf '%012d' $TS)"

mkbatch() { cat <<EOF
{"responses":[
 {"client_uuid":"$U1","form_id":$FID,"submitted_at":"2026-09-01T09:00:00Z","gps_lat":3.86,"gps_lng":11.52,"answers":[{"question_id":$QID,"value":"un"}]},
 {"client_uuid":"$U2","form_id":$FID,"submitted_at":"2026-09-01T09:05:00Z","answers":[{"question_id":$QID,"value":"deux"}]},
 {"client_uuid":"$U3","form_id":$FID,"answers":[]}
]}
EOF
}

echo "== 1. Envoi du lot (2 valides + 1 sans answers)"
R=$(curl -s -X POST "$BASE/responses" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d "$(mkbatch)")
echo "$R" | python3 -m json.tool
echo "$R" | py "
d=json.load(sys.stdin); r={x['client_uuid']:x['status'] for x in d['results']}
assert r['$U1']=='created' and r['$U2']=='created' and r['$U3']=='error', r
print('  OK : created, created, error')"

echo
echo "== 2. Rejeu du MÊME lot → duplicate, duplicate, error (idempotent)"
R=$(curl -s -X POST "$BASE/responses" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d "$(mkbatch)")
echo "$R" | py "
d=json.load(sys.stdin); r={x['client_uuid']:x['status'] for x in d['results']}
assert r['$U1']=='duplicate' and r['$U2']=='duplicate' and r['$U3']=='error', r
print('  OK : duplicate, duplicate, error')"
CNT=$($MYSQL "SELECT COUNT(*) FROM responses WHERE form_id=$FID")
echo "  réponses en base pour ce formulaire = $CNT"; [ "$CNT" = "2" ] || { echo "ÉCHEC: attendu 2"; exit 1; }

echo
echo "== 3. Réponses + answers bien enregistrées"
$MYSQL "SELECT r.client_uuid, r.submitted_at, a.value FROM responses r JOIN answers a ON a.response_id=r.id WHERE r.form_id=$FID ORDER BY r.client_uuid"

echo
echo "== 4. Lot sans Bearer → 401"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "$(mkbatch)")
echo "  HTTP $CODE"; [ "$CODE" = "401" ] || { echo ÉCHEC; exit 1; }

echo
echo "== 5. Lot > 100 items → 400"
BIG='{"responses":['"$(for i in $(seq 1 101); do printf '{"client_uuid":"x-%d","form_id":%d,"answers":[{"question_id":%d,"value":"v"}]},' $i $FID $QID; done | sed 's/,$//')"']}'
CODE=$(curl -s -o /tmp/a7_5.json -w '%{http_code}' -X POST "$BASE/responses" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d "$BIG")
cat /tmp/a7_5.json; echo " [HTTP $CODE]"; [ "$CODE" = "400" ] || { echo ÉCHEC; exit 1; }

echo
echo "== 6. Envoi unitaire classique inchangé"
R=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{\"form_id\":$FID,\"answers\":[{\"question_id\":$QID,\"value\":\"web\"}]}")
echo "$R" | py "d=json.load(sys.stdin);assert d.get('success') and 'results' not in d;print('  OK')"

echo
curl -s -X DELETE "$BASE/forms/$FID" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE email LIKE 'a7-$TS@kbforms.local'"
echo "TOUS LES TESTS A7 PASSENT ✅"
