<?php
declare(strict_types=1);

function parse_yandex_form_email(string $body): array
{
    $body = normalize_email_body($body);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $body)), fn($line) => $line !== ''));

    $meta = [
        'external_answer_id' => null,
        'answer_url' => null,
        'source_email' => null,
        'response_date' => null,
        'external_form_id' => null,
        'form_title' => null,
        'room' => null,
        'contact' => null,
    ];

    foreach ($lines as $line) {
        if ($meta['room'] === null && preg_match('/(?:номер|комната)[^\dа-яa-z]*(.+)$/iu', $line, $m)) {
            $meta['room'] = trim($m[1]);
        }
        if ($meta['contact'] === null && preg_match('/(?:контакт|телефон|почта)[^\p{L}\p{N}]*(.+)$/iu', $line, $m)) {
            $meta['contact'] = trim($m[1]);
        }
        if ($meta['external_answer_id'] === null && preg_match('/^\d{6,}$/', $line)) {
            $meta['external_answer_id'] = $line;
            continue;
        }
        if ($meta['answer_url'] === null && preg_match('~https?://forms\.yandex\.ru/\S+~u', $line, $m)) {
            $meta['answer_url'] = $m[0];
            continue;
        }
        if ($meta['source_email'] === null && filter_var($line, FILTER_VALIDATE_EMAIL)) {
            $meta['source_email'] = $line;
            continue;
        }
        if ($meta['response_date'] === null && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $line, $m)) {
            $meta['response_date'] = "{$m[3]}-{$m[2]}-{$m[1]}";
            continue;
        }
        if ($meta['external_form_id'] === null && preg_match('/^[a-f0-9]{16,}$/i', $line)) {
            $meta['external_form_id'] = $line;
            continue;
        }
        if ($meta['form_title'] === null && preg_match('/анкета|опрос|форма/iu', $line)) {
            $meta['form_title'] = $line;
        }
    }

    $answers = [];
    if (preg_match_all('/(?:^|\R)\s*(\d{1,2})\.\s*(.*?)(?=(?:\R\s*\d{1,2}\.\s*)|\z)/su', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $number = (int)$match[1];
            $value = trim($match[2]);
            $answers[$number] = $value;
        }
    }

    $scores = [];
    $comments = [];
    foreach ($answers as $number => $value) {
        if (preg_match('/\b(10|[0-9])\b/u', $value, $m)) {
            $score = (int)$m[1];
            if ($score >= 0 && $score <= 10) {
                $scores[$number] = $score;
                $tail = trim(preg_replace('/^\s*(10|[0-9])\b[^\p{L}\p{N}]*/u', '', $value));
                if ($tail !== '') {
                    $comments[] = $tail;
                }
                continue;
            }
        }
        if ($value !== '') {
            $comments[] = $value;
        }
    }

    $form = $meta['external_form_id'] ? form_by_external_id($meta['external_form_id']) : null;
    if ($form === null && $meta['form_title']) {
        $form = form_by_title($meta['form_title']);
    }
    if ($form === null && count($scores) === 1) {
        $form = form_by_slug('quick');
    }

    if ($form && $form['slug'] === 'quick') {
        $comments = [];
        if (!empty($answers[2])) {
            $comments[] = 'Что понравилось: ' . trim($answers[2]);
        }
        if (!empty($answers[3])) {
            $comments[] = 'Что улучшить: ' . trim($answers[3]);
        }
        if (!empty($answers[4])) {
            $meta['contact'] = trim($answers[4]);
        }
        $scores = isset($scores[1]) ? [1 => $scores[1]] : [];
    } elseif (!empty($answers[6])) {
        $comments = [trim($answers[6])];
    }

    return [
        'meta' => $meta,
        'form' => $form,
        'scores' => $scores,
        'comment' => trim(implode("\n", array_unique($comments))),
        'raw_body' => $body,
    ];
}

function normalize_email_body(string $body): string
{
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~<pre\b[^>]*>(.*?)</pre>~isu', $body, $m)) {
        $body = $m[1];
    }
    $body = preg_replace('/<br\s*\/?>/i', "\n", $body);
    $body = preg_replace('/<\/p\s*>/i', "\n", $body);
    $body = strip_tags($body);
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/\n\s*Это письмо содержит ответы на опрос.*$/su', '', $body);
    $body = preg_replace('/[ \t]+/u', ' ', $body);
    return trim((string)$body);
}
