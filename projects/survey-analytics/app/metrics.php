<?php
declare(strict_types=1);

function filter_params(): array
{
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $form = $_GET['form'] ?? '';
    return [
        'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01'),
        'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d'),
        'form' => preg_match('/^[a-z_]+$/', $form) ? $form : '',
    ];
}

function metrics_snapshot(array $filters): array
{
    $where = ['r.response_date BETWEEN :from AND :to'];
    $params = [':from' => $filters['from'], ':to' => $filters['to']];
    if ($filters['form'] !== '') {
        $where[] = 'f.slug = :form';
        $params[':form'] = $filters['form'];
    }
    $whereSql = implode(' AND ', $where);

    $summary = db_fetch_one(
        "SELECT COUNT(DISTINCT r.id) responses, AVG(s.score) avg_score,
        SUM(CASE WHEN s.score BETWEEN 0 AND 6 THEN 1 ELSE 0 END) low_scores,
        COUNT(s.id) total_scores
        FROM survey_responses r
        JOIN survey_forms f ON f.id = r.form_id
        JOIN survey_scores s ON s.response_id = r.id
        WHERE {$whereSql}",
        $params
    );

    $npsRows = db_fetch_all(
        "SELECT s.score FROM survey_responses r
        JOIN survey_forms f ON f.id = r.form_id
        JOIN survey_questions q ON q.form_id = f.id AND q.is_nps = 1
        JOIN survey_scores s ON s.response_id = r.id AND s.question_id = q.id
        WHERE {$whereSql}",
        $params
    );

    $nps = calculate_nps(array_map(fn($row) => (int)$row['score'], $npsRows));

    $byForm = db_fetch_all(
        "SELECT f.slug, f.department, COUNT(DISTINCT r.id) responses, ROUND(AVG(s.score), 2) avg_score,
        ROUND(100 * SUM(CASE WHEN s.score BETWEEN 0 AND 6 THEN 1 ELSE 0 END) / COUNT(s.id), 1) low_share
        FROM survey_responses r
        JOIN survey_forms f ON f.id = r.form_id
        JOIN survey_scores s ON s.response_id = r.id
        WHERE {$whereSql}
        GROUP BY f.id
        ORDER BY f.id",
        $params
    );

    foreach ($byForm as &$row) {
        $row['nps'] = nps_for_form($row['slug'], $filters);
        $row['status'] = status_for_metrics((float)$row['avg_score'], (float)$row['low_share'], (int)$row['nps']);
    }

    $byQuestion = db_fetch_all(
        "SELECT f.department, q.question_number, q.question_text, COUNT(s.id) votes, ROUND(AVG(s.score), 2) avg_score,
        ROUND(100 * SUM(CASE WHEN s.score BETWEEN 0 AND 6 THEN 1 ELSE 0 END) / COUNT(s.id), 1) low_share
        FROM survey_scores s
        JOIN survey_questions q ON q.id = s.question_id
        JOIN survey_responses r ON r.id = s.response_id
        JOIN survey_forms f ON f.id = r.form_id
        WHERE {$whereSql}
        GROUP BY q.id
        ORDER BY avg_score ASC, low_share DESC",
        $params
    );

    $trend = db_fetch_all(
        "SELECT r.response_date label, ROUND(AVG(s.score), 2) avg_score, COUNT(DISTINCT r.id) responses
        FROM survey_responses r
        JOIN survey_forms f ON f.id = r.form_id
        JOIN survey_scores s ON s.response_id = r.id
        WHERE {$whereSql}
        GROUP BY r.response_date
        ORDER BY r.response_date",
        $params
    );

    $distribution = db_fetch_all(
        "SELECT s.score, COUNT(*) count
        FROM survey_scores s
        JOIN survey_responses r ON r.id = s.response_id
        JOIN survey_forms f ON f.id = r.form_id
        WHERE {$whereSql}
        GROUP BY s.score
        ORDER BY s.score",
        $params
    );

    $comments = db_fetch_all(
        "SELECT r.response_date, f.department, r.comment, r.source_email
        FROM survey_responses r
        JOIN survey_forms f ON f.id = r.form_id
        WHERE {$whereSql} AND r.comment IS NOT NULL AND r.comment <> ''
        ORDER BY r.response_date DESC, r.id DESC
        LIMIT 30",
        $params
    );

    return [
        'summary' => $summary,
        'nps' => $nps,
        'by_form' => $byForm,
        'by_question' => $byQuestion,
        'trend' => $trend,
        'distribution' => normalize_distribution($distribution),
        'comments' => $comments,
        'forms' => form_detail_snapshots($filters),
    ];
}

function form_detail_snapshots(array $filters): array
{
    $forms = all_forms();
    $details = [];

    foreach ($forms as $form) {
        if ($filters['form'] !== '' && $filters['form'] !== $form['slug']) {
            continue;
        }

        $formFilters = $filters;
        $formFilters['form'] = $form['slug'];
        $params = [':from' => $formFilters['from'], ':to' => $formFilters['to'], ':form' => $form['slug']];
        $whereSql = 'r.response_date BETWEEN :from AND :to AND f.slug = :form';

        $summary = db_fetch_one(
            "SELECT COUNT(DISTINCT r.id) responses, AVG(s.score) avg_score,
            SUM(CASE WHEN s.score BETWEEN 0 AND 6 THEN 1 ELSE 0 END) low_scores,
            COUNT(s.id) total_scores
            FROM survey_responses r
            JOIN survey_forms f ON f.id = r.form_id
            JOIN survey_scores s ON s.response_id = r.id
            WHERE {$whereSql}",
            $params
        );

        $lowShare = !empty($summary['total_scores'])
            ? round(((int)$summary['low_scores'] / (int)$summary['total_scores']) * 100, 1)
            : 0.0;

        $nps = nps_for_form($form['slug'], $formFilters);
        $avgScore = (float)($summary['avg_score'] ?? 0);

        $questions = db_fetch_all(
            "SELECT q.question_number, q.question_text, COUNT(r.id) votes, ROUND(AVG(CASE WHEN r.id IS NOT NULL THEN s.score END), 2) avg_score,
            ROUND(100 * SUM(CASE WHEN r.id IS NOT NULL AND s.score BETWEEN 0 AND 6 THEN 1 ELSE 0 END) / NULLIF(COUNT(r.id), 0), 1) low_share
            FROM survey_questions q
            JOIN survey_forms f ON f.id = q.form_id
            LEFT JOIN survey_scores s ON s.question_id = q.id
            LEFT JOIN survey_responses r ON r.id = s.response_id AND r.response_date BETWEEN :from AND :to
            WHERE f.slug = :form
            GROUP BY q.id
            ORDER BY q.question_number",
            $params
        );

        $trend = db_fetch_all(
            "SELECT r.response_date label, ROUND(AVG(s.score), 2) avg_score, COUNT(DISTINCT r.id) responses
            FROM survey_responses r
            JOIN survey_forms f ON f.id = r.form_id
            JOIN survey_scores s ON s.response_id = r.id
            WHERE {$whereSql}
            GROUP BY r.response_date
            ORDER BY r.response_date",
            $params
        );

        $distribution = db_fetch_all(
            "SELECT s.score, COUNT(*) count
            FROM survey_scores s
            JOIN survey_responses r ON r.id = s.response_id
            JOIN survey_forms f ON f.id = r.form_id
            WHERE {$whereSql}
            GROUP BY s.score
            ORDER BY s.score",
            $params
        );

        $comments = db_fetch_all(
            "SELECT r.response_date, r.comment, r.source_email
            FROM survey_responses r
            JOIN survey_forms f ON f.id = r.form_id
            WHERE {$whereSql} AND r.comment IS NOT NULL AND r.comment <> ''
            ORDER BY r.response_date DESC, r.id DESC
            LIMIT 8",
            $params
        );

        $details[] = [
            'slug' => $form['slug'],
            'department' => $form['department'],
            'title' => $form['title'],
            'summary' => $summary,
            'avg_score' => $avgScore,
            'nps' => $nps,
            'low_share' => $lowShare,
            'status' => !empty($summary['responses']) ? status_for_metrics($avgScore, $lowShare, $nps) : 'Нет данных',
            'questions' => $questions,
            'trend' => $trend,
            'distribution' => normalize_distribution($distribution),
            'comments' => $comments,
        ];
    }

    return $details;
}

function calculate_nps(array $scores): int
{
    $total = count($scores);
    if ($total === 0) {
        return 0;
    }
    $promoters = count(array_filter($scores, fn($score) => $score >= 9));
    $detractors = count(array_filter($scores, fn($score) => $score <= 6));
    return (int)round((($promoters / $total) - ($detractors / $total)) * 100);
}

function nps_for_form(string $slug, array $filters): int
{
    $params = [':from' => $filters['from'], ':to' => $filters['to'], ':slug' => $slug];
    $rows = db_fetch_all(
        "SELECT s.score FROM survey_responses r
        JOIN survey_forms f ON f.id = r.form_id
        JOIN survey_questions q ON q.form_id = f.id AND q.is_nps = 1
        JOIN survey_scores s ON s.response_id = r.id AND s.question_id = q.id
        WHERE r.response_date BETWEEN :from AND :to AND f.slug = :slug",
        $params
    );
    return calculate_nps(array_map(fn($row) => (int)$row['score'], $rows));
}

function status_for_metrics(float $avgScore, float $lowShare, int $nps): string
{
    if ($avgScore < 7.0 || $lowShare > 15.0 || $nps < 0) {
        return 'Критическая зона';
    }
    if ($nps < 30) {
        return 'Зона внимания';
    }
    if ($nps <= 50) {
        return 'Стабильно';
    }
    return 'Сильная зона';
}

function normalize_distribution(array $rows): array
{
    $map = array_fill(0, 11, 0);
    foreach ($rows as $row) {
        $map[(int)$row['score']] = (int)$row['count'];
    }
    return $map;
}

function db_fetch_one(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: [];
}

function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
