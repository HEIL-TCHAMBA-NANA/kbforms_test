#!/usr/bin/env bash
# B2 — Assignation d'enquêtes : form_assignments + /me/assignments + CRUD.
#   superviseur = propriétaire (ou collaborateur admin) ; enquêteur = assigné.
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }

reg() { # $1 label -> echoes "TOKEN ID"
  local r
  r=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"b2-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== setup : superviseur, enquêteur, tiers"
read -r SUP_TOK SUP_ID <<< "$(reg sup)"
read -r COL_TOK COL_ID <<< "$(reg col)"
read -r OUT_TOK OUT_ID <<< "$(reg out)"
echo "  sup=$SUP_ID  col=$COL_ID  out=$OUT_ID"

F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $SUP_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$SUP_ID,\"title\":\"B2 enquête terrain\"}" | J "['form_id']")
echo "  form=$F"

echo
echo "== 1. POST /forms/$F/assignments (sup → col) → success, pending"
R=$(curl -s -X POST "$BASE/forms/$F/assignments" -H "Authorization: Bearer $SUP_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$COL_ID,\"note\":\"Zone Nord, 20 ménages\"}")
echo "  $R"
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "assign échoue"
[ "$(echo "$R" | J "['status']")" = "pending" ] || fail "statut initial != pending"
AID=$(echo "$R" | J "['assignment_id']")
pass "assigné (assignment_id=$AID)"

echo
echo "== 2. GET /me/assignments (col) → 1 entrée, titre + note + assigned_by_name"
R=$(curl -s "$BASE/me/assignments" -H "Authorization: Bearer $COL_TOK")
echo "$R" | python3 -m json.tool
N=$(echo "$R" | J "['assignments'].__len__()")
[ "$N" = "1" ] || fail "attendu 1 assignation, reçu $N"
[ "$(echo "$R" | J "['assignments'][0]['title']")" = "B2 enquête terrain" ] || fail "titre absent"
[ "$(echo "$R" | J "['assignments'][0]['note']")" = "Zone Nord, 20 ménages" ] || fail "note absente"
[ "$(echo "$R" | J "['assignments'][0]['status']")" = "pending" ] || fail "statut"
pass "1 assignation vue par l'enquêteur"

echo
echo "== 3. GET /me/assignments (sup) → 0 (ne s'est pas assigné lui-même)"
R=$(curl -s "$BASE/me/assignments" -H "Authorization: Bearer $SUP_TOK")
[ "$(echo "$R" | J "['assignments'].__len__()")" = "0" ] || fail "le superviseur voit des assignations à tort"
pass "superviseur : 0"

echo
echo "== 4. POST /forms/$F/assignments par un tiers → 403"
C=$(code -X POST "$BASE/forms/$F/assignments" -H "Authorization: Bearer $OUT_TOK" -H 'Content-Type: application/json' -d "{\"user_id\":$OUT_ID}")
[ "$C" = "403" ] || fail "tiers peut assigner (HTTP $C)"
pass "tiers refusé (403)"

echo
echo "== 5. GET /forms/$F/assignments (sup) → 1 ; (tiers) → 403"
R=$(curl -s "$BASE/forms/$F/assignments" -H "Authorization: Bearer $SUP_TOK")
[ "$(echo "$R" | J "['assignments'].__len__()")" = "1" ] || fail "liste superviseur != 1"
[ "$(echo "$R" | J "['assignments'][0]['assignee_email']")" != "" ] || fail "assignee_email manquant"
C=$(code "$BASE/forms/$F/assignments" -H "Authorization: Bearer $OUT_TOK")
[ "$C" = "403" ] || fail "tiers lit la liste (HTTP $C)"
pass "liste superviseur OK, tiers 403"

echo
echo "== 6. PATCH /assignments/$AID (col) status=in_progress → success"
R=$(curl -s -X PATCH "$BASE/assignments/$AID" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d '{"status":"in_progress"}')
[ "$(echo "$R" | J "['status']")" = "in_progress" ] || fail "PATCH statut: $R"
R=$(curl -s "$BASE/me/assignments" -H "Authorization: Bearer $COL_TOK")
[ "$(echo "$R" | J "['assignments'][0]['status']")" = "in_progress" ] || fail "statut non persistant"
pass "statut passé à in_progress"

echo
echo "== 7. PATCH /assignments/$AID par un tiers → 403 ; statut bidon → 400"
C=$(code -X PATCH "$BASE/assignments/$AID" -H "Authorization: Bearer $OUT_TOK" -H 'Content-Type: application/json' -d '{"status":"done"}')
[ "$C" = "403" ] || fail "tiers modifie le statut (HTTP $C)"
C=$(code -X PATCH "$BASE/assignments/$AID" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d '{"status":"parti"}')
[ "$C" = "400" ] || fail "statut invalide accepté (HTTP $C)"
pass "tiers 403, statut invalide 400"

echo
echo "== 8. Ré-assignation (POST à nouveau, même enquêteur) → upsert, statut re-pending, 1 seule ligne"
R=$(curl -s -X POST "$BASE/forms/$F/assignments" -H "Authorization: Bearer $SUP_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$COL_ID,\"note\":\"Zone Sud\"}")
[ "$(echo "$R" | J "['assignment_id']")" = "$AID" ] || fail "ré-assignation crée une nouvelle ligne"
ROWS=$($MYSQL "SELECT COUNT(*) FROM form_assignments WHERE form_id=$F")
[ "$ROWS" = "1" ] || fail "$ROWS lignes en base au lieu d'1"
R=$(curl -s "$BASE/me/assignments" -H "Authorization: Bearer $COL_TOK")
[ "$(echo "$R" | J "['assignments'][0]['status']")" = "pending" ] || fail "statut non remis à pending"
[ "$(echo "$R" | J "['assignments'][0]['note']")" = "Zone Sud" ] || fail "note non mise à jour"
pass "upsert : 1 ligne, pending, note à jour"

echo
echo "== 9. DELETE /forms/$F/assignments/$COL_ID (tiers → 403 ; sup → removed)"
C=$(code -X DELETE "$BASE/forms/$F/assignments/$COL_ID" -H "Authorization: Bearer $OUT_TOK")
[ "$C" = "403" ] || fail "tiers désassigne (HTTP $C)"
R=$(curl -s -X DELETE "$BASE/forms/$F/assignments/$COL_ID" -H "Authorization: Bearer $SUP_TOK")
[ "$(echo "$R" | J "['removed']")" = "True" ] || fail "delete: $R"
R=$(curl -s "$BASE/me/assignments" -H "Authorization: Bearer $COL_TOK")
[ "$(echo "$R" | J "['assignments'].__len__()")" = "0" ] || fail "assignation encore visible après delete"
pass "désassignation OK"

echo
echo "== 10. GET /me/assignments sans token → 401"
C=$(code "$BASE/me/assignments")
[ "$C" = "401" ] || fail "pas de 401 sans token (HTTP $C)"
pass "401 sans token"

echo
echo "== 11. FK cascade : supprimer le formulaire purge l'assignation"
curl -s -X POST "$BASE/forms/$F/assignments" -H "Authorization: Bearer $SUP_TOK" -H 'Content-Type: application/json' -d "{\"user_id\":$COL_ID}" >/dev/null
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $SUP_TOK" >/dev/null
ROWS=$($MYSQL "SELECT COUNT(*) FROM form_assignments WHERE form_id=$F")
[ "$ROWS" = "0" ] || fail "assignations orphelines après suppression du formulaire ($ROWS)"
pass "cascade ON DELETE OK"

echo
echo "== cleanup"
$MYSQL "DELETE FROM users WHERE id IN ($SUP_ID,$COL_ID,$OUT_ID)"

echo
echo "TOUS LES TESTS B2 PASSENT ✅"
