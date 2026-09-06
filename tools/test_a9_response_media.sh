#!/usr/bin/env bash
# A9 — POST /responses/{id}/media + GET /responses/{id} (M1.5)
set -euo pipefail
BASE="${BASE:-http://localhost}"
SECRET="${KBF_CLIENT_SECRET:-$(php -r '$c=@include "'"$(dirname "$0")"'/../app/config/mobile.php"; echo is_array($c)?($c["client_secret"]??""):"";')}"
MYSQL="/opt/lampp/bin/mysql -u kbforms -pK&Bgroup237* kbforms -N -B -e"
py() { python3 -c "import sys,json;$1"; }

TS=$(date +%s)
reg() { curl -s -X POST "$BASE/register" -H 'Content-Type: application/json' -H "X-KBF-Client-Secret: $SECRET" \
  -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"$2\",\"password\":\"secret123\",\"account_type\":\"individual\"}"; }

OWN=$(reg Own "a9o-$TS@kbforms.local"); OTOK=$(echo "$OWN"|py "print(json.load(sys.stdin)['token'])"); OID=$(echo "$OWN"|py "print(json.load(sys.stdin)['user_id'])")
OTH=$(reg Oth "a9x-$TS@kbforms.local"); XTOK=$(echo "$OTH"|py "print(json.load(sys.stdin)['token'])")

FID=$(curl -s -X POST "$BASE/forms" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$OID,\"title\":\"A9\"}" | py "print(json.load(sys.stdin)['form_id'])")
QID=$(curl -s -X POST "$BASE/questions" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"form_id\":$FID,\"type\":\"short_text\",\"label\":\"Q\",\"required\":false,\"position\":0,\"section_index\":0}" | py "print(json.load(sys.stdin)['question_id'])")

# 1x1 png transparent en base64 (image de test minuscule)
PNG="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="

echo "== 1. Envoi d'une réponse (batch, pour récupérer le response_id serveur)"
UUID="a9-$(printf '%012d' $TS)"
R=$(curl -s -X POST "$BASE/responses" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"responses\":[{\"client_uuid\":\"$UUID\",\"form_id\":$FID,\"answers\":[{\"question_id\":$QID,\"value\":\"terrain\"}]}]}")
echo "$R" | python3 -m json.tool
RID=$(echo "$R" | py "print(json.load(sys.stdin)['results'][0]['response_id'])")
echo "  RID=$RID"

echo
echo "== 2. POST /responses/\$RID/media (propriétaire) → OK"
M=$(curl -s -X POST "$BASE/responses/$RID/media" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' \
  -d "{\"question_id\":$QID,\"mime\":\"image/png\",\"data\":\"$PNG\"}")
echo "$M" | python3 -m json.tool
echo "$M" | py "d=json.load(sys.stdin);assert d.get('success') and d.get('media_id'),d;print('  OK media_id=%s'%d['media_id'])"

echo
echo "== 3. GET /responses/\$RID (propriétaire) → answers + media (avec data)"
D=$(curl -s "$BASE/responses/$RID" -H "Authorization: Bearer $OTOK")
echo "$D" | python3 -c "
import sys,json
d=json.load(sys.stdin)
assert d['success'] is True
assert d['response']['id']==$RID
assert len(d['answers'])==1 and d['answers'][0]['value']=='terrain'
assert len(d['media'])==1
m=d['media'][0]
assert m['mime']=='image/png' and m['size_bytes']>0 and m['data'].startswith('data:image/png;base64,')
print('  OK réponse + 1 pièce jointe, taille=%s octets' % m['size_bytes'])
"

echo
echo "== 4. Un tiers non lié → 403 sur le détail et sur l'upload"
CODE=$(curl -s -o /tmp/a9_4a.json -w '%{http_code}' "$BASE/responses/$RID" -H "Authorization: Bearer $XTOK")
[ "$CODE" = "403" ] || { echo "ÉCHEC détail: $CODE"; cat /tmp/a9_4a.json; exit 1; }
CODE=$(curl -s -o /tmp/a9_4b.json -w '%{http_code}' -X POST "$BASE/responses/$RID/media" -H "Authorization: Bearer $XTOK" -H 'Content-Type: application/json' -d "{\"question_id\":$QID,\"data\":\"$PNG\"}")
[ "$CODE" = "403" ] || { echo "ÉCHEC upload: $CODE"; cat /tmp/a9_4b.json; exit 1; }
echo "  OK (403 pour les deux)"

echo
echo "== 5. data URI invalide → 400"
CODE=$(curl -s -o /tmp/a9_5.json -w '%{http_code}' -X POST "$BASE/responses/$RID/media" -H "Authorization: Bearer $OTOK" -H 'Content-Type: application/json' -d "{\"question_id\":$QID,\"data\":\"pas-une-data-uri\"}")
cat /tmp/a9_5.json; echo " [HTTP $CODE]"
[ "$CODE" = "400" ] || { echo ÉCHEC; exit 1; }

echo
curl -s -X DELETE "$BASE/forms/$FID" -H "Authorization: Bearer $OTOK" >/dev/null
$MYSQL "DELETE FROM users WHERE email IN ('a9o-$TS@kbforms.local','a9x-$TS@kbforms.local')"
echo "TOUS LES TESTS A9 PASSENT ✅"
