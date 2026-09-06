<?php
namespace Modules\RepeatGroup\Services;

use Modules\RepeatGroup\Models\RepeatGroupModel;
use Modules\Form\Models\FormModel;
use Modules\Collaboration\Models\CollaborationModel;

/**
 * Groupes de questions répétables. Édition réservée au propriétaire ou à un
 * collaborateur editor / admin (comme la structure du formulaire).
 */
class RepeatGroupService
{
    private RepeatGroupModel $model;
    private const EDITOR_ROLES = ['editor', 'admin'];

    public function __construct()
    {
        $this->model = new RepeatGroupModel();
    }

    public function listForForm(int $formId, int $userId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canView($form, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé.'];
        }
        return ['success' => true, 'repeat_groups' => $this->model->listForForm($formId)];
    }

    public function create(int $formId, array $data, int $userId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Édition réservée au propriétaire ou à un éditeur.'];
        }
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return ['success' => false, 'error' => 'label requis.'];
        }
        [$min, $max, $err] = $this->normalizeBounds($data);
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        $id = $this->model->create(
            $formId,
            (int) ($data['section_index'] ?? 0),
            $label,
            $min,
            $max,
            (int) ($data['position'] ?? 0)
        );
        return ['success' => true, 'group_id' => $id];
    }

    public function update(int $groupId, array $data, int $userId): array
    {
        [$ok, $err] = $this->guard($groupId, $userId);
        if (!$ok) {
            return $err;
        }
        $fields = [];
        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                return ['success' => false, 'error' => 'label ne peut pas être vide.'];
            }
            $fields['label'] = $label;
        }
        if (array_key_exists('section_index', $data)) {
            $fields['section_index'] = (int) $data['section_index'];
        }
        if (array_key_exists('position', $data)) {
            $fields['position'] = (int) $data['position'];
        }
        if (array_key_exists('min_repeat', $data) || array_key_exists('max_repeat', $data)) {
            [$min, $max, $bErr] = $this->normalizeBounds($data);
            if ($bErr) {
                return ['success' => false, 'error' => $bErr];
            }
            if (array_key_exists('min_repeat', $data)) {
                $fields['min_repeat'] = $min;
            }
            if (array_key_exists('max_repeat', $data)) {
                $fields['max_repeat'] = $max;
            }
        }
        $this->model->update($groupId, $fields);
        return ['success' => true, 'group_id' => $groupId];
    }

    public function delete(int $groupId, int $userId): array
    {
        [$ok, $err] = $this->guard($groupId, $userId);
        if (!$ok) {
            return $err;
        }
        // FK ON DELETE SET NULL : les questions membres redeviennent normales.
        return ['success' => true, 'removed' => $this->model->delete($groupId)];
    }

    /** Pour le bundle / formulaire public. */
    public function groupsForForm(int $formId): array
    {
        return $this->model->listForForm($formId);
    }

    /**
     * Contrôle min/max occurrences d'une soumission.
     * $answers : tableau d'answers {question_id, repeat_index?}.
     * @return array|null null si OK, sinon un résultat d'erreur.
     */
    public function validateSubmission(int $formId, array $answers): ?array
    {
        $groups = $this->model->listForForm($formId);
        if (!$groups) {
            return null;
        }
        foreach ($groups as $g) {
            $memberIds = array_flip($g['question_ids']);
            if (!$memberIds) {
                continue;
            }
            $indices = [];
            foreach ($answers as $a) {
                $qid = (int) ($a['question_id'] ?? 0);
                if (isset($memberIds[$qid]) && isset($a['repeat_index']) && $a['repeat_index'] !== null && $a['repeat_index'] !== '') {
                    $indices[(int) $a['repeat_index']] = true;
                }
            }
            $count = count($indices);
            if ($g['min_repeat'] > 0 && $count < $g['min_repeat']) {
                return [
                    'success' => false,
                    'code'    => 'repeat_min',
                    'error'   => "« {$g['label']} » : {$g['min_repeat']} occurrence(s) minimum requise(s) ({$count} fournie(s)).",
                ];
            }
            if ($g['max_repeat'] !== null && $count > $g['max_repeat']) {
                return [
                    'success' => false,
                    'code'    => 'repeat_max',
                    'error'   => "« {$g['label']} » : {$g['max_repeat']} occurrence(s) maximum ({$count} fournie(s)).",
                ];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    private function normalizeBounds(array $data): array
    {
        $min = array_key_exists('min_repeat', $data) && $data['min_repeat'] !== null && $data['min_repeat'] !== ''
            ? (int) $data['min_repeat'] : 0;
        $max = array_key_exists('max_repeat', $data) && $data['max_repeat'] !== null && $data['max_repeat'] !== ''
            ? (int) $data['max_repeat'] : null;
        if ($min < 0) {
            return [0, null, 'min_repeat ne peut pas être négatif.'];
        }
        if ($max !== null && $max < 1) {
            return [0, null, 'max_repeat doit valoir au moins 1.'];
        }
        if ($max !== null && $max < $min) {
            return [0, null, 'max_repeat doit être ≥ min_repeat.'];
        }
        return [$min, $max, null];
    }

    /** @return array{0:bool,1:array} */
    private function guard(int $groupId, int $userId): array
    {
        $g = $this->model->get($groupId);
        if (!$g) {
            return [false, ['success' => false, 'code' => 'not_found', 'error' => 'Groupe introuvable.']];
        }
        $form = (new FormModel())->getFormById((int) $g['form_id']);
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
        return in_array((new CollaborationModel())->getRole((int) $form['id'], $userId), self::EDITOR_ROLES, true);
    }

    private function canView(array $form, int $userId): bool
    {
        if ((int) ($form['user_id'] ?? 0) === $userId) {
            return true;
        }
        return (new CollaborationModel())->getRole((int) $form['id'], $userId) !== null;
    }
}
