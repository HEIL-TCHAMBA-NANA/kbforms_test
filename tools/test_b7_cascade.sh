#!/usr/bin/env bash
# B7 — Listes de choix en cascade : CRUD listes/items, import, wiring question, bundle.
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
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"b7-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

echo "== setup"
read -r TOK USERID <<< "$(reg own)"
read -r OUT_TOK OUT_ID <<< "$(reg out)"
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"B7 enquête localités\"}" | J "['form_id']")
echo "  user=$USERID form=$F"

echo
echo "== 1. POST /forms/$F/choice-lists (owner) → list_id ; tiers → 403"
R=$(curl -s -X POST "$BASE/forms/$F/choice-lists" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"name":"Localités"}')
echo "  $R"
LID=$(echo "$R" | J "['list_id']")
[ -n "$LID" ] && [ "$LID" != "None" ] || fail "création liste KO"
C=$(code -X POST "$BASE/forms/$F/choice-lists" -H "Authorization: Bearer $OUT_TOK" -H 'Content-Type: application/json' -d '{"name":"X"}')
[ "$C" = "403" ] || fail "tiers crée une liste (HTTP $C)"
pass "liste créée (id=$LID), tiers 403"

echo
echo "== 2. POST /choice-lists/$LID/import (3 lignes → 7 items hiérarchiques)"
R=$(curl -s -X POST "$BASE/choice-lists/$LID/import" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"rows":[["Nord","Garoua","Pitoa"],["Nord","Garoua","Bibémi"],["Centre","Yaoundé","Nlongkak"]]}')
echo "  $R"
[ "$(echo "$R" | J "['items_created']")" = "7" ] || fail "import : 7 items attendus, $R"
pass "import OK (7 items)"

echo
echo "== 3. GET /forms/$F/choice-lists → arbre correct"
R=$(curl -s "$BASE/forms/$F/choice-lists" -H "Authorization: Bearer $TOK")
echo "$R" | python3 -c "
import sys,json
d=json.load(sys.stdin)
L=[l for l in d['choice_lists'] if l['id']==$LID][0]
items=L['items']
assert len(items)==7, items
roots=[i for i in items if i['parent_item_id'] is None]
assert {r['label'] for r in roots}=={'Nord','Centre'}, roots
nord=[i for i in items if i['label']=='Nord'][0]
garoua=[i for i in items if i['label']=='Garoua'][0]
assert garoua['parent_item_id']==nord['id'], 'Garoua doit pointer sur Nord'
pitoa=[i for i in items if i['label']=='Pitoa'][0]
assert pitoa['parent_item_id']==garoua['id'], 'Pitoa doit pointer sur Garoua'
print('  OK arbre Nord>Garoua>Pitoa')
"

echo
echo "== 4. POST item racine + rejet parent hors-liste"
R=$(curl -s -X POST "$BASE/choice-lists/$LID/items" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"label":"Extrême-Nord"}')
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "ajout item racine KO: $R"
C=$(code -X POST "$BASE/choice-lists/$LID/items" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"label":"X","parent_item_id":999999}')
[ "$C" = "400" ] || fail "parent hors-liste accepté (HTTP $C)"
pass "item racine ajouté, parent invalide rejeté"

echo
echo "== 5. DELETE item racine 'Nord' → cascade sur ses descendants"
NID=$($MYSQL "SELECT id FROM choice_list_items WHERE list_id=$LID AND label='Nord'")
curl -s -X DELETE "$BASE/choice-lists/$LID/items/$NID" -H "Authorization: Bearer $TOK" >/dev/null
LEFT=$($MYSQL "SELECT COUNT(*) FROM choice_list_items WHERE list_id=$LID")
# restait : Extrême-Nord + Centre>Yaoundé>Nlongkak = 4
[ "$LEFT" = "4" ] || fail "cascade item KO (reste $LEFT au lieu de 4)"
pass "suppression 'Nord' → 4 items restants (cascade FK)"

echo
echo "== 6. Wiring question : dropdown racine + dropdown enfant"
Q1=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"dropdown\",\"label\":\"Région\",\"position\":0,\"cascade_list_id\":$LID}" | J "['question_id']")
Q2=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"dropdown\",\"label\":\"Ville\",\"position\":1,\"cascade_list_id\":$LID,\"cascade_parent_question_id\":$Q1}" | J "['question_id']")
[ "$($MYSQL "SELECT cascade_list_id FROM questions WHERE id=$Q1")" = "$LID" ] || fail "Q1.cascade_list_id non persisté"
[ "$($MYSQL "SELECT cascade_parent_question_id FROM questions WHERE id=$Q2")" = "$Q1" ] || fail "Q2.cascade_parent_question_id non persisté"
pass "questions liées à la liste (Q1=$Q1 racine, Q2=$Q2 enfant de Q1)"

echo
echo "== 7. GET /forms/$F/questions → champs cascade exposés"
curl -s "$BASE/forms/$F/questions" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin); qs=d if isinstance(d,list) else d.get('questions',[])
q1=[x for x in qs if x['id']==$Q1][0]; q2=[x for x in qs if x['id']==$Q2][0]
assert q1.get('cascade_list_id')==$LID, q1
assert q2.get('cascade_parent_question_id')==$Q1, q2
print('  OK')
"

echo
echo "== 8. GET /forms/$F/bundle → choice_lists inclus + items"
curl -s "$BASE/forms/$F/bundle" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
assert 'choice_lists' in d, list(d.keys())
cl=[l for l in d['choice_lists'] if l['id']==$LID]
assert cl and len(cl[0]['items'])==4, d['choice_lists']
q=[x for x in d['questions'] if x['id']==$Q1][0]
assert q.get('cascade_list_id')==$LID
print('  OK bundle porte la liste (4 items) + le wiring question')
"

echo
echo "== 8b. Formulaire public : GET /f/{token} porte choice_lists + wiring"
curl -s -X POST "$BASE/forms/$F/publish" -H "Authorization: Bearer $TOK" >/dev/null
TKN=$($MYSQL "SELECT SUBSTRING_INDEX(share_link,'/f/',-1) FROM forms WHERE id=$F")
curl -s "$BASE/f/$TKN" | python3 -c "
import sys,json
d=json.load(sys.stdin)
assert 'choice_lists' in d, list(d.keys())
cl=[l for l in d['choice_lists'] if l['id']==$LID]
assert cl and len(cl[0]['items'])==4, d.get('choice_lists')
q=[x for x in d['questions'] if x['id']==$Q1][0]
assert q.get('cascade_list_id')==$LID, q
print('  OK formulaire public : liste (4 items) + cascade_list_id sur la question')
"

echo
echo "== 9. PUT /questions/$Q2 : retirer le parent (cascade_parent_question_id=null)"
curl -s -X PUT "$BASE/questions/$Q2" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"id\":$Q2,\"form_id\":$F,\"type\":\"dropdown\",\"label\":\"Ville\",\"position\":1,\"cascade_parent_question_id\":null}" >/dev/null
V=$($MYSQL "SELECT IFNULL(cascade_parent_question_id,'NULL') FROM questions WHERE id=$Q2")
[ "$V" = "NULL" ] || fail "parent non remis à NULL (=$V)"
pass "cascade_parent_question_id remis à NULL"

echo
echo "== 10. Sans token → 401 ; 11. suppression du formulaire → cascade listes"
C=$(code "$BASE/forms/$F/choice-lists"); [ "$C" = "401" ] || fail "pas de 401 sans token (HTTP $C)"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $TOK" >/dev/null
[ "$($MYSQL "SELECT COUNT(*) FROM choice_lists WHERE id=$LID")" = "0" ] || fail "liste orpheline après suppression du formulaire"
[ "$($MYSQL "SELECT COUNT(*) FROM choice_list_items WHERE list_id=$LID")" = "0" ] || fail "items orphelins"
pass "401 sans token + cascade formulaire → listes/items purgés"

echo
echo "== cleanup"
$MYSQL "DELETE FROM users WHERE id IN ($USERID,$OUT_ID)"

echo
echo "TOUS LES TESTS B7 PASSENT ✅"
