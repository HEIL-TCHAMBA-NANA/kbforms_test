#!/usr/bin/env bash
# A3 — GET /me/forms : formulaires possédés + collaborateur, + ?since=
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* kbforms -N -B -e"

login() { # $1 email  $2 pass
  curl -s -X POST "$BASE/login" -H 'Content-Type: application/json' \
    -H "X-KBF-Client-Secret: $SECRET" \
    -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])"
}

OWNER_EMAIL="a3-owner-$(date +%s)@kbforms.local"
COLLAB_EMAIL="a3-collab-$(date +%s)@kbforms.local"

echo "== setup : 2 comptes"
OWNER=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"Own\",\"last_name\":\"Er\",\"email\":\"$OWNER_EMAIL\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
OWNER_TOK=$(echo "$OWNER" | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")
OWNER_ID=$(echo "$OWNER" | python3 -c "import sys,json;print(json.load(sys.stdin)['user_id'])")
COLLAB=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"Col\",\"last_name\":\"Lab\",\"email\":\"$COLLAB_EMAIL\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
COLLAB_TOK=$(echo "$COLLAB" | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")
COLLAB_ID=$(echo "$COLLAB" | python3 -c "import sys,json;print(json.load(sys.stdin)['user_id'])")
echo "  owner=$OWNER_ID  collab=$COLLAB_ID"

echo "== owner crée 2 formulaires"
F1=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OWNER_ID,\"title\":\"A3 form un\"}" | python3 -c "import sys,json;print(json.load(sys.stdin)['form_id'])")
sleep 1
F2=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OWNER_TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OWNER_ID,\"title\":\"A3 form deux\"}" | python3 -c "import sys,json;print(json.load(sys.stdin)['form_id'])")
echo "  F1=$F1  F2=$F2"

echo "== collab devient éditeur de F1 (insert direct form_collaborators)"
$MYSQL "INSERT INTO form_collaborators (form_id, user_id, role) VALUES ($F1, $COLLAB_ID, 'editor')"

echo
echo "== 1. GET /me/forms (owner) → 2 formulaires, role=owner"
R=$(curl -s "$BASE/me/forms" -H "Authorization: Bearer $OWNER_TOK")
echo "$R" | python3 -m json.tool
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert len(d)==2,f'attendu 2, reçu {len(d)}';assert all(x['role']=='owner' for x in d);print('  OK 2 formulaires role=owner')"

echo
echo "== 2. GET /me/forms (collab) → 1 formulaire (F1), role=editor"
R=$(curl -s "$BASE/me/forms" -H "Authorization: Bearer $COLLAB_TOK")
echo "$R" | python3 -m json.tool
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert len(d)==1 and d[0]['id']==$F1 and d[0]['role']=='editor',d;print('  OK F1 role=editor')"

echo
echo "== 3. GET /me/forms sans token → 401"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/me/forms")
echo "  HTTP $CODE"; [ "$CODE" = "401" ] || { echo ÉCHEC; exit 1; }

echo
echo "== 4. ?since= dans le futur → 0 résultat"
FUT=$(date -u -d '+1 day' '+%Y-%m-%dT%H:%M:%S')
R=$(curl -s "$BASE/me/forms?since=$FUT" -H "Authorization: Bearer $OWNER_TOK")
echo "  $R"
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert d==[],d;print('  OK vide')"

echo
echo "== 5. ?since= dans le passé → 2 résultats"
PAST=$(date -u -d '-1 day' '+%Y-%m-%dT%H:%M:%S')
R=$(curl -s "$BASE/me/forms?since=$PAST" -H "Authorization: Bearer $OWNER_TOK")
echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);assert len(d)==2,d;print('  OK 2')"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F1" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
curl -s -X DELETE "$BASE/forms/$F2" -H "Authorization: Bearer $OWNER_TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id IN ($OWNER_ID, $COLLAB_ID)"

echo
echo "TOUS LES TESTS A3 PASSENT ✅"
