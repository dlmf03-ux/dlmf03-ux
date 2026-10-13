<?php
declare(strict_types=1);

function all_forms(): array
{
    return db()->query('SELECT * FROM survey_forms ORDER BY id')->fetchAll();
}

function form_by_title(string $title): ?array
{
    $normalized = lower_text(trim($title));
    $forms = all_forms();
    foreach ($forms as $form) {
        $formTitle = lower_text($form['title']);
        if ($normalized === $formTitle || str_contains($normalized, $formTitle) || str_contains($formTitle, $normalized)) {
            return $form;
        }
    }

    $aliases = [
        'столов' => 'dining',
        'медицин' => 'medical',
        'прожив' => 'room',
        'номере' => 'room',
        'досуг' => 'leisure',
        'итог' => 'final',
        'выезде' => 'final',
        'мини' => 'quick',
    ];

    foreach ($aliases as $needle => $slug) {
        if (str_contains($normalized, $needle)) {
            return form_by_slug($slug);
        }
    }

    return null;
}

function form_by_external_id(string $externalFormId): ?array
{
    $stmt = db()->prepare('SELECT * FROM survey_forms WHERE yandex_form_id = ?');
    $stmt->execute([$externalFormId]);
    $form = $stmt->fetch();
    return $form ?: null;
}

function lower_text(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function form_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM survey_forms WHERE slug = ?');
    $stmt->execute([$slug]);
    $form = $stmt->fetch();
    return $form ?: null;
}

function questions_for_form(int $formId): array
{
    $stmt = db()->prepare('SELECT * FROM survey_questions WHERE form_id = ? ORDER BY question_number');
    $stmt->execute([$formId]);
    return $stmt->fetchAll();
}
