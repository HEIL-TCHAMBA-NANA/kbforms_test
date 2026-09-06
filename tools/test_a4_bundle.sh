#!/usr/bin/env bash
# A4 — GET /forms/{id}/bundle : form + sections + questions + conditions + theme + version
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* kbforms -N -B -e"
py() { python3 -c "import sys,json;$1"; }

reg() { curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"$2\",\"password\":\"secret123\",\"account_type\":\"individual\"}"; }

TS=$(date +%s)
OWN=$(reg Own "a4o-$TS@kbforms.local"); OTOK=$(echo "$OWN"|py "print(json.load(sys.stdin)['token'])"); OID=$(echo "$OWN"|py "print(json.load(sys.stdin)['user_id'])")
STR=$(reg Str "a4s-$TS@kbforms.local"); STOK=$(echo "$STR"|py "print(json.load(sys.stdin)['token'])")

FID=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OID,\"title\":\"A4 bundle\",\"description\":\"desc\"}" | py "print(json.load(sys.stdin)['form_id'])")
SID=$(curl -s -X POST "$BASE/forms/$FID/sections" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d '{"title":"S1","position":0}' | py "print(json.load(sys.stdin)['section_id'])")
Q1=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"type\":\"radio\",\"label\":\"Q1\",\"required\":true,\"position\":0,\"section_index\":0,\"options\":[\"a\",\"b\"]}" | py "print(json.load(sys.stdin)['question_id'])")
Q2=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"type\":\"short_text\",\"label\":\"Q2\",\"required\":false,\"position\":1,\"section_index\":0}" | py "print(json.load(sys.stdin)['question_id'])")
curl -s -X POST "$BASE/forms/$FID/conditions" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"source_question_id\":$Q1,\"operator\":\"eq\",\"value\":\"a\",\"target_section_id\":$SID}" >/dev/null
curl -s -X PATCH "$BASE/forms/$FID/theme" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d '{"theme_color":"#10B981","font_family":"Roboto"}' >/dev/null

echo "== 1. GET /forms/$FID/bundle (owner) → 6 clés + contenu"
B=$(curl -s "$BASE/forms/$FID/bundle" -H "Authorization: Bearer $OTOK")
echo "$B" | python3 -m json.tool
echo "$B" | py "
d=json.load(sys.stdin)
assert d['success'] is True
assert d['form']['id']==$FID and d['form']['title']=='A4 bundle'
assert len(d['sections'])==1 and d['sections'][0]['id']==$SID
assert len(d['questions'])==2
assert len(d['conditions'])==1 and d['conditions'][0]['operator']=='eq'
assert d['theme']['theme_color']=='#10B981' and d['theme']['font_family']=='Roboto'
assert isinstance(d['version'], int)
print('  OK : form, sections(1), questions(2), conditions(1), theme, version=%s' % d['version'])
"

echo
echo "== 2. GET bundle par un tiers → 403"
CODE=$(curl -s -o /tmp/a4_2.json -w '%{http_code}' "$BASE/forms/$FID/bundle" -H "Authorization: Bearer $STOK")
cat /tmp/a4_2.json; echo " [HTTP $CODE]"; [ "$CODE" = "403" ] || { echo ÉCHEC; exit 1; }

echo
echo "== 3. GET bundle sans token → 401"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/forms/$FID/bundle")
echo "  HTTP $CODE"; [ "$CODE" = "401" ] || { echo ÉCHEC; exit 1; }

echo
echo "== 4. GET bundle d'un id inexistant → 404"
CODE=$(curl -s -o /tmp/a4_4.json -w '%{http_code}' "$BASE/forms/99999999/bundle" -H "Authorization: Bearer $OTOK")
cat /tmp/a4_4.json; echo " [HTTP $CODE]"; [ "$CODE" = "403" ] || [ "$CODE" = "404" ] || { echo "ÉCHEC (attendu 403/404)"; exit 1; }

echo
echo "== cleanup"
curl -s -X DELETE "$BASE/forms/$FID" -H "Authorization: Bearer $OTOK" >/dev/null
$MYSQL "DELETE FROM users WHERE email LIKE 'a4%-$TS@kbforms.local'"

echo
echo "TOUS LES TESTS A4 PASSENT ✅"
