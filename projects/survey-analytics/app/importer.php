<?php
declare(strict_types=1);

function import_parsed_response(array $parsed, string $via = 'manual', ?string $messageUid = null): array
{
    if (empty($parsed['form'])) {
        log_import($via, $messageUid, 'skipped', 'Не удалось определить анкету');
        return ['status' => 'skipped', 'message' => 'Не удалось определить анкету по заголовку письма.'];
    }

    if (empty($parsed['scores'])) {
        log_import($via, $messageUid, 'skipped', 'Не найдены оценки 0-10');
        return ['status' => 'skipped', 'message' => 'В письме не найдены оценки 0-10.'];
    }

    $meta = $parsed['meta'];
    if (!empty($meta['external_answer_id']) && response_exists($meta['external_answer_id'])) {
        log_import($via, $messageUid, 'duplicate', 'Ответ уже импортирован: ' . $meta['external_answer_id']);
        return ['status' => 'duplicate', 'message' => 'Ответ уже импортирован.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO survey_responses
            (form_id, external_answer_id, external_form_id, source_email, response_date, room, contact, comment, raw_body, imported_via)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$parsed['form']['id'],
            $meta['external_answer_id'],
            $meta['external_form_id'],
            $meta['source_email'],
            $meta['response_date'],
            $meta['room'],
            $meta['contact'],
            $parsed['comment'],
            $parsed['raw_body'],
            $via,
        ]);
        $responseId = (int)$pdo->lastInsertId();

        $questions = questions_for_form((int)$parsed['form']['id']);
        $byNumber = [];
        foreach ($questions as $question) {
            $byNumber[(int)$question['question_number']] = $question;
        }

        $scoreStmt = $pdo->prepare('INSERT INTO survey_scores (response_id, question_id, score) VALUES (?, ?, ?)');
        foreach ($parsed['scores'] as $number => $score) {
            if (!isset($byNumber[$number])) {
                continue;
            }
            $scoreStmt->execute([$responseId, (int)$byNumber[$number]['id'], (int)$score]);
        }

        $pdo->commit();
        log_import($via, $messageUid, 'imported', 'Импортирован ответ #' . $responseId);
        return ['status' => 'imported', 'message' => 'Ответ импортирован.', 'response_id' => $responseId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        log_import($via, $messageUid, 'error', $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function save_site_response(array $form, array $payload): array
{
    $questions = questions_for_form((int)$form['id']);
    $scores = [];

    foreach ($questions as $question) {
        $key = 'score_' . (int)$question['question_number'];
        if (!isset($payload[$key]) || $payload[$key] === '') {
            continue;
        }
        $score = (int)$payload[$key];
        if ($score < 0 || $score > 10) {
            return ['status' => 'error', 'message' => 'Оценка должна быть от 0 до 10.'];
        }
        $scores[(int)$question['id']] = $score;
    }

    if (count($scores) !== count($questions)) {
        return ['status' => 'error', 'message' => 'Поставьте оценки по всем вопросам.'];
    }

    $responseDate = (string)($payload['response_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $responseDate)) {
        $responseDate = date('Y-m-d');
    }

    $comment = trim((string)($payload['comment'] ?? ''));
    $room = trim((string)($payload['room'] ?? ''));
    $contact = trim((string)($payload['contact'] ?? ''));
    $externalAnswerId = 'site_' . $form['slug'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    $rawBody = json_encode([
        'form' => $form['slug'],
        'scores' => $scores,
        'room' => $room,
        'contact' => $contact,
        'comment' => $comment,
        'response_date' => $responseDate,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO survey_responses
            (form_id, external_answer_id, external_form_id, source_email, response_date, room, contact, comment, raw_body, imported_via)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$form['id'],
            $externalAnswerId,
            null,
            null,
            $responseDate,
            $room !== '' ? $room : null,
            $contact !== '' ? $contact : null,
            $comment !== '' ? $comment : null,
            $rawBody ?: '{}',
            'site',
        ]);

        $responseId = (int)$pdo->lastInsertId();
        $scoreStmt = $pdo->prepare('INSERT INTO survey_scores (response_id, question_id, score) VALUES (?, ?, ?)');
        foreach ($scores as $questionId => $score) {
            $scoreStmt->execute([$responseId, $questionId, $score]);
        }

        $pdo->commit();
        return ['status' => 'imported', 'message' => 'Спасибо! Ответ сохранен.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['status' => 'error', 'message' => 'Не удалось сохранить ответ. Попробуйте позже.'];
    }
}

function response_exists(string $externalAnswerId): bool
{
    $stmt = db()->prepare('SELECT id FROM survey_responses WHERE external_answer_id = ? LIMIT 1');
    $stmt->execute([$externalAnswerId]);
    return (bool)$stmt->fetch();
}

function log_import(string $source, ?string $messageUid, string $status, string $details): void
{
    $stmt = db()->prepare('INSERT INTO import_logs (source, message_uid, status, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$source, $messageUid, $status, $details]);
}
