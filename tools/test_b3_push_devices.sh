#!/usr/bin/env bash
# B3 — Notifications push : device_tokens + /me/devices + handshake FCM HTTP v1.
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
    -d "{\"first_name\":\"$1\",\"last_name\":\"T\",\"email\":\"b3-$1-$(date +%s%N)@kbforms.local\",\"password\":\"secret123\",\"account_type\":\"individual\"}")
  echo "$(echo "$r" | J "['token']") $(echo "$r" | J "['user_id']")"
}

echo "== setup"
read -r SUP_TOK SUP_ID <<< "$(reg sup)"
read -r COL_TOK COL_ID <<< "$(reg col)"
TOK_A="fake-token-A-$(date +%s%N)"
TOK_B="fake-token-B-$(date +%s%N)"
echo "  sup=$SUP_ID  col=$COL_ID"

echo
echo "== 1. POST /me/devices (col) → success, push_enabled reflète la config"
R=$(curl -s -X POST "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' \
  -d "{\"fcm_token\":\"$TOK_A\",\"platform\":\"android\"}")
echo "  $R"
[ "$(echo "$R" | J "['success']")" = "True" ] || fail "register KO"
[ "$(echo "$R" | J "['registered']")" = "True" ] || fail "registered != true"
PUSH_ENABLED=$(echo "$R" | J "['push_enabled']")
echo "  push_enabled=$PUSH_ENABLED"
pass "device enregistré"

echo
echo "== 2. POST /me/devices sans fcm_token → 400 ; sans auth → 401"
C=$(code -X POST "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d '{}')
[ "$C" = "400" ] || fail "token manquant accepté (HTTP $C)"
C=$(code -X POST "$BASE/me/devices" -H 'Content-Type: application/json' -d "{\"fcm_token\":\"x\"}")
[ "$C" = "401" ] || fail "pas de 401 sans auth (HTTP $C)"
pass "400 / 401"

echo
echo "== 3. Ré-enregistrement du même jeton par un autre compte → réattribution"
curl -s -X POST "$BASE/me/devices" -H "Authorization: Bearer $SUP_TOK" -H 'Content-Type: application/json' \
  -d "{\"fcm_token\":\"$TOK_A\"}" >/dev/null
OWNER=$($MYSQL "SELECT user_id FROM device_tokens WHERE token='$TOK_A'")
ROWS=$($MYSQL "SELECT COUNT(*) FROM device_tokens WHERE token='$TOK_A'")
[ "$ROWS" = "1" ] && [ "$OWNER" = "$SUP_ID" ] || fail "réattribution KO (rows=$ROWS owner=$OWNER)"
pass "jeton réattribué à sup, 1 seule ligne"
# on le remet à col pour la suite
curl -s -X POST "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d "{\"fcm_token\":\"$TOK_A\"}" >/dev/null

echo
echo "== 4. DELETE /me/devices — mauvais jeton → removed:false ; bon → removed:true"
R=$(curl -s -X DELETE "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d "{\"fcm_token\":\"$TOK_B\"}")
[ "$(echo "$R" | J "['removed']")" = "False" ] || fail "removed devrait être false: $R"
R=$(curl -s -X DELETE "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d "{\"fcm_token\":\"$TOK_A\"}")
[ "$(echo "$R" | J "['removed']")" = "True" ] || fail "removed devrait être true: $R"
[ "$($MYSQL "SELECT COUNT(*) FROM device_tokens WHERE token='$TOK_A'")" = "0" ] || fail "jeton pas supprimé"
pass "delete OK"

echo
echo "== 5. Handshake FCM (JWT RS256 → jeton OAuth2) avec la vraie clé de service"
php -r '
require "'"$DIR"'/../app/src/Core/Database.php";
require "'"$DIR"'/../app/src/Modules/Notification/Models/DeviceTokenModel.php";
require "'"$DIR"'/../app/src/Modules/Notification/Services/PushService.php";
$p = new Modules\Notification\Services\PushService();
if (!$p->isConfigured()) { fwrite(STDERR, "SKIP: FCM non configuré (app/config/fcm.php absent)\n"); exit(2); }
$m = new ReflectionMethod($p, "accessToken");
$m->setAccessible(true);
$t = $m->invoke($p);
if (is_string($t) && strlen($t) > 20) { echo "  jeton OAuth2 obtenu (".strlen($t)." car)\n"; exit(0); }
fwrite(STDERR, "  ÉCHEC: pas de jeton OAuth2 — clé de service invalide ?\n"); exit(1);
' && pass "handshake FCM OK" || { rc=$?; [ $rc = 2 ] && echo "  ⏭️  ignoré (FCM non configuré)" || fail "handshake FCM KO"; }

echo
echo "== 6. Envoi réel à un jeton bidon → FCM répond INVALID_ARGUMENT, jeton purgé"
if php -r '$c=@include "'"$DIR"'/../app/config/fcm.php"; exit(is_array($c)?0:2);'; then
  curl -s -X POST "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d "{\"fcm_token\":\"$TOK_B\"}" >/dev/null
  php -r '
  require "'"$DIR"'/../app/src/Core/Database.php";
  require "'"$DIR"'/../app/src/Modules/Notification/Models/DeviceTokenModel.php";
  require "'"$DIR"'/../app/src/Modules/Notification/Services/PushService.php";
  (new Modules\Notification\Services\PushService())->sendToUser('"$COL_ID"', "Test", "B3", ["type"=>"test"]);
  '
  LEFT=$($MYSQL "SELECT COUNT(*) FROM device_tokens WHERE token='$TOK_B'")
  [ "$LEFT" = "0" ] || fail "jeton bidon non purgé après rejet FCM ($LEFT)"
  pass "jeton bidon purgé après rejet FCM (chemin d'envoi + gestion d'erreur OK)"
else
  echo "  ⏭️  ignoré (FCM non configuré)"
fi

echo
echo "== 7. FK cascade : suppression de l'utilisateur purge ses jetons"
curl -s -X POST "$BASE/me/devices" -H "Authorization: Bearer $COL_TOK" -H 'Content-Type: application/json' -d "{\"fcm_token\":\"cascade-$COL_ID\"}" >/dev/null
$MYSQL "DELETE FROM users WHERE id=$COL_ID"
[ "$($MYSQL "SELECT COUNT(*) FROM device_tokens WHERE user_id=$COL_ID")" = "0" ] || fail "jetons orphelins après suppression user"
pass "cascade OK"

echo
echo "== cleanup"
$MYSQL "DELETE FROM users WHERE id IN ($SUP_ID,$COL_ID)"

echo
echo "TOUS LES TESTS B3 PASSENT ✅"
