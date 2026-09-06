<?php
namespace Modules\LogicEngine\Services;

use Modules\LogicEngine\Models\ConditionModel;
use Modules\LogicEngine\Entities\Condition;

class LogicEngineService {
    private ConditionModel $model;

    public function __construct() {
        $this->model = new ConditionModel();
    }

    // ── CRUD conditions ───────────────────────────────────────────────────────

    public function addCondition(int $formId, int $sourceQuestionId, string $operator, string $value, int $targetSectionId): array {
        $allowed = ['eq', 'neq', 'contains'];
        if (!in_array($operator, $allowed, true)) {
            return ['success' => false, 'error' => "operator must be one of: " . implode(', ', $allowed)];
        }
        if (trim($value) === '') {
            return ['success' => false, 'error' => 'value cannot be empty'];
        }

        $id = $this->model->create($formId, $sourceQuestionId, $operator, $value, $targetSectionId);
        return ['success' => true, 'condition_id' => $id];
    }

    public function listConditions(int $formId): array {
        $rows = $this->model->getByForm($formId);
        return array_map(fn($r) => (new Condition($r))->toArray(), $rows);
    }

    public function deleteCondition(int $id): array {
        $ok = $this->model->delete($id);
        return ['success' => $ok, 'message' => $ok ? 'Condition deleted' : 'Condition not found'];
    }

    // ── Évaluation ────────────────────────────────────────────────────────────
    /**
     * Évalue toutes les conditions du formulaire face aux réponses fournies.
     *
     * @param int   $formId
     * @param array $answers  Map question_id (int) => valeur(s) soumise(s) (string|array)
     * @return array  ['visible_section_ids' => int[], 'triggered' => array[]]
     *
     * Logique :
     *  - Par défaut, TOUTES les sections sont visibles.
     *  - Dès qu'au moins une condition existe pour un target_section_id,
     *    cette section devient conditionnelle : elle n'est visible QUE si
     *    au moins une de ses conditions est satisfaite.
     */
    public function evaluate(int $formId, array $answers): array {
        $rows = $this->model->getByForm($formId);
        if (empty($rows)) {
            return ['visible_section_ids' => null, 'triggered' => []]; // aucune règle → tout visible
        }

        // Toutes les sections du formulaire. Celles qui ne portent AUCUNE
        // condition restent visibles en permanence (sinon on cache la section
        // qui contient la question déclenchante — impasse pour le répondant).
        $allSectionIds = array_map(
            static fn ($s) => (int) $s['id'],
            (new \Modules\Section\Models\SectionModel())->getByForm($formId)
        );
        $conditionalIds = array_values(array_unique(array_map(
            static fn ($r) => (int) $r['target_section_id'], $rows
        )));
        $visibleSectionIds = array_values(array_diff($allSectionIds, $conditionalIds));

        // Regroupe les conditions par section cible
        $bySection = [];
        foreach ($rows as $row) {
            $bySection[(int) $row['target_section_id']][] = new Condition($row);
        }

        $triggered = [];
        foreach ($bySection as $sectionId => $conditions) {
            foreach ($conditions as $cond) {
                $answer = $answers[$cond->sourceQuestionId] ?? null;
                if ($this->matches($cond, $answer)) {
                    $visibleSectionIds[] = $sectionId;
                    $triggered[]         = $cond->toArray();
                    break; // OR logic : une condition suffit
                }
            }
        }

        return [
            'visible_section_ids' => array_values(array_unique($visibleSectionIds)),
            'triggered'           => $triggered,
        ];
    }

    // ── Matching ──────────────────────────────────────────────────────────────

    private function matches(Condition $cond, mixed $answer): bool {
        if ($answer === null) return false;

        // Si la réponse est un tableau (checkbox), on teste chaque valeur
        $values = is_array($answer) ? $answer : [$answer];

        foreach ($values as $v) {
            $v = (string)$v;
            switch ($cond->operator) {
                case 'eq':
                    if (strtolower($v) === strtolower($cond->value)) return true;
                    break;
                case 'neq':
                    if (strtolower($v) !== strtolower($cond->value)) return true;
                    break;
                case 'contains':
                    if (str_contains(strtolower($v), strtolower($cond->value))) return true;
                    break;
            }
        }
        return false;
    }
}
