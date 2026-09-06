#!/usr/bin/env bash
# KBForms — restauration de la base depuis une sauvegarde .sql.gz
# Usage : scripts/restore-db.sh backups/kbforms_YYYY-MM-DD_HHMMSS.sql.gz
set -euo pipefail

FILE="${1:?Usage: restore-db.sh <fichier.sql.gz>}"
[ -f "$FILE" ] || { echo "Fichier introuvable : $FILE" >&2; exit 1; }

DB_NAME="${KBF_DB_NAME:-kbforms}"
DB_USER="${KBF_DB_USER:-kbforms}"
DB_PASS="${KBF_DB_PASS:-K&Bgroup237*}"
DB_SOCK="${KBF_DB_SOCKET:-/opt/lampp/var/mysql/mysql.sock}"
MYSQL="${KBF_MYSQL:-/opt/lampp/bin/mysql}"

CONN=(-u "$DB_USER" "-p$DB_PASS")
[ -S "$DB_SOCK" ] && CONN+=(-S "$DB_SOCK") || CONN+=(-h 127.0.0.1)

read -rp "⚠️  Écraser la base « $DB_NAME » avec $FILE ? (tape OUI) " ANS
[ "$ANS" = "OUI" ] || { echo "Annulé."; exit 0; }

gunzip -c "$FILE" | "$MYSQL" "${CONN[@]}" "$DB_NAME"
echo "Restauré depuis $FILE"
