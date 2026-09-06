<?php
namespace Modules\Assignment\Services;

use Modules\Assignment\Models\AssignmentModel;
use Modules\Form\Models\FormModel;
use Modules\Identity\Models\UserModel;
use Modules\Collaboration\Models\CollaborationModel;

/**
 * Règles métier de l'assignation d'enquêtes.
 *  - Assigner / retirer : réservé au propriétaire du formulaire ou à un
 *    collaborateur « admin » (le « superviseur »).
 *  - Changer le statut : l'enquêteur assigné, ou un superviseur.
 */
class AssignmentService
{
    private AssignmentModel $model;

    public function __construct()
    {
        $this->model = new AssignmentModel();
    }

    // ── Superviseur : assigner ───────────────────────────────────────────────
    public function assign(int $formId, int $toUserId, ?string $note, int $managerId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $managerId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Seul le propriétaire ou un administrateur du formulaire peut assigner.'];
        }
        if ($toUserId <= 0) {
            return ['success' => false, 'error' => 'user_id requis.'];
        }
        $target = (new UserModel())->findById($toUserId);
        if (!$target) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Utilisateur cible introuvable.'];
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        $id = $this->model->assign($formId, $toUserId, $managerId, $note);

        // Notification push best-effort — n'interrompt jamais l'assignation.
        try {
            (new \Modules\Notification\Services\PushService())
                ->notifyAssignment($toUserId, (string) ($form['title'] ?? 'un formulaire'), $note);
        } catch (\Throwable $e) {
            error_log('[AssignmentService] notification push échouée : ' . $e->getMessage());
        }

        return [
            'success'       => true,
            'assignment_id' => $id,
            'form_id'       => $formId,
            'user_id'       => $toUserId,
            'status'        => 'pending',
            'message'       => 'Formulaire assigné à ' . ($target['email'] ?? ('#' . $toUserId)) . '.',
        ];
    }

    // ── Superviseur : retirer ───────────────────────────────────────────────
    public function unassign(int $formId, int $toUserId, int $managerId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $managerId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Action réservée au propriétaire ou à un administrateur du formulaire.'];
        }
        $removed = $this->model->unassign($formId, $toUserId);
        return ['success' => true, 'removed' => $removed];
    }

    // ── Superviseur : lister les assignations d'un formulaire ────────────────
    public function listForForm(int $formId, int $managerId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $managerId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé.'];
        }
        return ['success' => true, 'assignments' => $this->model->listForForm($formId)];
    }

    // ── Enquêteur : mes assignations ───────────────────────────────────────
    public function myAssignments(int $userId): array
    {
        return ['success' => true, 'assignments' => $this->model->listForUser($userId)];
    }

    // ── Changer le statut (enquêteur assigné ou superviseur) ────────────────
    public function setStatus(int $assignmentId, string $status, int $userId): array
    {
        if (!in_array($status, AssignmentModel::VALID_STATUSES, true)) {
            return ['success' => false, 'error' => 'Statut invalide (pending | in_progress | done).'];
        }
        $a = $this->model->findById($assignmentId);
        if (!$a) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Assignation introuvable.'];
        }

        $isAssignee = (int) $a['assigned_to_user_id'] === $userId;
        $isManager  = false;
        if (!$isAssignee) {
            $form = (new FormModel())->getFormById((int) $a['form_id']);
            $isManager = $form && $this->canManage($form, $userId);
        }
        if (!$isAssignee && !$isManager) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Vous ne pouvez pas modifier cette assignation.'];
        }

        $this->model->updateStatus($assignmentId, $status);
        return ['success' => true, 'assignment_id' => $assignmentId, 'status' => $status];
    }

    // ───────────────────────────────────────────────────────────────────────
    /** Propriétaire du formulaire ou collaborateur avec le rôle « admin ». */
    private function canManage(array $form, int $userId): bool
    {
        if ((int) ($form['user_id'] ?? 0) === $userId) {
            return true;
        }
        return (new CollaborationModel())->getRole((int) $form['id'], $userId) === 'admin';
    }
}
