#!/usr/bin/env bash
# C2 (M5) — pièce jointe photo opt-in par question (champ questions.allow_photo).
#   - défaut false ; accepté par POST /questions et PUT /questions/{id}
#   - exposé dans GET /forms/{id}/bundle (lu par le mobile) ET GET /f/{token}
#   - remis à false si on bascule la question vers signature/audio/video (UI ;
#     côté serveur on stocke tel quel, testé via PUT explicite)
set -euo pipefail
BASE="${BASE:-http://localhost}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$DIR"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }

reg() {
  local r; r=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"c2-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

# allow_photo d'une question donnée dans le bundle
bundle_ap() {
  curl -s "$BASE/forms/$1/bundle" -H "Authorization: Bearer $2" | python3 -c "
import sys,json
d=json.load(sys.stdin)
q=[x for x in d['questions'] if x['id']==$3][0]
print(q.get('allow_photo'))
"
}

echo "== setup"
read -r TOK UID_ <<< "$(reg own)"
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -d '{"title":"C2 allow_photo"}' | J "['form_id']")
echo "  form=$F"

echo
echo "== 1. Question créée SANS allow_photo → défaut false"
Q1=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Nom\",\"position\":0}" | J "['question_id']")
[ "$(bundle_ap "$F" "$TOK" "$Q1")" = "False" ] || fail "allow_photo n'est pas false par défaut"
pass "défaut false"

echo
echo "== 2. Question créée AVEC allow_photo:true → true dans le bundle"
Q2=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"long_text\",\"label\":\"Observations\",\"position\":1,\"allow_photo\":true}" | J "['question_id']")
[ "$(bundle_ap "$F" "$TOK" "$Q2")" = "True" ] || fail "allow_photo:true à la création non pris en compte"
[ "$($MYSQL "SELECT allow_photo FROM questions WHERE id=$Q2")" = "1" ] || fail "allow_photo non persisté en base"
pass "activé à la création"

echo
echo "== 3. PUT /questions/\$id bascule allow_photo"
curl -s -X PUT "$BASE/questions/$Q1" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"label\":\"Nom\",\"required\":false,\"position\":0,\"allow_photo\":true}" >/dev/null
[ "$(bundle_ap "$F" "$TOK" "$Q1")" = "True" ] || fail "PUT allow_photo:true non appliqué"
curl -s -X PUT "$BASE/questions/$Q2" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"label\":\"Observations\",\"required\":false,\"position\":1,\"allow_photo\":false}" >/dev/null
[ "$(bundle_ap "$F" "$TOK" "$Q2")" = "False" ] || fail "PUT allow_photo:false non appliqué"
pass "PUT bascule dans les deux sens"

echo
echo "== 4. PUT sans le champ allow_photo → valeur inchangée"
curl -s -X PUT "$BASE/questions/$Q1" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"label\":\"Nom complet\",\"required\":true,\"position\":0}" >/dev/null
[ "$(bundle_ap "$F" "$TOK" "$Q1")" = "True" ] || fail "allow_photo perdu lors d'un PUT qui ne le mentionne pas"
pass "champ omis = inchangé"

echo
echo "== 5. GET /f/\$token (formulaire public) expose aussi allow_photo"
curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"short_text\",\"label\":\"Ville\",\"position\":2}" >/dev/null
PUB=$(curl -s -X POST "$BASE/forms/$F/publish" -H "Authorization: Bearer $TOK")
TKN=$(echo "$PUB" | J "['share_link']" | grep -oP '(?<=/f/)[a-zA-Z0-9]+')
curl -s "$BASE/f/$TKN" | python3 -c "
import sys,json
d=json.load(sys.stdin)
qs={x['id']:x for x in d['questions']}
assert qs[$Q1]['allow_photo'] is True, qs[$Q1]
assert qs[$Q2]['allow_photo'] is False, qs[$Q2]
print('  OK allow_photo présent sur chaque question du formulaire public')
"
pass "formulaire public : allow_photo exposé"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id=$UID_"

echo
echo "TOUS LES TESTS C2 PASSENT ✅"
