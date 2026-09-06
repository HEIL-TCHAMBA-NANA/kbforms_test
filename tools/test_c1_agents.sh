#!/usr/bin/env bash
# C1 — Agents de terrain scopés à une enquête (M4) : création, connexion,
#      bundle/soumission scopés, révocation & dépublication → refus immédiat,
#      unicité globale de l'identifiant, exclusion des endpoints /me/* et gestion.
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
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"c1-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

echo "== setup : 4 comptes, 2 formulaires, 1 question, rôles collaborateurs"
read -r OWNER_TOK OWNER_ID <<< "$(reg own)"
read -r EDITOR_TOK EDITOR_ID <<< "$(reg edi)"
read -r ADMIN_TOK ADMIN_ID <<< "$(reg adm)"
read -r OUT_TOK OUT_ID <<< "$(reg out)"

F1=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OWNER_ID,\"title\":\"C1 recensement\"}" | J "['form_id']")
F2=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OWNER_ID,\"title\":\"C1 autre enquête\"}" | J "['form_id']")
Q1=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F1,\"type\":\"short_text\",\"label\":\"Nom du ménage\",\"position\":0}" | J "['question_id']")
$MYSQL "INSERT INTO form_collaborators (form_id, user_id, role) VALUES ($F1,$EDITOR_ID,'editor'), ($F1,$ADMIN_ID,'admin')"
curl -s -X POST "$BASE/forms/$F1/publish" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
echo "  F1=$F1 (publié) F2=$F2 Q1=$Q1  owner=$OWNER_ID editor=$EDITOR_ID admin=$ADMIN_ID stranger=$OUT_ID"

echo
echo "== 1. Création d'agent : propriétaire OK, admin OK, editor/tiers 403"
C=$(code -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $EDITOR_TOK" -H 'Content-Type: application/json' -d '{"display_name":"X","email":"x@kbforms.local"}')
[ "$C" = "403" ] || fail "editor peut créer un agent (HTTP $C)"
C=$(code -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $OUT_TOK" -H 'Content-Type: application/json' -d '{"display_name":"X","email":"x@kbforms.local"}')
[ "$C" = "403" ] || fail "tiers peut créer un agent (HTTP $C)"

# E-mail obligatoire : absent ou invalide → 400 (après le contrôle de droits)
C=$(code -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"display_name":"Sans mail"}')
[ "$C" = "400" ] || fail "agent créé sans e-mail (HTTP $C)"
C=$(code -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"display_name":"Mail nul","email":"pas-un-email"}')
[ "$C" = "400" ] || fail "agent créé avec un e-mail invalide (HTTP $C)"

R1=$(curl -s -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"display_name":"Agent Nord-1","email":"agent.nord1@kbforms.local"}')
echo "  $R1"
AGENT1=$(echo "$R1" | J "['agent_id']"); ID1=$(echo "$R1" | J "['identifiant']"); PW1=$(echo "$R1" | J "['password']")
[ -n "$AGENT1" ] && [ "$AGENT1" != "None" ] || fail "création par le propriétaire refusée : $R1"
[ "$(echo "$R1" | J "['email']")" = "agent.nord1@kbforms.local" ] || fail "e-mail non renvoyé à la création : $R1"

R2=$(curl -s -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $ADMIN_TOK" -H 'Content-Type: application/json' -d '{"display_name":"Agent Nord-2","email":"agent.nord2@kbforms.local"}')
AGENT2=$(echo "$R2" | J "['agent_id']"); ID2=$(echo "$R2" | J "['identifiant']")
[ -n "$AGENT2" ] && [ "$AGENT2" != "None" ] || fail "création par un admin refusée : $R2"
pass "propriétaire + admin peuvent créer, editor/tiers 403, e-mail obligatoire (agent1=$AGENT1 agent2=$AGENT2)"

echo
echo "== 1b. GET /forms/\$id/agents renvoie l'e-mail de chaque agent"
curl -s "$BASE/forms/$F1/agents" -H "Authorization: Bearer $OWNER_TOK" | python3 -c "
import sys, json
d = json.load(sys.stdin)
a = [x for x in d['agents'] if x['id'] == $AGENT1][0]
assert a.get('email') == 'agent.nord1@kbforms.local', a
print('  OK e-mail listé :', a['email'])
"
pass "e-mail exposé dans la liste des agents"

echo
echo "== 2. Unicité globale de l'identifiant"
[ "$ID1" != "$ID2" ] || fail "deux agents avec le même identifiant ($ID1)"
[ "$($MYSQL "SELECT COUNT(DISTINCT identifiant) FROM form_agents WHERE id IN ($AGENT1,$AGENT2)")" = "2" ] || fail "identifiants non uniques en base"
pass "identifiants distincts ($ID1 / $ID2)"

echo
echo "== 3. Connexion agent : mauvais mdp/identifiant → 401 ; bon → token"
C=$(code -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"faux\"}")
[ "$C" = "401" ] || fail "mauvais mot de passe accepté (HTTP $C)"
C=$(code -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d '{"identifiant":"INCONNU99","password":"x"}')
[ "$C" = "401" ] || fail "identifiant inconnu accepté (HTTP $C)"

LOGIN1=$(curl -s -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"$PW1\"}")
echo "  $LOGIN1"
TOKEN1=$(echo "$LOGIN1" | J "['token']")
[ "$(echo "$LOGIN1" | J "['form_id']")" = "$F1" ] || fail "form_id de connexion incorrect"
[ "$(echo "$LOGIN1" | J "['display_name']")" = "Agent Nord-1" ] || fail "display_name incorrect"
[ -n "$TOKEN1" ] && [ "$TOKEN1" != "None" ] || fail "pas de token renvoyé"
pass "connexion agent OK (form_id=$F1, display_name correct)"

echo
echo "== 3b. Régénération du mot de passe : propriétaire OK, editor/tiers 403, ancien mdp invalidé"
C=$(code -X POST "$BASE/forms/$F1/agents/$AGENT1/regenerate-password" -H "Authorization: Bearer $EDITOR_TOK")
[ "$C" = "403" ] || fail "editor peut régénérer le mot de passe (HTTP $C)"
C=$(code -X POST "$BASE/forms/$F1/agents/$AGENT1/regenerate-password" -H "Authorization: Bearer $OUT_TOK")
[ "$C" = "403" ] || fail "tiers peut régénérer le mot de passe (HTTP $C)"

RG=$(curl -s -X POST "$BASE/forms/$F1/agents/$AGENT1/regenerate-password" -H "Authorization: Bearer $OWNER_TOK")
echo "  $RG"
PW1_NEW=$(echo "$RG" | J "['password']")
[ -n "$PW1_NEW" ] && [ "$PW1_NEW" != "None" ] || fail "régénération refusée pour le propriétaire : $RG"
[ "$PW1_NEW" != "$PW1" ] || fail "le mot de passe régénéré est identique à l'ancien"
[ "$(echo "$RG" | J "['identifiant']")" = "$ID1" ] || fail "l'identifiant a changé à la régénération"

C=$(code -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"$PW1\"}")
[ "$C" = "401" ] || fail "l'ancien mot de passe fonctionne encore après régénération (HTTP $C)"
LN=$(curl -s -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"$PW1_NEW\"}")
[ -n "$(echo "$LN" | J "['token']")" ] && [ "$(echo "$LN" | J "['token']")" != "None" ] || fail "le nouveau mot de passe ne permet pas de se connecter : $LN"
PW1="$PW1_NEW"  # les sections suivantes utilisent le mot de passe courant
pass "régénération : propriétaire OK, editor/tiers 403, ancien mdp KO, nouveau mdp OK"

echo
echo "== 4. Bundle scopé : F1 avec le jeton agent → OK ; F2 → 403"
R=$(curl -s "$BASE/forms/$F1/bundle" -H "Authorization: Bearer $TOKEN1")
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert d.get('success') and d['form']['id']==$F1, d; print('  OK bundle F1')"
C=$(code "$BASE/forms/$F2/bundle" -H "Authorization: Bearer $TOKEN1")
[ "$C" = "403" ] || fail "agent accède au bundle d'un autre formulaire (HTTP $C)"
pass "bundle scopé au bon formulaire"

echo
echo "== 5. Endpoints exclus pour un jeton agent → 403"
for EP in "GET /me/forms" "GET /me/assignments" "GET /forms/$F1/agents" "GET /forms/$F1/collaborators"; do
  read -r M P <<< "$EP"
  C=$(code -X "$M" "$BASE$P" -H "Authorization: Bearer $TOKEN1")
  [ "$C" = "403" ] || fail "$EP accessible avec un jeton agent (HTTP $C)"
done
C=$(code -X POST "$BASE/me/devices" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' -d '{"fcm_token":"x"}')
[ "$C" = "403" ] || fail "POST /me/devices accessible avec un jeton agent (HTTP $C)"
C=$(code -X POST "$BASE/questions" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' -d "{\"form_id\":$F1,\"type\":\"short_text\",\"label\":\"x\",\"position\":9}")
[ "$C" = "403" ] || fail "POST /questions (gestion de structure) accessible avec un jeton agent (HTTP $C)"
C=$(code -X POST "$BASE/forms/$F1/agents" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' -d '{"display_name":"x"}')
[ "$C" = "403" ] || fail "un agent peut créer un autre agent (HTTP $C)"
pass "/me/*, gestion de formulaire et gestion des agents : tous 403 pour un jeton agent"

echo
echo "== 6. Soumission : agent_id attribué, jamais user_id ; form_id divergent → 403 ; lot → 403"
R=$(curl -s -X POST "$BASE/responses" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F1,\"answers\":[{\"question_id\":$Q1,\"value\":\"Ménage Diallo\"}]}")
echo "  $R"
RID1=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('response_id') or '')")
[ -n "$RID1" ] || fail "soumission agent refusée : $R"
ROW=$($MYSQL "SELECT CONCAT(IFNULL(user_id,'NULL'),'/',agent_id) FROM responses WHERE id=$RID1")
[ "$ROW" = "NULL/$AGENT1" ] || fail "attribution incorrecte (attendu NULL/$AGENT1, obtenu $ROW)"
pass "réponse $RID1 attribuée à l'agent $AGENT1, user_id NULL"

C=$(code -X POST "$BASE/responses" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F2,\"answers\":[{\"question_id\":$Q1,\"value\":\"x\"}]}")
[ "$C" = "403" ] || fail "agent soumet pour un autre formulaire que le sien (HTTP $C)"

C=$(code -X POST "$BASE/responses" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' \
  -d "{\"responses\":[{\"client_uuid\":\"$(python3 -c 'import uuid;print(uuid.uuid4())')\",\"form_id\":$F1,\"answers\":[{\"question_id\":$Q1,\"value\":\"x\"}]}]}")
[ "$C" = "403" ] || fail "l'envoi groupé accepte un jeton agent (HTTP $C)"
pass "form_id divergent et envoi groupé tous deux rejetés (403)"

echo
echo "== 7. Média : l'agent peut illustrer SA réponse, pas celle d'un autre"
R=$(curl -s -X POST "$BASE/responses/$RID1/media" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' \
  -d "{\"question_id\":$Q1,\"mime\":\"image/png\",\"data\":\"$PNG\"}")
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "upload média par l'agent sur sa propre réponse refusé : $R"

RID_OTHER=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F1,\"user_id\":null,\"answers\":[{\"question_id\":$Q1,\"value\":\"anonyme\"}]}" | python3 -c "import sys,json;print(json.load(sys.stdin)['response_id'])")
C=$(code -X POST "$BASE/responses/$RID_OTHER/media" -H "Authorization: Bearer $TOKEN1" -H 'Content-Type: application/json' \
  -d "{\"question_id\":$Q1,\"mime\":\"image/png\",\"data\":\"$PNG\"}")
[ "$C" = "403" ] || fail "agent illustre la réponse de quelqu'un d'autre (HTTP $C)"
pass "média : propre réponse OK, réponse d'autrui 403"

echo
echo "== 8. Révocation → refus IMMÉDIAT (connexion ET jeton déjà émis)"
curl -s -X PATCH "$BASE/forms/$F1/agents/$AGENT1" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"is_revoked":true}' >/dev/null
[ "$($MYSQL "SELECT is_revoked FROM form_agents WHERE id=$AGENT1")" = "1" ] || fail "révocation non persistée"

R=$(curl -s -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"$PW1\"}")
[ "$(echo "$R" | J "['code']")" = "form_unavailable" ] || fail "connexion d'un agent révoqué non refusée : $R"

C=$(code "$BASE/forms/$F1/bundle" -H "Authorization: Bearer $TOKEN1")
[ "$C" = "403" ] || fail "le jeton déjà émis d'un agent révoqué fonctionne encore (HTTP $C)"
pass "agent révoqué : connexion refusée + jeton déjà émis invalidé immédiatement"

echo
echo "== 9. Réactivation puis dépublication du formulaire → refus IMMÉDIAT"
curl -s -X PATCH "$BASE/forms/$F1/agents/$AGENT1" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"is_revoked":false}' >/dev/null
LOGIN2=$(curl -s -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"$PW1\"}")
TOKEN2=$(echo "$LOGIN2" | J "['token']")
[ -n "$TOKEN2" ] && [ "$TOKEN2" != "None" ] || fail "réactivation : reconnexion impossible : $LOGIN2"

curl -s -X PUT "$BASE/forms/$F1" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"is_published":0}' >/dev/null
R=$(curl -s -X POST "$BASE/agent-login" -H 'Content-Type: application/json' -d "{\"identifiant\":\"$ID1\",\"password\":\"$PW1\"}")
[ "$(echo "$R" | J "['code']")" = "form_unavailable" ] || fail "connexion sur formulaire dépublié non refusée : $R"
C=$(code "$BASE/forms/$F1/bundle" -H "Authorization: Bearer $TOKEN2")
[ "$C" = "403" ] || fail "jeton agent valide sur un formulaire dépublié encore accepté (HTTP $C)"
pass "dépublication : connexion refusée + jeton déjà émis invalidé immédiatement"

echo
echo "== 10. Suppression d'un agent"
curl -s -X DELETE "$BASE/forms/$F1/agents/$AGENT2" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
[ "$($MYSQL "SELECT COUNT(*) FROM form_agents WHERE id=$AGENT2")" = "0" ] || fail "agent non supprimé"
pass "agent supprimé"

echo
echo "== cleanup"
curl -s -X PUT "$BASE/forms/$F1" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' -d '{"is_published":1}' >/dev/null
curl -s -X DELETE "$BASE/forms/$F1" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
curl -s -X DELETE "$BASE/forms/$F2" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id IN ($OWNER_ID,$EDITOR_ID,$ADMIN_ID,$OUT_ID)"

echo
echo "TOUS LES TESTS C1 PASSENT ✅"
