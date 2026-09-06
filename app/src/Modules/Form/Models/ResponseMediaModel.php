<?php
namespace Modules\Form\Models;

use Core\Database;
use PDO;

/**
 * Pièces jointes (photos) d'une réponse — collecte mobile, M1.5.
 * Stockage en base (data URI base64), comme banner_data/image_data/avatar_data
 * existants : pas de dossier d'upload à configurer côté serveur.
 */
class ResponseMediaModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function create(
        int $responseId,
        int $questionId,
        ?string $mime,
        ?string $sha256,
        ?int $sizeBytes,
        string $data
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO response_media (response_id, question_id, mime, sha256, size_bytes, data)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$responseId, $questionId, $mime, $sha256, $sizeBytes, $data]);
        return (int) $this->db->lastInsertId();
    }

    /** Métadonnées + contenu — pour l'écran de consultation d'une réponse. */
    public function listForResponse(int $responseId): array {
        $stmt = $this->db->prepare("
            SELECT id, question_id, mime, sha256, size_bytes, data, created_at
            FROM response_media WHERE response_id = ? ORDER BY id ASC
        ");
        $stmt->execute([$responseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
