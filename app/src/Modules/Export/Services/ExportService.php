<?php
namespace Modules\Export\Services;

use Modules\Analytics\Services\AnalyticsService;

class ExportService
{
    private AnalyticsService $analytics;

    public function __construct()
    {
        $this->analytics = new AnalyticsService();
    }

    /**
     * GET /forms/{id}/export/csv
     * Construit un tableau pivot en mémoire puis stream le CSV directement.
     *
     * Format :
     *   response_id | user_id | submitted_at | <label Q1> | <label Q2> | …
     *
     * Chaque ligne = une réponse. Checkbox multi-valeur → concaténées avec " ; ".
     * BOM UTF-8 inclus pour compatibilité Excel.
     */
    public function streamCsv(int $formId): void
    {
        $rows = $this->analytics->getRawData($formId);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="form_' . $formId . '_responses.csv"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM

        if (empty($rows)) {
            fputcsv($out, ['response_id', 'user_id', 'submitted_at']);
            fclose($out);
            return;
        }

        // ── Pivot ──────────────────────────────────────────────────────────────
        $responses = [];
        $questions = []; // labels ordonnés

        foreach ($rows as $row) {
            $rid   = $row['response_id'];
            $label = $row['question_label'];
            // Groupe répétable (B8) : une colonne par occurrence — « Libellé [1] », « Libellé [2] »…
            if (isset($row['repeat_index']) && $row['repeat_index'] !== null) {
                $label .= ' [' . ((int) $row['repeat_index'] + 1) . ']';
            }

            if (!isset($responses[$rid])) {
                $responses[$rid] = [
                    'response_id'  => $rid,
                    'user_id'      => $row['user_id'] ?? '',
                    'submitted_at' => $row['submitted_at'] ?? '',
                    'answers'      => [],
                ];
            }

            if (!in_array($label, $questions, true)) {
                $questions[] = $label;
            }

            // Concaténer si plusieurs valeurs (checkbox)
            if (isset($responses[$rid]['answers'][$label])) {
                $responses[$rid]['answers'][$label] .= ' ; ' . $row['value'];
            } else {
                $responses[$rid]['answers'][$label] = $row['value'];
            }
        }

        // ── En-tête ───────────────────────────────────────────────────────────
        fputcsv($out, array_merge(['response_id', 'user_id', 'submitted_at'], $questions));

        // ── Données ───────────────────────────────────────────────────────────
        foreach ($responses as $r) {
            $line = [$r['response_id'], $r['user_id'], $r['submitted_at']];
            foreach ($questions as $label) {
                $line[] = $r['answers'][$label] ?? '';
            }
            fputcsv($out, $line);
        }

        fclose($out);
    }
}
