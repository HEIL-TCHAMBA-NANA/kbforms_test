<?php
namespace Modules\GoogleSheets\Models;

use Core\Database;
use PDO;

class GoogleSheetsModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ── Lecture ───────────────────────────────────────────────────────────────

    public function getByForm(int $formId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, form_id, spreadsheet_id, spreadsheet_title, sheet_name,
                   access_token, refresh_token, token_expires_at,
                   auto_sync, last_synced_at, created_at, updated_at
            FROM form_google_sheets
            WHERE form_id = ?
        ");
        $stmt->execute([$formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── Création / mise à jour ────────────────────────────────────────────────

    public function upsert(int $formId, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = [
            'spreadsheet_id', 'spreadsheet_title', 'sheet_name',
            'access_token', 'refresh_token', 'token_expires_at',
            'auto_sync', 'last_synced_at',
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = $field;
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) return false;

        $placeholders  = implode(', ', array_fill(0, count($fields), '?'));
        $columnList    = implode(', ', $fields);
        $updateClauses = implode(', ', array_map(fn($f) => "$f = VALUES($f)", $fields));

        $stmt = $this->db->prepare("
            INSERT INTO form_google_sheets (form_id, $columnList)
            VALUES (?, $placeholders)
            ON DUPLICATE KEY UPDATE $updateClauses, updated_at = NOW()
        ");

        return $stmt->execute([$formId, ...$values]);
    }

    // ── Suppression ───────────────────────────────────────────────────────────

    public function deleteByForm(int $formId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM form_google_sheets WHERE form_id = ?");
        return $stmt->execute([$formId]);
    }

    // ── Mise à jour dernière sync ─────────────────────────────────────────────

    public function touchSyncDate(int $formId): void
    {
        $stmt = $this->db->prepare("
            UPDATE form_google_sheets SET last_synced_at = NOW() WHERE form_id = ?
        ");
        $stmt->execute([$formId]);
    }
}