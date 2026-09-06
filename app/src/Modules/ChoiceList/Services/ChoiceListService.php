<?php
namespace Modules\ChoiceList\Services;

use Modules\ChoiceList\Models\ChoiceListModel;
use Modules\Form\Models\FormModel;
use Modules\Collaboration\Models\CollaborationModel;

/**
 * Gestion des listes de choix en cascade.
 * Édition réservée au propriétaire du formulaire ou à un collaborateur
 * editor / admin (comme l'édition de structure d'un formulaire).
 */
class ChoiceListService
{
    private ChoiceListModel $model;

    private const EDITOR_ROLES = ['editor', 'admin'];

    public function __construct()
    {
        $this->model = new ChoiceListModel();
    }

    // ── Lecture ────────────────────────────────────────────────────────────
    public function listForForm(int $formId, int $userId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canView($form, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé.'];
        }
        return ['success' => true, 'choice_lists' => $this->model->listsForForm($formId)];
    }

    // ── Création de liste ─────────────────────────────────────────────────
    public function createList(int $formId, string $name, int $userId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Édition réservée au propriétaire ou à un éditeur du formulaire.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Le nom de la liste est requis.'];
        }
        $id = $this->model->createList($formId, $name);
        return ['success' => true, 'list_id' => $id, 'name' => $name];
    }

    public function renameList(int $listId, string $name, int $userId): array
    {
        [$ok, $err] = $this->guardList($listId, $userId);
        if (!$ok) {
            return $err;
        }
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Nom requis.'];
        }
        $this->model->renameList($listId, $name);
        return ['success' => true, 'list_id' => $listId, 'name' => $name];
    }

    public function deleteList(int $listId, int $userId): array
    {
        [$ok, $err] = $this->guardList($listId, $userId);
        if (!$ok) {
            return $err;
        }
        return ['success' => true, 'removed' => $this->model->deleteList($listId)];
    }

    // ── Items ─────────────────────────────────────────────────────────────
    public function addItem(int $listId, array $data, int $userId): array
    {
        [$ok, $err] = $this->guardList($listId, $userId);
        if (!$ok) {
            return $err;
        }
        $label = trim((string) ($data['label'] ?? ''));
        $value = trim((string) ($data['value'] ?? $label));
        if ($label === '') {
            return ['success' => false, 'error' => 'label requis.'];
        }
        $parentId = isset($data['parent_item_id']) && $data['parent_item_id'] !== null
            ? (int) $data['parent_item_id'] : null;
        if ($parentId !== null && !$this->model->itemBelongsToList($parentId, $listId)) {
            return ['success' => false, 'error' => 'parent_item_id n\'appartient pas à cette liste.'];
        }
        $position = (int) ($data['position'] ?? 0);
        $id = $this->model->addItem($listId, $label, $value, $parentId, $position);
        return ['success' => true, 'item_id' => $id];
    }

    public function deleteItem(int $itemId, int $listId, int $userId): array
    {
        [$ok, $err] = $this->guardList($listId, $userId);
        if (!$ok) {
            return $err;
        }
        if (!$this->model->itemBelongsToList($itemId, $listId)) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Item introuvable dans cette liste.'];
        }
        return ['success' => true, 'removed' => $this->model->deleteItem($itemId)];
    }

    public function importRows(int $listId, array $rows, int $userId): array
    {
        [$ok, $err] = $this->guardList($listId, $userId);
        if (!$ok) {
            return $err;
        }
        if (!$rows) {
            return ['success' => false, 'error' => 'rows vide.'];
        }
        $count = $this->model->importRows($listId, $rows);
        return ['success' => true, 'list_id' => $listId, 'items_created' => $count];
    }

    // ── Pour le bundle (mobile) ──────────────────────────────────────────
    public function listsUsedByForm(int $formId): array
    {
        $ids = $this->model->listIdsUsedByForm($formId);
        return $this->model->listsByIds($ids);
    }

    // ─────────────────────────────────────────────────────────────────────
    /** @return array{0:bool,1:array} */
    private function guardList(int $listId, int $userId): array
    {
        $list = $this->model->getList($listId);
        if (!$list) {
            return [false, ['success' => false, 'code' => 'not_found', 'error' => 'Liste introuvable.']];
        }
        $form = (new FormModel())->getFormById((int) $list['form_id']);
        if (!$form || !$this->canManage($form, $userId)) {
            return [false, ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé.']];
        }
        return [true, []];
    }

    private function canManage(array $form, int $userId): bool
    {
        if ((int) ($form['user_id'] ?? 0) === $userId) {
            return true;
        }
        return in_array(
            (new CollaborationModel())->getRole((int) $form['id'], $userId),
            self::EDITOR_ROLES,
            true
        );
    }

    private function canView(array $form, int $userId): bool
    {
        if ((int) ($form['user_id'] ?? 0) === $userId) {
            return true;
        }
        return (new CollaborationModel())->getRole((int) $form['id'], $userId) !== null;
    }
}
