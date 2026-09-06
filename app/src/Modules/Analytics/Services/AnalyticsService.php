<?php
namespace Modules\Analytics\Services;

use Modules\Analytics\Models\AnalyticsModel;

class AnalyticsService
{
    private AnalyticsModel $model;

    private const CHOICE_TYPES = ['radio', 'checkbox', 'dropdown'];
    private const TEXT_TYPES   = ['short_text', 'long_text'];

    public function __construct()
    {
        $this->model = new AnalyticsModel();
    }

    public function getAnalytics(int $formId): array
    {
        $total     = $this->model->countResponses($formId);
        $questions = $this->model->getQuestions($formId);
        $answered  = $this->model->getAnsweredCounts($formId); // [qid => count]

        $span          = $this->model->getResponseSpan($formId);
        $identified    = $this->model->countIdentifiedRespondents($formId);
        $anonymous     = $this->model->countAnonymousResponses($formId);
        $answerRows    = $this->model->countAnswerRows($formId);

        $last7  = $this->model->countResponsesInWindow($formId, 7, 0);
        $prev7  = $this->model->countResponsesInWindow($formId, 14, 7);
        $last30 = $this->model->countResponsesInWindow($formId, 30, 0);

        // ── Taux de complétion ──────────────────────────────────────────────
        // moyenne, sur toutes les réponses, du ratio (obligatoires renseignées /
        // obligatoires totales). Sans question obligatoire : ratio sur toutes.
        $requiredIds = array_map(
            static fn ($q) => (int) $q['id'],
            array_filter($questions, static fn ($q) => (int) $q['required'] === 1)
        );
        $denomQuestions = !empty($requiredIds)
            ? $requiredIds
            : array_map(static fn ($q) => (int) $q['id'], $questions);

        $completionRate = null;
        if ($total > 0 && !empty($denomQuestions)) {
            $filled = $this->model->countAnsweredForQuestions($formId, $denomQuestions);
            $completionRate = round($filled / ($total * count($denomQuestions)), 4);
        }

        // ── Séries temporelles (30 j, trous comblés) ────────────────────────
        $perDayRaw = [];
        foreach ($this->model->getResponsesPerDay($formId, 30) as $row) {
            $perDayRaw[$row['day']] = (int) $row['count'];
        }
        $series = [];
        $cumulative = 0;
        $baseline = max(0, $total - array_sum($perDayRaw)); // réponses antérieures à la fenêtre
        $cumulative = $baseline;
        for ($i = 30; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $c = $perDayRaw[$d] ?? 0;
            $cumulative += $c;
            $series[] = ['day' => $d, 'count' => $c, 'cumulative' => $cumulative];
        }

        // ── Par question ────────────────────────────────────────────────────
        $result = [];
        foreach ($questions as $idx => $q) {
            $qId  = (int) $q['id'];
            $type = $q['type'];
            $ans  = $answered[$qId] ?? 0;

            $stats = [
                'answered_count' => $ans,
                'skipped_count'  => max(0, $total - $ans),
                'response_rate'  => $total > 0 ? round($ans / $total, 4) : null,
            ];

            if (in_array($type, self::CHOICE_TYPES, true)) {
                $counts = $this->withPct($this->model->getValueCounts($qId));
                $stats['value_counts']   = $counts;
                $stats['response_count'] = array_sum(array_column($counts, 'count'));
                $stats['top_answer']     = $counts[0]['value'] ?? null;

            } elseif ($type === 'linear_scale') {
                $counts = $this->withPct($this->model->getValueCounts($qId));
                $stats['value_counts']   = $counts;
                $stats['response_count'] = array_sum(array_column($counts, 'count'));

                $values = $this->model->getNumericValues($qId);
                $min = isset($q['scale_min']) ? (int) $q['scale_min'] : (int) ($values ? min($values) : 0);
                $max = isset($q['scale_max']) ? (int) $q['scale_max'] : (int) ($values ? max($values) : 0);

                $stats['numeric'] = [
                    'min'    => $values ? (float) min($values) : null,
                    'max'    => $values ? (float) max($values) : null,
                    'avg'    => $values ? round(array_sum($values) / count($values), 2) : null,
                    'median' => $values ? $this->median($values) : null,
                    'stddev' => $values ? $this->stddev($values) : null,
                    'n'      => count($values),
                ];
                $stats['scale'] = ['min' => $min, 'max' => $max];
                $stats['distribution'] = $this->distribution($values, $min, $max);

                if ($min === 0 && $max === 10 && $values) {
                    $stats['nps'] = $this->nps($values);
                }

            } elseif (in_array($type, self::TEXT_TYPES, true)) {
                $stats = array_merge($stats, $this->model->getTextMetrics($qId));
                $stats['value_counts']   = $this->withPct($this->model->getValueCounts($qId));
                $stats['response_count'] = $ans;

            } else { // date, time, grid, phone, email, …
                $counts = $this->withPct($this->model->getValueCounts($qId));
                $stats['value_counts']   = $counts;
                $stats['response_count'] = array_sum(array_column($counts, 'count'));
            }

            $result[] = [
                'question_id'   => $qId,
                'label'         => $q['label'],
                'type'          => $type,
                'required'      => (int) $q['required'] === 1,
                'position'      => (int) $q['position'],
                'section_index' => (int) ($q['section_index'] ?? 0),
                'stats'         => $stats,
            ];
        }

        return [
            // ── compat rétro ──
            'total_responses'   => $total,
            'responses_per_day' => array_map(
                static fn ($r) => ['day' => $r['day'], 'count' => $r['count']],
                $series
            ),
            'completion_rate'   => $completionRate,
            'last_response_at'  => $span['last'],
            'questions'         => $result,

            // ── bloc analytique enrichi ──
            'summary' => [
                'total_responses'        => $total,
                'identified_respondents' => $identified,
                'anonymous_responses'    => $anonymous,
                'first_response_at'      => $span['first'],
                'last_response_at'       => $span['last'],
                'responses_7d'           => $last7,
                'responses_prev_7d'      => $prev7,
                'delta_7d_pct'           => $prev7 > 0
                    ? round((($last7 - $prev7) / $prev7) * 100, 1)
                    : ($last7 > 0 ? null : 0.0),
                'responses_30d'          => $last30,
                'avg_answers_per_response' => $total > 0 ? round($answerRows / $total, 1) : 0.0,
                'completion_rate'        => $completionRate,
                'question_count'         => count($questions),
                'required_question_count' => count($requiredIds),
            ],
            'timeseries' => $series,
            'activity'   => [
                'by_weekday' => $this->model->getResponsesByWeekday($formId),
                'by_hour'    => $this->model->getResponsesByHour($formId),
            ],
            'funnel' => array_map(static function ($r) {
                return [
                    'question_id'   => $r['question_id'],
                    'label'         => $r['label'],
                    'required'      => $r['required'],
                    'answered'      => $r['stats']['answered_count'],
                    'response_rate' => $r['stats']['response_rate'],
                ];
            }, $result),
        ];
    }

    public function getRawData(int $formId): array
    {
        return $this->model->getRawResponses($formId);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function withPct(array $counts): array
    {
        $total = array_sum(array_map(static fn ($r) => (int) $r['count'], $counts));
        return array_map(static function ($r) use ($total) {
            return [
                'value' => $r['value'],
                'count' => (int) $r['count'],
                'pct'   => $total > 0 ? round(((int) $r['count'] / $total) * 100, 1) : 0.0,
            ];
        }, $counts);
    }

    private function median(array $v): float
    {
        sort($v);
        $n = count($v);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $v[$mid] : round(($v[$mid - 1] + $v[$mid]) / 2, 2);
    }

    private function stddev(array $v): float
    {
        $n = count($v);
        if ($n < 2) return 0.0;
        $mean = array_sum($v) / $n;
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += ($x - $mean) ** 2;
        }
        return round(sqrt($sum / ($n - 1)), 2);
    }

    /** Histogramme : chaque valeur entière de l'échelle → nombre d'occurrences. */
    private function distribution(array $values, int $min, int $max): array
    {
        if ($max < $min) {
            return [];
        }
        $buckets = [];
        for ($i = $min; $i <= $max; $i++) {
            $buckets[$i] = 0;
        }
        foreach ($values as $x) {
            $k = (int) round($x);
            if (array_key_exists($k, $buckets)) {
                $buckets[$k]++;
            }
        }
        $out = [];
        foreach ($buckets as $k => $c) {
            $out[] = ['value' => $k, 'count' => $c];
        }
        return $out;
    }

    /** Net Promoter Score sur une échelle 0–10. */
    private function nps(array $values): array
    {
        $n = count($values);
        $promoters = $passives = $detractors = 0;
        foreach ($values as $x) {
            if ($x >= 9)      $promoters++;
            elseif ($x >= 7)  $passives++;
            else              $detractors++;
        }
        return [
            'promoters'      => $promoters,
            'passives'       => $passives,
            'detractors'     => $detractors,
            'promoters_pct'  => round($promoters / $n * 100, 1),
            'passives_pct'   => round($passives / $n * 100, 1),
            'detractors_pct' => round($detractors / $n * 100, 1),
            'score'          => round(($promoters - $detractors) / $n * 100),
        ];
    }
}
