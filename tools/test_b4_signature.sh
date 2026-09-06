#!/usr/bin/env bash
# B4 — Type de question « signature » : création, bundle, soumission (web + média).
set -euo pipefail
BASE="${BASE:-http://localhost}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$DIR"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }

# 1×1 PNG transparent en data URI (tient lieu de « signature »)
PNG="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="

echo "== setup"
OWN=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"Sig\",\"last_name\":\"T\",\"email\":\"b4-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
TOK=$(echo "$OWN" | J "['token']"); USERID=$(echo "$OWN" | J "['user_id']")
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"B4 consentement\"}" | J "['form_id']")
echo "  user=$USERID form=$F"

echo
echo "== 1. POST /questions type=signature → accepté (enum élargi)"
R=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"signature\",\"label\":\"Signature du répondant\",\"required\":true,\"position\":0}")
echo "  $R"
QID=$(echo "$R" | J "['question_id']")
[ -n "$QID" ] && [ "$QID" != "None" ] || fail "création signature refusée : $R"
[ "$($MYSQL "SELECT type FROM questions WHERE id=$QID")" = "signature" ] || fail "type non persisté"
pass "question signature créée (id=$QID)"

echo
echo "== 2. GET /forms/$F/bundle → question présente avec type=signature"
R=$(curl -s "$BASE/forms/$F/bundle" -H "Authorization: Bearer $TOK")
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);qs=[q for q in d['questions'] if q['id']==$QID];assert qs and qs[0]['type']=='signature',d['questions'];print('  OK type=signature dans le bundle')"

echo
echo "== 3. GET /forms/$F/questions → idem"
curl -s "$BASE/forms/$F/questions" -H "Authorization: Bearer $TOK" \
  | python3 -c "import sys,json;d=json.load(sys.stdin);qs=d if isinstance(d,list) else d.get('questions',[]);q=[x for x in qs if x['id']==$QID];assert q and q[0]['type']=='signature';print('  OK')"

echo
echo "== 4. POST /responses avec la signature en valeur (parcours web public)"
R=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"user_id\":null,\"answers\":[{\"question_id\":$QID,\"value\":\"$PNG\"}]}")
echo "  $R"
RID=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('response_id') or d.get('id') or '')")
[ -n "$RID" ] || fail "réponse non créée : $R"
STORED=$($MYSQL "SELECT LEFT(value,22) FROM answers WHERE response_id=$RID AND question_id=$QID")
[ "$STORED" = "data:image/png;base64," ] || fail "valeur signature non stockée (got: $STORED)"
pass "signature stockée dans answers.value (réponse $RID)"

echo
echo "== 5. GET /responses/$RID → la signature revient"
curl -s "$BASE/responses/$RID" -H "Authorization: Bearer $TOK" \
  | python3 -c "import sys,json;d=json.load(sys.stdin);a=[x for x in d['answers'] if x['question_id']==$QID];assert a and a[0]['value'].startswith('data:image/png'),d;print('  OK signature relue')"

echo
echo "== 6. Parcours mobile : POST /responses/$RID/media sur la question signature"
R=$(curl -s -X POST "$BASE/responses/$RID/media" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"question_id\":$QID,\"mime\":\"image/png\",\"data\":\"$PNG\"}")
echo "  $R"
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "upload média signature KO : $R"
pass "média signature accepté (pipeline A9 inchangé)"

echo
echo "== 7. changeType : passer une question texte → signature (PUT /questions/{id})"
Q2=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"tmp\",\"position\":1}" | J "['question_id']")
curl -s -X PUT "$BASE/questions/$Q2" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"id\":$Q2,\"form_id\":$F,\"type\":\"signature\",\"label\":\"Visa responsable\",\"position\":1}" >/dev/null
[ "$($MYSQL "SELECT type FROM questions WHERE id=$Q2")" = "signature" ] || fail "PUT vers signature KO"
pass "conversion de type vers signature OK"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id=$USERID"

echo
echo "TOUS LES TESTS B4 PASSENT ✅"
