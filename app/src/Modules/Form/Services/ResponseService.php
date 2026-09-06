<?php
namespace Modules\Form\Services;

use Modules\Form\Models\ResponseModel;
use Modules\Webhook\Services\WebhookService;
use Modules\Form\Services\FormService;

class ResponseService {
    private ResponseModel   $model;
    private WebhookService  $webhook;

    public function __construct() {
        $this->model   = new ResponseModel();
        $this->webhook = new WebhookService();
    }

    /**
     * @param array $meta  métadonnées mobiles éventuelles : client_uuid,
     *   device_id, app_version, gps_lat/lng/accuracy, started_at, duration_s,
     *   mock_location, submitted_at (ISO — horodatage appareil).
     */
    public function submitResponse(int $formId, ?int $userId, array $answers, ?string $ipHash = null, array $meta = [], ?int $agentId = null): array {
        // ── Idempotence : un même client_uuid ne crée qu'une réponse ─────────
        $clientUuid = isset($meta['client_uuid']) ? trim((string) $meta['client_uuid']) : '';
        if ($clientUuid !== '') {
            $existingId = $this->model->findIdByClientUuid($clientUuid);
            if ($existingId !== null) {
                return [
                    "success"              => true,
                    "duplicate"            => true,
                    "message"              => "Response already recorded",
                    "confirmation_message" => (new FormService())->getConfirmationMessage($formId),
                    "response_id"          => $existingId,
                ];
            }
        }

        // ── B8 : bornes min/max des groupes de questions répétables ──────────
        $repeatError = (new \Modules\RepeatGroup\Services\RepeatGroupService())
            ->validateSubmission($formId, $answers);
        if ($repeatError !== null) {
            return $repeatError;
        }

        $responseId = $this->model->createResponse($formId, $userId, $ipHash, $meta, $agentId);

        foreach ($answers as $answer) {
            // ✅ FIX : normaliser la valeur en string
            // Les checkboxes envoient un array, les grilles un objet → JSON-encode
            $value = $answer["value"] ?? '';
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $value = (string)$value;
            }
            $repeatIndex = array_key_exists('repeat_index', $answer) && $answer['repeat_index'] !== null && $answer['repeat_index'] !== ''
                ? (int) $answer['repeat_index'] : null;
            $this->model->addAnswer(
                $responseId,
                (int)$answer["question_id"],
                $value,
                $repeatIndex
            );

            // B4/B5 : média joint inline (signature / audio / vidéo) sur un
            // formulaire public — pas d'appel /responses/{id}/media séparé.
            if (!empty($answer['media']) && is_string($answer['media'])) {
                try {
                    $this->insertMedia(
                        $responseId,
                        (int)$answer["question_id"],
                        $answer['media'],
                        $answer['media_mime'] ?? null,
                        null
                    );
                } catch (\Throwable $e) {
                    error_log('[ResponseService] média inline ignoré: ' . $e->getMessage());
                }
            }
        }

        // ── US-015 : récupérer le message de confirmation ─────────────────────
        $formService = new FormService();
        $confirmationMessage = $formService->getConfirmationMessage($formId);

        // ── US-029 : dispatcher le webhook ────────────────────────────────────
        $this->webhook->dispatch($formId, 'response.submitted', [
            'response_id' => $responseId,
            'form_id'     => $formId,
            'user_id'     => $userId,
            'answers'     => $answers,
        ]);

        // ── Notification email (best-effort, ne bloque jamais la soumission) ──
        try {
            $this->notifyNewResponse($formId, $responseId, $answers);
        } catch (\Throwable $e) {
            error_log('[ResponseService] notification échouée: ' . $e->getMessage());
        }

        return [
            "success"              => true,
            "message"              => "Response submitted successfully",
            "confirmation_message" => $confirmationMessage,
            "response_id"          => $responseId,
        ];
    }

    private function notifyNewResponse(int $formId, int $responseId, array $answers): void {
        $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
        $raw  = trim((string) ($form['notify_emails'] ?? ''));
        if ($form === null || $raw === '') {
            return;
        }
        $recipients = array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim($e)),
            preg_split('/[,;\s]+/', $raw)
        ), static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (!$recipients) {
            return;
        }

        $title = $form['title'] ?: 'votre formulaire';
        $base  = \Core\AppUrl::base();
        $link  = $base . '/form-responses?id=' . $formId;

        // Aperçu des 5 premières réponses (label → valeur)
        $qLabels = [];
        foreach ((new \Modules\Form\Models\QuestionModel())->getQuestionsByForm($formId) as $q) {
            $qLabels[(int) $q['id']] = $q['label'];
        }
        $lines = [];
        foreach (array_slice($answers, 0, 5) as $a) {
            $lbl = $qLabels[(int) ($a['question_id'] ?? 0)] ?? 'Question';
            $val = $a['value'] ?? '';
            if (is_array($val) || is_object($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            $lines[] = '• ' . $lbl . ' : ' . mb_strimwidth((string) $val, 0, 120, '…');
        }
        $preview = implode("\n", $lines);

        $subject = "Nouvelle réponse — « {$title} »";
        $text = "Une nouvelle réponse (#{$responseId}) vient d'être soumise au formulaire « {$title} ».\n\n"
              . ($preview !== '' ? $preview . "\n\n" : '')
              . "Voir toutes les réponses :\n{$link}\n\n— KBForms";

        $safeTitle   = htmlspecialchars($title, ENT_QUOTES);
        $safeLink    = htmlspecialchars($link, ENT_QUOTES);
        $safePreview = nl2br(htmlspecialchars($preview, ENT_QUOTES));
        $html = '<div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937">'
              . "<p>Nouvelle réponse (#{$responseId}) au formulaire <strong>« {$safeTitle} »</strong>.</p>"
              . ($preview !== '' ? "<p style=\"background:#f3f4f6;border-radius:8px;padding:12px;font-size:13px\">{$safePreview}</p>" : '')
              . "<p style=\"margin-top:20px\"><a href=\"{$safeLink}\" style=\"background:#4F46E5;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;display:inline-block;font-weight:600\">Voir les réponses</a></p>"
              . '<p style="color:#6b7280;font-size:12px">Vous recevez cet email car cette adresse est dans les notifications de ce formulaire.</p></div>';

        $mail = new \Modules\Mail\MailService();
        foreach ($recipients as $to) {
            $mail->send($to, $subject, $text, $html);
        }
    }

    /**
     * Modifie les réponses d'une réponse existante (édition depuis form-responses.html).
     * $userId doit être propriétaire ou collaborateur du formulaire (ce
     * contrôle manquait jusqu'ici — n'importe quel jeton valide pouvait
     * réécrire n'importe quelle réponse).
     * Remplace TOUT le jeu de réponses : le client doit renvoyer les questions
     * non éditées (groupes répétables, médias, etc.) telles quelles.
     */
    public function updateResponse(int $responseId, int $userId, array $answers): array {
        $formId = $this->model->getFormId($responseId);
        if ($formId === null) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Réponse introuvable'];
        }
        if (!$this->userCanManage($formId, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }

        $this->model->deleteAnswers($responseId);
        $this->model->updateSubmittedAt($responseId);

        foreach ($answers as $answer) {
            $value = $answer["value"] ?? '';
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $value = (string) $value;
            }
            $repeatIndex = array_key_exists('repeat_index', $answer) && $answer['repeat_index'] !== null && $answer['repeat_index'] !== ''
                ? (int) $answer['repeat_index'] : null;
            $this->model->addAnswer(
                $responseId,
                (int) $answer["question_id"],
                $value,
                $repeatIndex
            );
        }

        return [
            "success"     => true,
            "message"     => "Response updated successfully",
            "response_id" => $responseId,
        ];
    }

    /** Supprime une réponse. $userId doit être propriétaire ou collaborateur. */
    public function deleteResponse(int $responseId, int $userId): array {
        $formId = $this->model->getFormId($responseId);
        if ($formId === null) {
            return ['success' => false, 'error' => 'Réponse introuvable'];
        }
        if (!$this->userCanManage($formId, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }
        $ok = $this->model->deleteResponse($responseId);
        if ($ok) $this->invalidateFormCache($formId);
        return ['success' => $ok, 'message' => $ok ? 'Réponse supprimée' : 'Échec de la suppression'];
    }

    /**
     * Purge les réponses au-delà de la durée de rétention.
     * $days null → utilise le réglage du formulaire ; 0/absent → rien.
     */
    public function purgeOldResponses(int $formId, int $userId, ?int $days = null): array {
        if (!$this->userCanManage($formId, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }
        if ($days === null) {
            $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
            $days = isset($form['response_retention_days']) ? (int) $form['response_retention_days'] : 0;
        }
        if ($days < 1) {
            return ['success' => false, 'error' => 'Aucune durée de rétention définie'];
        }
        $deleted = $this->model->deleteOlderThan($formId, $days);
        if ($deleted > 0) $this->invalidateFormCache($formId);
        return ['success' => true, 'deleted' => $deleted, 'retention_days' => $days];
    }

    /** Gérer les réponses = propriétaire OU collaborateur éditeur/admin (pas viewer). */
    private function userCanManage(int $formId, int $userId): bool {
        $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
        if ($form && (int) $form['user_id'] === $userId) {
            return true;
        }
        $role = (new \Modules\Collaboration\Services\CollaborationService())->getRole($formId, $userId);
        return in_array($role, ['editor', 'admin'], true);
    }

    private function invalidateFormCache(int $formId): void {
        try {
            $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
            if ($form && !empty($form['share_link'])
                && preg_match('#/f/([a-zA-Z0-9]+)$#', $form['share_link'], $m)) {
                (new \Modules\Cache\Services\CacheService())->invalidateForm($m[1]);
            }
        } catch (\Throwable $e) {}
    }

    public function getResponses(int $formId): array {
        $rows = $this->model->getResponsesByForm($formId);

        $responses = [];
        foreach ($rows as $row) {
            $rid = $row["response_id"];
            if (!isset($responses[$rid])) {
                $responses[$rid] = [
                    "response_id"  => $rid,
                    "user_id"      => $row["user_id"],
                    "submitted_at" => $row["submitted_at"],
                    "media_count"  => (int) ($row["media_count"] ?? 0),
                    "answers"      => [],
                ];
            }
            if ($row["question_id"] === null) {
                continue; // réponse sans aucune réponse enregistrée (LEFT JOIN)
            }
            $responses[$rid]["answers"][] = [
                "question_id"  => $row["question_id"],
                "value"        => $row["value"],
                "repeat_index" => $row["repeat_index"] !== null ? (int) $row["repeat_index"] : null,
            ];
        }

        return array_values($responses);
    }

    /** Le collecteur peut agir sur sa propre réponse, ou un gestionnaire du formulaire. */
    private function userCanAccessResponse(int $responseId, int $userId): ?int {
        $response = $this->model->getResponseById($responseId);
        if (!$response) return null;
        $formId = (int) $response['form_id'];
        if ((int) ($response['user_id'] ?? 0) === $userId || $this->userCanManage($formId, $userId)) {
            return $formId;
        }
        return -1; // existe mais accès refusé
    }

    // ── M1.5 — US mobile : consulter une réponse envoyée ──────────────────────
    public function getResponseDetail(int $responseId, int $userId): array {
        $access = $this->userCanAccessResponse($responseId, $userId);
        if ($access === null) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Réponse introuvable'];
        }
        if ($access === -1) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }

        $response = $this->model->getResponseById($responseId);
        $answers  = array_map(static fn (array $a) => [
            'question_id'  => (int) $a['question_id'],
            'value'        => $a['value'],
            'repeat_index' => $a['repeat_index'] !== null ? (int) $a['repeat_index'] : null,
        ], $this->model->getAnswersByResponse($responseId));
        $media    = (new \Modules\Form\Models\ResponseMediaModel())->listForResponse($responseId);

        return [
            'success'  => true,
            'response' => $response,
            'answers'  => $answers,
            'media'    => array_map(static fn (array $m) => [
                'id'          => (int) $m['id'],
                'question_id' => (int) $m['question_id'],
                'mime'        => $m['mime'],
                'sha256'      => $m['sha256'],
                'size_bytes'  => $m['size_bytes'] !== null ? (int) $m['size_bytes'] : null,
                'created_at'  => $m['created_at'],
                'data'        => $m['data'],
            ], $media),
        ];
    }

    // ── M1.5 — US mobile : joindre une photo à une réponse ─────────────────────
    /** Taille décodée max d'une pièce jointe (~8 Mo, cohérent avec MEDIUMTEXT). */
    private const MAX_MEDIA_BYTES = 8 * 1024 * 1024;

    public function uploadMedia(int $responseId, int $userId, array $data): array {
        $access = $this->userCanAccessResponse($responseId, $userId);
        if ($access === null) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Réponse introuvable'];
        }
        if ($access === -1) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }
        return $this->uploadMediaCommon($responseId, $data);
    }

    /**
     * M4 : upload média par l'agent de terrain qui a créé la réponse. Le
     * contrôle d'accès a lieu ici (agent_id de la réponse == agent du jeton) ;
     * une fois passé, même logique d'insertion que pour un compte normal.
     */
    public function uploadMediaForAgent(int $responseId, int $agentId, array $data): array {
        $response = $this->model->getResponseById($responseId);
        if (!$response) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Réponse introuvable'];
        }
        if ((int) ($response['agent_id'] ?? 0) !== $agentId) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }
        return $this->uploadMediaCommon($responseId, $data);
    }

    private function uploadMediaCommon(int $responseId, array $data): array {
        $questionId = (int) ($data['question_id'] ?? 0);
        $payload    = (string) ($data['data'] ?? '');
        if ($questionId <= 0 || $payload === '') {
            return ['success' => false, 'error' => 'question_id et data sont requis'];
        }
        return $this->insertMedia(
            $responseId,
            $questionId,
            $payload,
            isset($data['mime']) ? (string) $data['mime'] : null,
            isset($data['sha256']) ? (string) $data['sha256'] : null
        );
    }

    /**
     * Valide une data URI base64, la décode, la borne à MAX_MEDIA_BYTES et
     * crée la ligne response_media. Partagé par uploadMedia() (authentifié) et
     * submitResponse() (média joint inline à une soumission publique).
     */
    private function insertMedia(int $responseId, int $questionId, string $payload, ?string $mime, ?string $sha256): array
    {
        if (!preg_match('#^data:([a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $payload, $m)) {
            return ['success' => false, 'error' => 'data doit être une data URI base64'];
        }
        [, $mimeFromUri, $b64] = $m;
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            return ['success' => false, 'error' => 'Contenu base64 invalide'];
        }
        if (strlen($decoded) > self::MAX_MEDIA_BYTES) {
            return ['success' => false, 'error' => 'Fichier trop volumineux (max 8 Mo)'];
        }
        $mime   = $mime ?: $mimeFromUri;
        $sha256 = $sha256 ?: hash('sha256', $decoded);
        $mediaId = (new \Modules\Form\Models\ResponseMediaModel())->create(
            $responseId, $questionId, $mime, $sha256, strlen($decoded), $payload
        );
        return ['success' => true, 'media_id' => $mediaId];
    }
}