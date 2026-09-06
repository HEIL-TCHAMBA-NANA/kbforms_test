#!/usr/bin/env bash
# D1 — Correctifs liés à l'audit "fonctionnalités non accessibles depuis le web" :
#   - PUT /responses/{id} : contrôle de propriété (manquait), repeat_index préservé
#   - POST /forms/{id}/validate : rendu public (utilisable par un répondant anonyme)
#   - GET /forms/{id}/responses : repeat_index + media_count exposés (tableau web)
#   - CRUD /permissions (create/rename/delete) : smoke test (nouvellement câblé en UI)
set -euo pipefail
BASE="${BASE:-http://localhost}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$DIR"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
PNG="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="

reg() {
  local r; r=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"d1-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

echo "== setup"
read -r OWNER_TOK OWNER_ID <<< "$(reg own)"
read -r OUT_TOK OUT_ID <<< "$(reg out)"
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OWNER_ID,\"title\":\"D1 audit web\"}" | J "['form_id']")
QA=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Nom\",\"required\":true,\"position\":0}" | J "['question_id']")
QB=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Âge\",\"position\":1}" | J "['question_id']")
G=$(curl -s -X POST "$BASE/forms/$F/repeat-groups" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d '{"label":"Membres","section_index":0}' | J "['group_id']")
QC=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Prénom membre\",\"position\":2,\"repeat_group_id\":$G}" | J "['question_id']")
echo "  form=$F QA=$QA QB=$QB QC(repeat)=$QC"

echo
echo "== 1. POST /forms/\$id/validate est PUBLIC (répondant anonyme) et fonctionne"
C=$(code -X POST "$BASE/forms/$F/validate" -H 'Content-Type: application/json' -d "{\"answers\":{}}")
[ "$C" != "401" ] || fail "l'endpoint exige encore une authentification (HTTP $C)"
R=$(curl -s -X POST "$BASE/forms/$F/validate" -H 'Content-Type: application/json' -d "{\"answers\":{}}")
[ "$(echo "$R" | J "['valid']")" = "False" ] || fail "champ obligatoire non détecté manquant : $R"
[ "$(echo "$R" | J "['missing'][0]['question_id']")" = "$QA" ] || fail "mauvais champ signalé manquant : $R"
R=$(curl -s -X POST "$BASE/forms/$F/validate" -H 'Content-Type: application/json' -d "{\"answers\":{\"$QA\":\"Alice\"}}")
[ "$(echo "$R" | J "['valid']")" = "True" ] || fail "validation refusée alors que le champ obligatoire est rempli : $R"
pass "endpoint de validation public et fonctionnel"

echo
echo "== 2. GET /forms/\$id/responses (en masse) expose repeat_index + media_count"
RID=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{
  \"form_id\":$F,\"user_id\":null,
  \"answers\":[
    {\"question_id\":$QA,\"value\":\"Alice\"},
    {\"question_id\":$QB,\"value\":\"34\"},
    {\"question_id\":$QC,\"value\":\"Bob\",\"repeat_index\":0},
    {\"question_id\":$QC,\"value\":\"Cid\",\"repeat_index\":1}
  ]}" | python3 -c "import sys,json;print(json.load(sys.stdin)['response_id'])")
curl -s -X POST "$BASE/responses/$RID/media" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"question_id\":$QA,\"mime\":\"image/png\",\"data\":\"$PNG\"}" >/dev/null

curl -s "$BASE/forms/$F/responses" -H "Authorization: Bearer $OWNER_TOK" | python3 -c "
import sys, json
rows = json.load(sys.stdin)
r = [x for x in rows if x['response_id']==$RID][0]
assert r.get('media_count') == 1, r
occ = [a for a in r['answers'] if a['question_id']==$QC]
assert len(occ) == 2, occ
idxs = sorted(a['repeat_index'] for a in occ)
assert idxs == [0,1], idxs
print('  OK media_count=1, 2 occurrences repeat_index=[0,1]')
"
pass "réponses en masse : repeat_index + media_count exposés"

echo
echo "== 3. PUT /responses/\$id : contrôle de propriété + repeat_index préservé"
C=$(code -X PUT "$BASE/responses/$RID" -H "Authorization: Bearer $OUT_TOK" -H 'Content-Type: application/json' \
  -d "{\"answers\":[{\"question_id\":$QA,\"value\":\"hack\"}]}")
[ "$C" = "403" ] || fail "un tiers peut modifier la réponse de quelqu'un d'autre (HTTP $C)"

# Le client (form-responses.html) renvoie systématiquement l'ensemble complet
# des réponses : ici QA modifié, QB/QC renvoyés inchangés avec leur repeat_index.
R=$(curl -s -X PUT "$BASE/responses/$RID" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d "{
  \"answers\":[
    {\"question_id\":$QA,\"value\":\"Alice Corrigée\",\"repeat_index\":null},
    {\"question_id\":$QB,\"value\":\"34\",\"repeat_index\":null},
    {\"question_id\":$QC,\"value\":\"Bob\",\"repeat_index\":0},
    {\"question_id\":$QC,\"value\":\"Cid\",\"repeat_index\":1}
  ]}")
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "édition par le propriétaire refusée : $R"
[ "$($MYSQL "SELECT value FROM answers WHERE response_id=$RID AND question_id=$QA")" = "Alice Corrigée" ] || fail "valeur non mise à jour"
[ "$($MYSQL "SELECT COUNT(*) FROM answers WHERE response_id=$RID AND question_id=$QC")" = "2" ] || fail "occurrences du groupe répétable perdues à l'édition"
[ "$($MYSQL "SELECT value FROM answers WHERE response_id=$RID AND question_id=$QC AND repeat_index=1")" = "Cid" ] || fail "repeat_index non préservé"
pass "propriété appliquée (403 tiers), édition OK, occurrences répétables préservées"

echo
echo "== 4. CRUD /permissions (nouvellement câblé dans roles.html)"
R=$(curl -s -X POST "$BASE/permissions" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"name":"d1.test.permission"}')
PID=$(echo "$R" | J "['permission_id']")
[ -n "$PID" ] && [ "$PID" != "None" ] || fail "création de permission refusée : $R"
curl -s "$BASE/permissions" -H "Authorization: Bearer $OWNER_TOK" | python3 -c "
import sys,json
perms=json.load(sys.stdin)
assert any(p['id']==$PID and p['name']=='d1.test.permission' for p in perms), perms
print('  OK permission listée')
"
curl -s -X PUT "$BASE/permissions/$PID" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"name":"d1.test.renamed"}' >/dev/null
[ "$($MYSQL "SELECT name FROM permissions WHERE id=$PID")" = "d1.test.renamed" ] || fail "renommage non appliqué"
curl -s -X DELETE "$BASE/permissions/$PID" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
[ "$($MYSQL "SELECT COUNT(*) FROM permissions WHERE id=$PID")" = "0" ] || fail "suppression non appliquée"
pass "création / renommage / suppression de permission OK"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id IN ($OWNER_ID,$OUT_ID)"

echo
echo "TOUS LES TESTS D1 PASSENT ✅"
