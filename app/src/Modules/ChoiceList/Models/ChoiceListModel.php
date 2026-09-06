<?php
namespace Modules\ChoiceList\Models;

use Core\Database;
use PDO;

/**
 * Listes de choix hiérarchiques (cascade) : choice_lists + choice_list_items.
 * Un item de niveau N pointe vers son parent (item de niveau N-1) ; racine =
 * parent_item_id NULL.
 */
class ChoiceListModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ── Listes ─────────────────────────────────────────────────────────────
    public function createList(int $formId, string $name): int
    {
        $stmt = $this->db->prepare("INSERT INTO choice_lists (form_id, name) VALUES (?, ?)");
        $stmt->execute([$formId, $name]);
        return (int) $this->db->lastInsertId();
    }

    public function renameList(int $listId, string $name): bool
    {
        $stmt = $this->db->prepare("UPDATE choice_lists SET name = ? WHERE id = ?");
        $stmt->execute([$name, $listId]);
        return $stmt->rowCount() >= 0;
    }

    public function getList(int $listId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM choice_lists WHERE id = ?");
        $stmt->execute([$listId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteList(int $listId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM choice_lists WHERE id = ?");
        $stmt->execute([$listId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array liste des listes du formulaire, chacune avec ses items à plat */
    public function listsForForm(int $formId): array
    {
        $stmt = $this->db->prepare("SELECT id, name, created_at FROM choice_lists WHERE form_id = ? ORDER BY id ASC");
        $stmt->execute([$formId]);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lists as &$l) {
            $l['id']    = (int) $l['id'];
            $l['items'] = $this->itemsForList($l['id']);
        }
        return $lists;
    }

    /** Listes désignées par un ensemble d'ids (pour le bundle). */
    public function listsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, name FROM choice_lists WHERE id IN ($ph) ORDER BY id ASC");
        $stmt->execute($ids);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lists as &$l) {
            $l['id']    = (int) $l['id'];
            $l['items'] = $this->itemsForList($l['id']);
        }
        return $lists;
    }

    // ── Items ──────────────────────────────────────────────────────────────
    public function itemsForList(int $listId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, parent_item_id, label, value, position
            FROM choice_list_items WHERE list_id = ?
            ORDER BY (parent_item_id IS NOT NULL), parent_item_id, position, id
        ");
        $stmt->execute([$listId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['parent_item_id'] = $r['parent_item_id'] !== null ? (int) $r['parent_item_id'] : null;
            $r['position']       = (int) $r['position'];
        }
        return $rows;
    }

    public function addItem(int $listId, string $label, string $value, ?int $parentItemId, int $position = 0): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO choice_list_items (list_id, parent_item_id, label, value, position)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$listId, $parentItemId, $label, $value, $position]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteItem(int $itemId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM choice_list_items WHERE id = ?");
        $stmt->execute([$itemId]);
        return $stmt->rowCount() > 0;
    }

    public function itemBelongsToList(int $itemId, int $listId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM choice_list_items WHERE id = ? AND list_id = ?");
        $stmt->execute([$itemId, $listId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Vide tous les items d'une liste (avant un import). */
    public function clearItems(int $listId): void
    {
        $stmt = $this->db->prepare("DELETE FROM choice_list_items WHERE list_id = ?");
        $stmt->execute([$listId]);
    }

    /**
     * Import en masse : $rows = [ ["Région A","Dép 1","Commune X"], ... ].
     * Construit l'arborescence, dédoublonne par (parent, label). remplace tout.
     * @return int nombre d'items créés
     */
    public function importRows(int $listId, array $rows): int
    {
        $this->clearItems($listId);
        $created = 0;
        $cache = []; // "parentId|label" => itemId   (parentId 0 = racine)
        $posByParent = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parentId = null;
            foreach ($row as $cell) {
                $label = trim((string) $cell);
                if ($label === '') {
                    continue;
                }
                $key = ($parentId ?? 0) . '|' . mb_strtolower($label);
                if (!isset($cache[$key])) {
                    $pk = $parentId ?? 0;
                    $pos = $posByParent[$pk] = ($posByParent[$pk] ?? -1) + 1;
                    $cache[$key] = $this->addItem($listId, $label, $label, $parentId, $pos);
                    $created++;
                }
                $parentId = $cache[$key];
            }
        }
        return $created;
    }

    /** Ids de listes réellement utilisées par des questions du formulaire. */
    public function listIdsUsedByForm(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT cascade_list_id FROM questions
            WHERE form_id = ? AND cascade_list_id IS NOT NULL
        ");
        $stmt->execute([$formId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
