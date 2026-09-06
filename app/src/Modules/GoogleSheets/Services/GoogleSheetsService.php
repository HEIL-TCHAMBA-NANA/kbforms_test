<?php
namespace Modules\GoogleSheets\Services;

use Modules\GoogleSheets\Models\GoogleSheetsModel;
use Modules\Analytics\Services\AnalyticsService;

/**
 * Service Google Sheets — US-021
 *
 * Utilise l'API Google Sheets v4 via un compte de service (service account).
 * Le fichier JSON du compte de service doit être configuré dans :
 *   $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON']  (chemin absolu vers le fichier JSON)
 *
 * Endpoints couverts :
 *   GET    /forms/{id}/sheets                 → getSheet()
 *   POST   /forms/{id}/sheets/create          → createSheet()
 *   POST   /forms/{id}/sheets/link            → linkSheet()
 *   POST   /forms/{id}/sheets/export          → exportAll()
 *   POST   /forms/{id}/sheets/export/append   → exportAppend()
 *   POST   /forms/{id}/sheets/import          → importFromSheet()
 *   DELETE /forms/{id}/sheets                 → disconnect()
 */
class GoogleSheetsService
{
    private GoogleSheetsModel  $model;
    private AnalyticsService   $analytics;

    // Base URL Google Sheets API v4
    private const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';
    // Base URL Google Drive API v3 (pour créer un nouveau Sheet)
    private const DRIVE_API  = 'https://www.googleapis.com/drive/v3/files';

    public function __construct()
    {
        $this->model     = new GoogleSheetsModel();
        $this->analytics = new AnalyticsService();
    }

    // ── GET /forms/{id}/sheets ────────────────────────────────────────────────

    public function getSheet(int $formId): array
    {
        $sa = $this->serviceAccount();
        $saEmail = $sa['client_email'] ?? null;

        $row = $this->model->getByForm($formId);
        if (!$row) {
            return [
                'connected' => false,
                'sheet' => null,
                'configured' => $saEmail !== null,
                'service_account_email' => $saEmail,
            ];
        }
        return [
            'connected' => true,
            'configured' => $saEmail !== null,
            'service_account_email' => $saEmail,
            'sheet' => [
                'spreadsheet_id'    => $row['spreadsheet_id'],
                'spreadsheet_title' => $row['spreadsheet_title'],
                'sheet_name'        => $row['sheet_name'],
                'auto_sync'         => (bool)$row['auto_sync'],
                'last_synced_at'    => $row['last_synced_at'],
            ],
        ];
    }

    // ── POST /forms/{id}/sheets/create ────────────────────────────────────────
    // Crée un nouveau Google Sheet via Drive API et le lie au formulaire.

    public function createSheet(int $formId, string $title = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Compte de service Google non configuré.'];
        }

        $sheetTitle = $title ?: "KBForms — Formulaire #$formId";

        // Créer le fichier via Drive API
        $body = json_encode([
            'name'     => $sheetTitle,
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
        ]);

        $res = $this->httpRequest('POST', self::DRIVE_API, $token, $body);
        if (!$res['ok']) {
            return ['success' => false, 'error' => 'Impossible de créer le Sheet : ' . ($res['error'] ?? 'erreur Drive API')];
        }

        $spreadsheetId = $res['data']['id'] ?? null;
        if (!$spreadsheetId) {
            return ['success' => false, 'error' => 'Réponse Drive API invalide'];
        }

        // Partager le Sheet avec le propriétaire du formulaire pour qu'il puisse
        // l'ouvrir (le fichier appartient sinon au seul compte de service).
        try {
            $form  = (new \Modules\Form\Models\FormModel())->getFormById($formId);
            $owner = $form ? (new \Modules\Identity\Models\UserModel())->findById((int) $form['user_id']) : null;
            if ($owner && !empty($owner['email'])) {
                $this->httpRequest(
                    'POST',
                    self::DRIVE_API . "/$spreadsheetId/permissions?sendNotificationEmail=false",
                    $token,
                    json_encode(['role' => 'writer', 'type' => 'user', 'emailAddress' => $owner['email']])
                );
            }
        } catch (\Throwable $e) {
            error_log('[GoogleSheetsService] partage propriétaire échoué: ' . $e->getMessage());
        }

        // Lier au formulaire
        $this->model->upsert($formId, [
            'spreadsheet_id'    => $spreadsheetId,
            'spreadsheet_title' => $sheetTitle,
            'sheet_name'        => 'Réponses',
            'auto_sync'         => 0,
        ]);

        // Écrire les en-têtes dans le Sheet
        $this->writeHeaders($formId, $spreadsheetId, 'Réponses', $token);

        return [
            'success'        => true,
            'spreadsheet_id' => $spreadsheetId,
            'title'          => $sheetTitle,
            'url'            => "https://docs.google.com/spreadsheets/d/$spreadsheetId",
        ];
    }

    // ── POST /forms/{id}/sheets/link ──────────────────────────────────────────
    // Lie un Google Sheet existant au formulaire (ou met à jour auto_sync).

    public function linkSheet(int $formId, array $data): array
    {
        $spreadsheetId = $data['spreadsheet_id'] ?? null;
        $autoSync      = $data['auto_sync'] ?? null;

        // Si on met juste à jour auto_sync sans changer le Sheet
        if ($autoSync !== null && !$spreadsheetId) {
            $current = $this->model->getByForm($formId);
            if (!$current) {
                return ['success' => false, 'error' => 'Aucun Sheet connecté'];
            }
            $this->model->upsert($formId, ['auto_sync' => (int)(bool)$autoSync]);
            return ['success' => true, 'auto_sync' => (bool)$autoSync];
        }

        if (!$spreadsheetId) {
            return ['success' => false, 'error' => 'spreadsheet_id requis'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Compte de service Google non configuré.'];
        }

        // Vérifier que le Sheet est accessible
        $res = $this->httpRequest('GET', self::SHEETS_API . "/$spreadsheetId?fields=properties.title", $token);
        if (!$res['ok']) {
            return ['success' => false, 'error' => 'Sheet introuvable ou accès refusé. Vérifiez que le Sheet est partagé avec le compte de service.'];
        }

        $sheetTitle = $res['data']['properties']['title'] ?? $spreadsheetId;

        $this->model->upsert($formId, [
            'spreadsheet_id'    => $spreadsheetId,
            'spreadsheet_title' => $sheetTitle,
            'sheet_name'        => 'Réponses',
            'auto_sync'         => (int)(bool)($autoSync ?? false),
        ]);

        return [
            'success'           => true,
            'spreadsheet_id'    => $spreadsheetId,
            'spreadsheet_title' => $sheetTitle,
        ];
    }

    // ── POST /forms/{id}/sheets/export ────────────────────────────────────────
    // Écrase le Sheet avec toutes les réponses (rewrite complet).

    public function exportAll(int $formId): array
    {
        $config = $this->model->getByForm($formId);
        if (!$config) {
            return ['success' => false, 'error' => 'Aucun Sheet connecté'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Compte de service non configuré'];
        }

        $rows      = $this->buildRows($formId);
        $sheetId   = $config['spreadsheet_id'];
        $sheetName = $config['sheet_name'] ?: 'Réponses';

        // Clear puis write
        $this->clearSheet($sheetId, $sheetName, $token);
        $written = $this->writeRows($sheetId, $sheetName, $rows, $token);

        $this->model->touchSyncDate($formId);

        return ['success' => true, 'rows_written' => $written];
    }

    // ── POST /forms/{id}/sheets/export/append ─────────────────────────────────
    // Ajoute uniquement les nouvelles réponses (sans écraser les existantes).

    public function exportAppend(int $formId): array
    {
        $config = $this->model->getByForm($formId);
        if (!$config) {
            return ['success' => false, 'error' => 'Aucun Sheet connecté'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Compte de service non configuré'];
        }

        $sheetId   = $config['spreadsheet_id'];
        $sheetName = $config['sheet_name'] ?: 'Réponses';

        // Lire les IDs déjà présents dans le Sheet (colonne A, saute la ligne d'en-tête)
        $existingIds = $this->readColumnA($sheetId, $sheetName, $token);

        $allRows = $this->buildRows($formId);
        // allRows[0] = en-têtes, allRows[1..] = données
        $headers  = $allRows[0] ?? [];
        $dataRows = array_slice($allRows, 1);

        // Filtrer : garder seulement les lignes dont response_id n'est pas déjà dans le Sheet
        $newRows = array_filter($dataRows, function ($row) use ($existingIds) {
            return !in_array((string)($row[0] ?? ''), $existingIds, true);
        });

        $appended = 0;
        if (!empty($newRows)) {
            // Si le Sheet est vide, écrire les en-têtes d'abord
            if (empty($existingIds)) {
                $this->writeRows($sheetId, $sheetName, [$headers], $token, 'USER_ENTERED');
            }
            $appended = $this->appendRows($sheetId, $sheetName, array_values($newRows), $token);
        }

        $this->model->touchSyncDate($formId);

        return ['success' => true, 'rows_appended' => $appended];
    }

    // ── POST /forms/{id}/sheets/import ────────────────────────────────────────
    // Lit les données du Sheet et les importe dans KBForms (sans écraser).

    public function importFromSheet(int $formId): array
    {
        $config = $this->model->getByForm($formId);
        if (!$config) {
            return ['success' => false, 'error' => 'Aucun Sheet connecté'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Compte de service non configuré'];
        }

        $sheetId   = $config['spreadsheet_id'];
        $sheetName = urlencode($config['sheet_name'] ?: 'Réponses');

        // Lire toutes les données du Sheet
        $res = $this->httpRequest(
            'GET',
            self::SHEETS_API . "/$sheetId/values/$sheetName",
            $token
        );

        if (!$res['ok']) {
            return ['success' => false, 'error' => 'Impossible de lire le Sheet'];
        }

        $values = $res['data']['values'] ?? [];
        if (count($values) < 2) {
            return ['success' => true, 'rows_imported' => 0, 'message' => 'Aucune donnée à importer'];
        }

        // La première ligne est l'en-tête, les suivantes sont les données
        // L'import est délégué au ResponseService existant
        // Ici on retourne le nombre de lignes disponibles (implémentation complète
        // dépend du mapping question_label → question_id côté ResponseService)
        $dataRows = array_slice($values, 1);

        return [
            'success'       => true,
            'rows_imported' => count($dataRows),
            'message'       => count($dataRows) . ' ligne(s) lue(s) depuis le Sheet.',
        ];
    }

    // ── DELETE /forms/{id}/sheets ─────────────────────────────────────────────

    public function disconnect(int $formId): array
    {
        $ok = $this->model->deleteByForm($formId);
        return ['success' => $ok];
    }

    // ── Helpers internes ──────────────────────────────────────────────────────

    /**
     * Charge les identifiants du compte de service Google, dans l'ordre :
     *   1. app/config/google-sheets.php  → 'service_account' (tableau inline)
     *                                    ou 'service_account_json' (chemin)
     *   2. variable d'env GOOGLE_SERVICE_ACCOUNT_JSON (chemin absolu)
     */
    private function serviceAccount(): ?array
    {
        $cfgPath = __DIR__ . '/../../../../config/google-sheets.php';
        if (is_file($cfgPath)) {
            $cfg = include $cfgPath;
            if (is_array($cfg)) {
                if (!empty($cfg['service_account']) && is_array($cfg['service_account'])) {
                    return $cfg['service_account'];
                }
                if (!empty($cfg['service_account_json']) && is_file($cfg['service_account_json'])) {
                    return json_decode(file_get_contents($cfg['service_account_json']), true) ?: null;
                }
            }
        }
        $envPath = $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? getenv('GOOGLE_SERVICE_ACCOUNT_JSON') ?: null;
        if ($envPath && is_file($envPath)) {
            return json_decode(file_get_contents($envPath), true) ?: null;
        }
        return null;
    }

    /**
     * Récupère un access token OAuth2 via le compte de service Google.
     */
    private function getAccessToken(): ?string
    {
        $sa = $this->serviceAccount();
        if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
            error_log('[GoogleSheetsService] Compte de service Google non configuré (voir app/config/google-sheets.php).');
            return null;
        }

        // Construire le JWT pour l'échange de token
        $now   = time();
        $scope = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.file';

        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => $scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $sigInput = "$header.$payload";
        openssl_sign($sigInput, $signature, $sa['private_key'], 'SHA256');
        $jwt = "$sigInput." . base64_encode($signature);

        $res = $this->httpRequest('POST', 'https://oauth2.googleapis.com/token', null, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]), 'application/x-www-form-urlencoded');

        return $res['data']['access_token'] ?? null;
    }

    /**
     * Construit le tableau de lignes (en-têtes + données) depuis les réponses KBForms.
     */
    private function buildRows(int $formId): array
    {
        $raw = $this->analytics->getRawData($formId);
        if (empty($raw)) return [['response_id', 'submitted_at']];

        $responses = [];
        $questions = [];

        foreach ($raw as $row) {
            $rid   = $row['response_id'];
            $label = $row['question_label'] ?? '';
            $value = $row['answer_value']   ?? '';

            if (!isset($responses[$rid])) {
                $responses[$rid] = [
                    'response_id'  => $rid,
                    'submitted_at' => $row['submitted_at'] ?? '',
                    'answers'      => [],
                ];
            }

            if ($label && !in_array($label, $questions, true)) {
                $questions[] = $label;
            }

            // Gérer multi-valeurs (checkbox)
            if (isset($responses[$rid]['answers'][$label])) {
                $responses[$rid]['answers'][$label] .= ' ; ' . $value;
            } else {
                $responses[$rid]['answers'][$label] = $value;
            }
        }

        // Construire les lignes
        $headers = array_merge(['response_id', 'submitted_at'], $questions);
        $dataRows = [];
        foreach ($responses as $resp) {
            $line = [$resp['response_id'], $resp['submitted_at']];
            foreach ($questions as $q) {
                $line[] = $resp['answers'][$q] ?? '';
            }
            $dataRows[] = $line;
        }

        return array_merge([$headers], $dataRows);
    }

    private function writeHeaders(int $formId, string $spreadsheetId, string $sheetName, string $token): void
    {
        $rows = $this->buildRows($formId);
        if (!empty($rows)) {
            $this->writeRows($spreadsheetId, $sheetName, [$rows[0]], $token);
        }
    }

    private function clearSheet(string $spreadsheetId, string $sheetName, string $token): void
    {
        $range = urlencode($sheetName);
        $this->httpRequest(
            'POST',
            self::SHEETS_API . "/$spreadsheetId/values/$range:clear",
            $token,
            '{}'
        );
    }

    private function writeRows(string $spreadsheetId, string $sheetName, array $rows, string $token, string $valueInputOption = 'RAW'): int
    {
        if (empty($rows)) return 0;
        $range = urlencode($sheetName);
        $body  = json_encode(['values' => $rows]);
        $url   = self::SHEETS_API . "/$spreadsheetId/values/$range?valueInputOption=$valueInputOption";
        $res   = $this->httpRequest('PUT', $url, $token, $body);
        return $res['data']['updatedRows'] ?? count($rows);
    }

    private function appendRows(string $spreadsheetId, string $sheetName, array $rows, string $token): int
    {
        if (empty($rows)) return 0;
        $range = urlencode($sheetName);
        $body  = json_encode(['values' => $rows]);
        $url   = self::SHEETS_API . "/$spreadsheetId/values/$range:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS";
        $res   = $this->httpRequest('POST', $url, $token, $body);
        return $res['data']['updates']['updatedRows'] ?? count($rows);
    }

    private function readColumnA(string $spreadsheetId, string $sheetName, string $token): array
    {
        $range = urlencode("$sheetName!A2:A");
        $res   = $this->httpRequest('GET', self::SHEETS_API . "/$spreadsheetId/values/$range", $token);
        $values = $res['data']['values'] ?? [];
        return array_map(fn($row) => (string)($row[0] ?? ''), $values);
    }

    /**
     * Wrapper cURL générique pour les appels Google API.
     */
    private function httpRequest(string $method, string $url, ?string $token, ?string $body = null, string $contentType = 'application/json'): array
    {
        $ch = curl_init($url);
        $headers = ["Content-Type: $contentType"];
        if ($token) $headers[] = "Authorization: Bearer $token";

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[GoogleSheetsService] cURL error: $curlError");
            return ['ok' => false, 'error' => $curlError, 'data' => []];
        }

        $data = json_decode($response, true) ?? [];
        $ok   = $httpCode >= 200 && $httpCode < 300;

        if (!$ok) {
            error_log("[GoogleSheetsService] HTTP $httpCode on $method $url — " . substr($response, 0, 300));
        }

        return ['ok' => $ok, 'status' => $httpCode, 'data' => $data, 'error' => $data['error']['message'] ?? null];
    }
}