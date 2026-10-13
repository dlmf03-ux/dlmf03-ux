<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/check_eml_parser.php path/to/message.eml [...]\n");
    exit(1);
}

foreach (array_slice($argv, 1) as $path) {
    if (!is_file($path)) {
        echo basename($path) . ": file not found\n";
        continue;
    }

    $parsed = parse_yandex_form_email((string)file_get_contents($path));
    echo "\n" . basename($path) . "\n";
    echo "Form: " . ($parsed['form']['slug'] ?? 'not detected') . " / " . ($parsed['form']['department'] ?? '') . "\n";
    echo "Answer ID: " . ($parsed['meta']['external_answer_id'] ?? '') . "\n";
    echo "Form ID: " . ($parsed['meta']['external_form_id'] ?? '') . "\n";
    echo "Date: " . ($parsed['meta']['response_date'] ?? '') . "\n";
    echo "Email: " . ($parsed['meta']['source_email'] ?? '') . "\n";
    echo "Contact: " . ($parsed['meta']['contact'] ?? '') . "\n";
    echo "Scores: " . json_encode($parsed['scores'], JSON_UNESCAPED_UNICODE) . "\n";
    echo "Comment: " . str_replace("\n", " | ", $parsed['comment']) . "\n";
}
