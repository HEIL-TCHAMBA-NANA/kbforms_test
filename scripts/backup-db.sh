#!/usr/bin/env bash
# KBForms — sauvegarde de la base (mysqldump gzippé, horodaté).
# Config lue depuis app/config/database.php ou variables d'env KBF_DB_*.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${KBF_BACKUP_DIR:-$ROOT/backups}"
KEEP="${KBF_BACKUP_KEEP:-14}"          # nombre de sauvegardes à conserver

DB_NAME="${KBF_DB_NAME:-kbforms}"
DB_USER="${KBF_DB_USER:-kbforms}"
DB_PASS="${KBF_DB_PASS:-K&Bgroup237*}"
DB_SOCK="${KBF_DB_SOCKET:-/opt/lampp/var/mysql/mysql.sock}"
MYSQLDUMP="${KBF_MYSQLDUMP:-/opt/lampp/bin/mysqldump}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y-%m-%d_%H%M%S)"
OUT="$BACKUP_DIR/kbforms_${STAMP}.sql.gz"

CONN=(-u "$DB_USER" "-p$DB_PASS")
[ -S "$DB_SOCK" ] && CONN+=(-S "$DB_SOCK") || CONN+=(-h 127.0.0.1)

"$MYSQLDUMP" "${CONN[@]}" \
  --single-transaction --quick --skip-triggers \
  --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "$OUT"

SIZE="$(du -h "$OUT" | cut -f1)"
echo "OK  $OUT  ($SIZE)"

# rotation : garder les $KEEP plus récents
mapfile -t OLD < <(ls -1t "$BACKUP_DIR"/kbforms_*.sql.gz 2>/dev/null | tail -n +$((KEEP+1)) || true)
for f in "${OLD[@]:-}"; do [ -n "$f" ] && rm -f "$f" && echo "purge $f"; done
