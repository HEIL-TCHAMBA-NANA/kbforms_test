#!/usr/bin/env bash
# B8 — Groupes de questions répétables : CRUD, wiring, soumission multi-occurrences,
#      bornes min/max, relecture, export CSV.
set -euo pipefail
BASE="${BASE:-http://localhost}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$DIR"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

reg() {
  local r; r=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"b8-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

echo "== setup"
read -r TOK USERID <<< "$(reg own)"
read -r OUT_TOK OUT_ID <<< "$(reg out)"
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"B8 enquête ménage\"}" | J "['form_id']")
curl -s -X POST "$BASE/forms/$F/sections" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"title":"Ménage","position":0}' >/dev/null
QN=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Commune\",\"position\":0,\"section_index\":0}" | J "['question_id']")
echo "  form=$F  QN=$QN"

echo
echo "== 1. POST /forms/$F/repeat-groups (min 1, max 5) ; tiers → 403"
R=$(curl -s -X POST "$BASE/forms/$F/repeat-groups" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"label":"Membres du ménage","section_index":0,"min_repeat":1,"max_repeat":5}')
echo "  $R"
G=$(echo "$R" | J "['group_id']")
[ -n "$G" ] && [ "$G" != "None" ] || fail "création groupe KO"
C=$(code -X POST "$BASE/forms/$F/repeat-groups" -H "Authorization: Bearer $OUT_TOK" -H 'Content-Type: application/json' -d '{"label":"X"}')
[ "$C" = "403" ] || fail "tiers crée un groupe (HTTP $C)"
pass "groupe créé (id=$G), tiers 403"

echo
echo "== 2. 2 questions membres + wiring"
Q1=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Prénom\",\"position\":1,\"section_index\":0,\"repeat_group_id\":$G}" | J "['question_id']")
Q2=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"radio\",\"label\":\"Sexe\",\"position\":2,\"section_index\":0,\"repeat_group_id\":$G,\"options\":[\"F\",\"M\"]}" | J "['question_id']")
[ "$($MYSQL "SELECT repeat_group_id FROM questions WHERE id=$Q1")" = "$G" ] || fail "Q1 pas rattachée au groupe"
pass "Q1=$Q1 Q2=$Q2 rattachées au groupe"

echo
echo "== 3. GET /forms/$F/repeat-groups → groupe + question_ids + bornes"
curl -s "$BASE/forms/$F/repeat-groups" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
g=[x for x in d['repeat_groups'] if x['id']==$G][0]
assert g['min_repeat']==1 and g['max_repeat']==5, g
assert sorted(g['question_ids'])==sorted([$Q1,$Q2]), g['question_ids']
print('  OK')
"

echo
echo "== 4. GET /forms/$F/bundle → repeat_groups + repeat_group_id sur les questions"
curl -s "$BASE/forms/$F/bundle" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
assert 'repeat_groups' in d and any(x['id']==$G for x in d['repeat_groups']), list(d.keys())
q=[x for x in d['questions'] if x['id']==$Q1][0]
assert q.get('repeat_group_id')==$G, q
print('  OK')
"

echo
echo "== 5. POST /responses : 2 occurrences du groupe"
R=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{
  \"form_id\":$F,\"user_id\":null,
  \"answers\":[
    {\"question_id\":$QN,\"value\":\"Garoua\"},
    {\"question_id\":$Q1,\"value\":\"Alice\",\"repeat_index\":0},
    {\"question_id\":$Q2,\"value\":\"F\",\"repeat_index\":0},
    {\"question_id\":$Q1,\"value\":\"Bob\",\"repeat_index\":1},
    {\"question_id\":$Q2,\"value\":\"M\",\"repeat_index\":1}
  ]}")
RID=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('response_id') or '')")
[ -n "$RID" ] || fail "réponse non créée: $R"
[ "$($MYSQL "SELECT COUNT(DISTINCT repeat_index) FROM answers WHERE response_id=$RID AND repeat_index IS NOT NULL")" = "2" ] || fail "occurrences non enregistrées"
[ "$($MYSQL "SELECT value FROM answers WHERE response_id=$RID AND question_id=$Q1 AND repeat_index=1")" = "Bob" ] || fail "valeur occurrence 1 KO"
pass "réponse $RID avec 2 occurrences"

echo
echo "== 6. min_repeat : 0 occurrence → 422 ; max_repeat : 6 occurrences → 422"
C=$(code -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{\"form_id\":$F,\"user_id\":null,\"answers\":[{\"question_id\":$QN,\"value\":\"x\"}]}")
[ "$C" = "422" ] || fail "min_repeat non appliqué (HTTP $C)"
SIX=$(python3 -c "
import json
a=[{'question_id':$QN,'value':'x'}]
for i in range(6):
    a.append({'question_id':$Q1,'value':'p%d'%i,'repeat_index':i})
print(json.dumps({'form_id':$F,'user_id':None,'answers':a}))
")
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "$SIX")
[ "$C" = "422" ] || fail "max_repeat non appliqué (HTTP $C)"
pass "bornes min/max appliquées (422)"

echo
echo "== 7. GET /responses/$RID → repeat_index dans les answers"
curl -s "$BASE/responses/$RID" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
occ={a['repeat_index'] for a in d['answers'] if a['question_id']==$Q1}
assert occ=={0,1}, [a for a in d['answers'] if a['question_id']==$Q1]
print('  OK occurrences 0 et 1 relues')
"

echo
echo "== 8. Export CSV → colonnes « Prénom [1] » / « Prénom [2] »"
CSV=$(curl -s "$BASE/forms/$F/export/csv" -H "Authorization: Bearer $TOK")
echo "$CSV" | head -1 | grep -q 'Prénom \[1\]' || fail "colonne 'Prénom [1]' absente"
echo "$CSV" | head -1 | grep -q 'Prénom \[2\]' || fail "colonne 'Prénom [2]' absente"
echo "$CSV" | sed -n '2p' | grep -q 'Alice' || fail "valeur Alice absente du CSV"
pass "export CSV : une colonne par occurrence"

echo
echo "== 9. PUT /repeat-groups/$G : bornes ; max<min → 400"
C=$(code -X PUT "$BASE/repeat-groups/$G" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"min_repeat":3,"max_repeat":2}')
[ "$C" = "400" ] || fail "max<min accepté (HTTP $C)"
curl -s -X PUT "$BASE/repeat-groups/$G" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"min_repeat":0,"max_repeat":10,"label":"Personnes"}' >/dev/null
[ "$($MYSQL "SELECT CONCAT(min_repeat,'/',max_repeat,'/',label) FROM repeat_groups WHERE id=$G")" = "0/10/Personnes" ] || fail "PUT non appliqué"
pass "PUT bornes+label OK, max<min rejeté"

echo
echo "== 10. DELETE /repeat-groups/$G → questions membres détachées (FK SET NULL)"
curl -s -X DELETE "$BASE/repeat-groups/$G" -H "Authorization: Bearer $TOK" >/dev/null
[ "$($MYSQL "SELECT IFNULL(repeat_group_id,'NULL') FROM questions WHERE id=$Q1")" = "NULL" ] || fail "Q1 encore rattachée après suppression du groupe"
pass "suppression groupe → questions détachées"

echo
echo "== 11. Sans token → 401"
C=$(code "$BASE/forms/$F/repeat-groups"); [ "$C" = "401" ] || fail "pas de 401 sans token (HTTP $C)"
pass "401 sans token"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id IN ($USERID,$OUT_ID)"

echo
echo "TOUS LES TESTS B8 PASSENT ✅"
