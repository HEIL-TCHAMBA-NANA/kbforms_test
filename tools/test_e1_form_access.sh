#!/usr/bin/env bash
# E1 — Contrôle d'accès aux formulaires (faille IDOR historique).
#   Avant ce lot, n'importe quel compte pouvait lire/modifier/supprimer les
#   enquêtes et réponses d'un autre en devinant un id numérique. On vérifie :
#   - un tiers sans lien avec le formulaire → 403 partout
#   - un collaborateur viewer  → lecture OK, écriture refusée
#   - un collaborateur editor  → structure OK, cycle de vie refusé
#   - un collaborateur admin / le propriétaire → tout OK
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
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"e1-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

echo "== setup"
read -r OWN_T OWN_ID   <<< "$(reg own)"
read -r VIE_T VIE_ID   <<< "$(reg vie)"
read -r EDI_T EDI_ID   <<< "$(reg edi)"
read -r ADM_T ADM_ID   <<< "$(reg adm)"
read -r OUT_T OUT_ID   <<< "$(reg out)"

F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OWN_T" -H 'Content-Type: application/json' \
  -d "{\"title\":\"E1 accès\"}" | J "['form_id']")
Q=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OWN_T" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Nom\",\"position\":0}" | J "['question_id']")
S=$(curl -s -X POST "$BASE/forms/$F/sections" -H "Authorization: Bearer $OWN_T" -H 'Content-Type: application/json' \
  -d '{"title":"Section 1","position":0}' | J "['section_id']")
C=$(curl -s -X POST "$BASE/forms/$F/conditions" -H "Authorization: Bearer $OWN_T" -H 'Content-Type: application/json' \
  -d "{\"source_question_id\":$Q,\"operator\":\"eq\",\"value\":\"x\",\"target_section_id\":$S}" | J "['condition_id']")
W=$(curl -s -X POST "$BASE/forms/$F/webhooks" -H "Authorization: Bearer $OWN_T" -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/hook"}' | J "['webhook_id']")
RID=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"user_id\":null,\"answers\":[{\"question_id\":$Q,\"value\":\"secret-RH\"}]}" | J "['response_id']")
curl -s -X POST "$BASE/forms/$F/publish" -H "Authorization: Bearer $OWN_T" >/dev/null
$MYSQL "INSERT INTO form_collaborators (form_id,user_id,role) VALUES ($F,$VIE_ID,'viewer'),($F,$EDI_ID,'editor'),($F,$ADM_ID,'admin')"
echo "  form=$F Q=$Q S=$S C=$C W=$W response=$RID"

echo
echo "== 1. Tiers sans lien → 403 sur toute la surface"
for EP in "GET /forms/$F" "PUT /forms/$F" "DELETE /forms/$F" "GET /forms/$F/responses" \
          "GET /forms/$F/analytics" "GET /forms/$F/export/csv" "GET /forms/$F/questions" \
          "GET /forms/$F/sections" "GET /forms/$F/conditions" "GET /forms/$F/webhooks" \
          "GET /forms/$F/collaborators" "POST /forms/$F/publish" "GET /forms/$F/version" \
          "GET /forms/$F/theme" "PATCH /forms/$F/theme" "POST /forms/$F/sections" \
          "GET /forms/$F/sheets" "GET /users/$OWN_ID/forms"; do
  read -r M P <<< "$EP"
  CC=$(code -X "$M" "$BASE$P" -H "Authorization: Bearer $OUT_T" -H 'Content-Type: application/json' -d '{}')
  [ "$CC" = "403" ] || fail "$EP accessible à un tiers (HTTP $CC)"
done
# sous-ressources (id → formulaire résolu côté contrôleur)
for EP in "PUT /questions/$Q" "DELETE /questions/$Q" "PUT /sections/$S" "DELETE /sections/$S" \
          "DELETE /conditions/$C" "DELETE /webhooks/$W" "PUT /webhooks/$W/toggle"; do
  read -r M P <<< "$EP"
  CC=$(code -X "$M" "$BASE$P" -H "Authorization: Bearer $OUT_T" -H 'Content-Type: application/json' -d '{"label":"x"}')
  [ "$CC" = "403" ] || fail "$EP accessible à un tiers (HTTP $CC)"
done
CC=$(code -X POST "$BASE/questions" -H "Authorization: Bearer $OUT_T" -H 'Content-Type: application/json' -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"x\",\"position\":9}")
[ "$CC" = "403" ] || fail "POST /questions accessible à un tiers (HTTP $CC)"
# la voie d'escalade de privilège : s'auto-ajouter admin
CC=$(code -X POST "$BASE/forms/$F/collaborators" -H "Authorization: Bearer $OUT_T" -H 'Content-Type: application/json' -d "{\"user_id\":$OUT_ID,\"role\":\"admin\"}")
[ "$CC" = "403" ] || fail "un tiers peut s'ajouter comme collaborateur admin (HTTP $CC)"
# lecture des réponses confidentielles
R=$(curl -s "$BASE/forms/$F/responses" -H "Authorization: Bearer $OUT_T")
echo "$R" | grep -q "secret-RH" && fail "un tiers lit les réponses confidentielles : $R"
pass "tiers : 403 partout, aucune donnée exposée"

echo
echo "== 2. Collaborateur viewer : lecture OK, écriture refusée"
[ "$(code "$BASE/forms/$F" -H "Authorization: Bearer $VIE_T")" = "200" ] || fail "viewer ne peut pas lire le formulaire"
[ "$(code "$BASE/forms/$F/responses" -H "Authorization: Bearer $VIE_T")" = "200" ] || fail "viewer ne peut pas lire les réponses"
[ "$(code -X PUT "$BASE/forms/$F" -H "Authorization: Bearer $VIE_T" -H 'Content-Type: application/json' -d '{"title":"h"}')" = "403" ] || fail "viewer peut modifier le formulaire"
[ "$(code -X POST "$BASE/questions" -H "Authorization: Bearer $VIE_T" -H 'Content-Type: application/json' -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"x\",\"position\":8}")" = "403" ] || fail "viewer peut ajouter une question"
pass "viewer : lecture OK, écriture 403"

echo
echo "== 3. Collaborateur editor : structure OK, cycle de vie refusé"
QN=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $EDI_T" -H 'Content-Type: application/json' -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Ajout editor\",\"position\":5}" | J "['question_id']")
[ -n "$QN" ] && [ "$QN" != "None" ] || fail "editor ne peut pas ajouter de question"
[ "$(code -X PATCH "$BASE/forms/$F/theme" -H "Authorization: Bearer $EDI_T" -H 'Content-Type: application/json' -d '{"theme_color":"#111111"}')" = "200" ] || fail "editor ne peut pas modifier le thème"
[ "$(code -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $EDI_T")" = "403" ] || fail "editor peut supprimer le formulaire"
[ "$(code -X POST "$BASE/forms/$F/collaborators" -H "Authorization: Bearer $EDI_T" -H 'Content-Type: application/json' -d "{\"user_id\":$OUT_ID,\"role\":\"viewer\"}")" = "403" ] || fail "editor peut gérer les collaborateurs"
pass "editor : structure OK, suppression/collaborateurs 403"

echo
echo "== 4. Collaborateur admin + propriétaire : tout OK"
[ "$(code -X PUT "$BASE/forms/$F" -H "Authorization: Bearer $ADM_T" -H 'Content-Type: application/json' -d '{"title":"E1 renommé par admin"}')" = "200" ] || fail "admin ne peut pas modifier le formulaire"
[ "$(code "$BASE/forms/$F/invitations" -H "Authorization: Bearer $ADM_T")" = "200" ] || fail "admin ne peut pas lister les invitations (route admin)"
[ "$(code -X DELETE "$BASE/forms/$F/collaborators/$VIE_ID" -H "Authorization: Bearer $ADM_T")" = "200" ] || fail "admin ne peut pas retirer un collaborateur"
[ "$(code "$BASE/forms/$F/responses" -H "Authorization: Bearer $OWN_T")" = "200" ] || fail "propriétaire ne peut pas lire les réponses"
[ "$(code -X PUT "$BASE/questions/$Q" -H "Authorization: Bearer $OWN_T" -H 'Content-Type: application/json' -d '{"label":"Nom complet"}')" = "200" ] || fail "propriétaire ne peut pas modifier une question"
pass "admin + propriétaire : accès complet"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $OWN_T" >/dev/null
$MYSQL "DELETE FROM users WHERE id IN ($OWN_ID,$VIE_ID,$EDI_ID,$ADM_ID,$OUT_ID)"

echo
echo "TOUS LES TESTS E1 PASSENT ✅"
