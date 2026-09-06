#!/usr/bin/env bash
# B6 — Champs calculés : type `calculated`, validation de formule, exposition, stockage.
# (L'évaluation est côté client — non testée ici.)
set -euo pipefail
BASE="${BASE:-http://localhost}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$DIR"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== setup"
OWN=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"Calc\",\"last_name\":\"T\",\"email\":\"b6-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
TOK=$(echo "$OWN" | J "['token']"); USERID=$(echo "$OWN" | J "['user_id']")
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"B6 calculs\"}" | J "['form_id']")
QN=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Montant\",\"position\":0}" | J "['question_id']")
QD=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"date\",\"label\":\"Naissance\",\"position\":1}" | J "['question_id']")
echo "  form=$F  QN=$QN  QD=$QD"

echo
echo "== 1. POST /questions type=calculated, formule valide"
QC=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"calculated\",\"label\":\"TVA\",\"position\":2,\"calculated_expression\":\"round({q$QN} * 0.2)\"}" | J "['question_id']")
[ -n "$QC" ] && [ "$QC" != "None" ] || fail "création calculated refusée"
[ "$($MYSQL "SELECT calculated_expression FROM questions WHERE id=$QC")" = "round({q$QN} * 0.2)" ] || fail "formule non persistée"
pass "champ calculé créé (id=$QC)"

echo
echo "== 2. age({qD}) accepté"
QC2=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"calculated\",\"label\":\"Âge\",\"position\":3,\"calculated_expression\":\"age({q$QD})\"}" | J "['question_id']")
[ -n "$QC2" ] && [ "$QC2" != "None" ] || fail "age() refusé"
pass "age({q}) accepté"

echo
echo "== 3. Formules invalides → 400"
for BAD in 'DROP TABLE' '{q1} +' '(1 + 2' 'system(\"x\")' '{q1} & {q2}'; do
  C=$(code -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
    -d "{\"form_id\":$F,\"type\":\"calculated\",\"label\":\"X\",\"position\":9,\"calculated_expression\":\"$BAD\"}")
  [ "$C" = "400" ] || fail "formule invalide acceptée : « $BAD » (HTTP $C)"
done
pass "5 formules invalides rejetées (400)"

echo
echo "== 4. GET /forms/$F/questions + bundle exposent calculated_expression"
curl -s "$BASE/forms/$F/questions" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin); qs=d if isinstance(d,list) else d.get('questions',[])
q=[x for x in qs if x['id']==$QC][0]
assert q['type']=='calculated' and q.get('calculated_expression')=='round({q$QN} * 0.2)', q
print('  OK /questions')
"
curl -s "$BASE/forms/$F/bundle" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
q=[x for x in d['questions'] if x['id']==$QC][0]
assert q.get('calculated_expression')=='round({q$QN} * 0.2)', q
print('  OK bundle')
"

echo
echo "== 5. POST /responses : la valeur calculée (calculée par le client) est stockée"
R=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{
  \"form_id\":$F,\"user_id\":null,
  \"answers\":[{\"question_id\":$QN,\"value\":\"150\"},{\"question_id\":$QC,\"value\":\"30\"}]}")
RID=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('response_id') or '')")
[ -n "$RID" ] || fail "réponse non créée: $R"
[ "$($MYSQL "SELECT value FROM answers WHERE response_id=$RID AND question_id=$QC")" = "30" ] || fail "valeur calculée non stockée"
pass "valeur calculée stockée dans answers.value"

echo
echo "== 6. PUT /questions/$QC : formule invalide → 400 ; valide → OK"
C=$(code -X PUT "$BASE/questions/$QC" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"id\":$QC,\"form_id\":$F,\"type\":\"calculated\",\"label\":\"TVA\",\"position\":2,\"calculated_expression\":\"{q1} ; rm\"}")
[ "$C" = "400" ] || fail "PUT formule invalide acceptée (HTTP $C)"
curl -s -X PUT "$BASE/questions/$QC" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"id\":$QC,\"form_id\":$F,\"type\":\"calculated\",\"label\":\"TVA\",\"position\":2,\"calculated_expression\":\"{q$QN} + {q$QD}\"}" >/dev/null
[ "$($MYSQL "SELECT calculated_expression FROM questions WHERE id=$QC")" = "{q$QN} + {q$QD}" ] || fail "PUT formule valide non enregistrée"
pass "PUT : invalide 400, valide enregistrée"

echo
echo "== 7. Conversion de type → formule effacée"
curl -s -X PUT "$BASE/questions/$QC" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"id\":$QC,\"form_id\":$F,\"type\":\"short_text\",\"label\":\"TVA\",\"position\":2,\"calculated_expression\":null}" >/dev/null
[ "$($MYSQL "SELECT IFNULL(calculated_expression,'NULL') FROM questions WHERE id=$QC")" = "NULL" ] || fail "formule non effacée au changement de type"
pass "changement de type → calculated_expression = NULL"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id=$USERID"

echo
echo "TOUS LES TESTS B6 PASSENT ✅"
