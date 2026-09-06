<?php
namespace Modules\Webhook\Models;

use Core\Database;
use PDO;

class WebhookModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(int $formId, string $url, ?string $secret): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO webhooks (form_id, url, secret, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$formId, $url, $secret]);
        return (int) $this->db->lastInsertId();
    }

    public function getByForm(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, form_id, url, secret, is_active, created_at
            FROM webhooks WHERE form_id = ? ORDER BY id ASC
        ");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, form_id, url, secret, is_active, created_at
            FROM webhooks WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getActiveByForm(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, url, secret FROM webhooks
            WHERE form_id = ? AND is_active = 1
        ");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $webhookId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM webhooks WHERE id = ?");
        return $stmt->execute([$webhookId]);
    }

    public function setActive(int $webhookId, bool $active): bool
    {
        $stmt = $this->db->prepare("UPDATE webhooks SET is_active = ? WHERE id = ?");
        return $stmt->execute([(int)$active, $webhookId]);
    }
}
