#!/usr/bin/env bash
# B5 — Types de question audio / vidéo : enum, durée max, média inline (web public) + média mobile.
set -euo pipefail
BASE="${BASE:-http://localhost}"
DIR="$(cd "$(dirname "$0")" && pwd)"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$DIR"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* -S /opt/lampp/var/mysql/mysql.sock kbforms -N -B -e"
J() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)"; }
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; exit 1; }

AUD="data:audio/mpeg;base64,QUJDREVG"       # "ABCDEF"
VID="data:video/mp4;base64,QUJDREVGRw=="    # "ABCDEFG"

echo "== setup"
OWN=$(curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"AV\",\"last_name\":\"T\",\"email\":\"b5-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
TOK=$(echo "$OWN" | J "['token']"); USERID=$(echo "$OWN" | J "['user_id']")
F=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$USERID,\"title\":\"B5 verbatim terrain\"}" | J "['form_id']")
echo "  user=$USERID form=$F"

echo
echo "== 1. POST /questions type=audio (media_max_duration_s=60)"
QA=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"audio\",\"label\":\"Verbatim\",\"required\":true,\"position\":0,\"media_max_duration_s\":60}" | J "['question_id']")
[ "$($MYSQL "SELECT type FROM questions WHERE id=$QA")" = "audio" ] || fail "type audio non persisté"
[ "$($MYSQL "SELECT media_max_duration_s FROM questions WHERE id=$QA")" = "60" ] || fail "durée non persistée"
pass "question audio (id=$QA, max 60s)"

echo
echo "== 2. POST /questions type=video"
QV=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$F,\"type\":\"video\",\"label\":\"Séquence\",\"position\":1}" | J "['question_id']")
[ "$($MYSQL "SELECT type FROM questions WHERE id=$QV")" = "video" ] || fail "type video non persisté"
pass "question vidéo (id=$QV)"

echo
echo "== 3. GET /forms/$F/questions → types + media_max_duration_s exposés"
curl -s "$BASE/forms/$F/questions" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin); qs=d if isinstance(d,list) else d.get('questions',[])
a=[x for x in qs if x['id']==$QA][0]; v=[x for x in qs if x['id']==$QV][0]
assert a['type']=='audio' and a.get('media_max_duration_s')==60, a
assert v['type']=='video', v
print('  OK')
"

echo
echo "== 4. GET /forms/$F/bundle → idem"
curl -s "$BASE/forms/$F/bundle" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
a=[x for x in d['questions'] if x['id']==$QA][0]
assert a['type']=='audio' and a.get('media_max_duration_s')==60, a
print('  OK')
"

echo
echo "== 5. POST /responses (public) avec média inline (answers[].media)"
R=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{
  \"form_id\":$F,\"user_id\":null,
  \"answers\":[
    {\"question_id\":$QA,\"value\":\"verbatim.mp3\",\"media\":\"$AUD\",\"media_mime\":\"audio/mpeg\"},
    {\"question_id\":$QV,\"value\":\"clip.mp4\",\"media\":\"$VID\"}
  ]}")
echo "  $R"
RID=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('response_id') or '')")
[ -n "$RID" ] || fail "réponse non créée: $R"
CNT=$($MYSQL "SELECT COUNT(*) FROM response_media WHERE response_id=$RID")
[ "$CNT" = "2" ] || fail "attendu 2 médias inline, trouvé $CNT"
[ "$($MYSQL "SELECT mime FROM response_media WHERE response_id=$RID AND question_id=$QA")" = "audio/mpeg" ] || fail "mime audio KO"
pass "réponse $RID + 2 médias (audio+vidéo) rattachés"

echo
echo "== 6. GET /responses/$RID → médias relus"
curl -s "$BASE/responses/$RID" -H "Authorization: Bearer $TOK" | python3 -c "
import sys,json
d=json.load(sys.stdin)
mids={m['question_id'] for m in d['media']}
assert $QA in mids and $QV in mids, d['media']
print('  OK 2 médias dans le détail')
"

echo
echo "== 7. Média inline invalide → ignoré, réponse quand même créée"
R=$(curl -s -X POST "$BASE/responses" -H 'Content-Type: application/json' -d "{
  \"form_id\":$F,\"user_id\":null,
  \"answers\":[{\"question_id\":$QA,\"value\":\"x\",\"media\":\"pas-une-data-uri\"}]}")
RID2=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('response_id') or '')")
[ -n "$RID2" ] || fail "réponse refusée à cause d'un média invalide: $R"
[ "$($MYSQL "SELECT COUNT(*) FROM response_media WHERE response_id=$RID2")" = "0" ] || fail "média invalide stocké"
pass "média invalide ignoré (best-effort), réponse $RID2 OK"

echo
echo "== 8. Parcours mobile : POST /responses/$RID/media (mime audio)"
R=$(curl -s -X POST "$BASE/responses/$RID/media" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"question_id\":$QV,\"data\":\"$VID\",\"mime\":\"video/mp4\"}")
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "upload média mobile KO: $R"
pass "endpoint média mobile inchangé, accepte audio/vidéo"

echo
echo "== 9. PUT /questions/$QA : media_max_duration_s=null puis type=short_text"
curl -s -X PUT "$BASE/questions/$QA" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d "{\"id\":$QA,\"form_id\":$F,\"type\":\"audio\",\"label\":\"Verbatim\",\"position\":0,\"media_max_duration_s\":null}" >/dev/null
[ "$($MYSQL "SELECT IFNULL(media_max_duration_s,'NULL') FROM questions WHERE id=$QA")" = "NULL" ] || fail "durée non remise à NULL"
pass "media_max_duration_s remis à NULL"

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$F" -H "Authorization: Bearer $TOK" >/dev/null
$MYSQL "DELETE FROM users WHERE id=$USERID"

echo
echo "TOUS LES TESTS B5 PASSENT ✅"
