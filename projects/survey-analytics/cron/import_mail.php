<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!function_exists('imap_open')) {
    fwrite(STDERR, "PHP IMAP extension is not enabled.\n");
    exit(1);
}

$mailboxName = (string)config('IMAP_MAILBOX', '');
$user = (string)config('IMAP_USER', '');
$pass = (string)config('IMAP_PASS', '');
$processedFolder = (string)config('IMAP_PROCESSED_FOLDER', '');

if ($mailboxName === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "IMAP settings are not configured.\n");
    exit(1);
}

$mailbox = imap_open($mailboxName, $user, $pass);
if (!$mailbox) {
    fwrite(STDERR, "Cannot connect to IMAP: " . imap_last_error() . "\n");
    exit(1);
}

$criteria = 'UNSEEN';
$fromFilter = trim((string)config('IMAP_FROM_FILTER', ''));
$subjectFilter = trim((string)config('IMAP_SUBJECT_FILTER', ''));
if ($fromFilter !== '') {
    $criteria .= ' FROM "' . addcslashes($fromFilter, '"') . '"';
}
if ($subjectFilter !== '') {
    $criteria .= ' SUBJECT "' . addcslashes($subjectFilter, '"') . '"';
}

$emails = imap_search($mailbox, $criteria) ?: [];
$imported = 0;
$skipped = 0;

foreach ($emails as $messageNumber) {
    $uid = (string)imap_uid($mailbox, $messageNumber);
    $body = extract_message_body($mailbox, (int)$messageNumber);
    $parsed = parse_yandex_form_email($body);
    $result = import_parsed_response($parsed, 'imap', $uid);
    if ($result['status'] === 'imported' || $result['status'] === 'duplicate') {
        imap_setflag_full($mailbox, (string)$messageNumber, '\\Seen');
        if ($processedFolder !== '') {
            @imap_mail_move($mailbox, (string)$messageNumber, $processedFolder);
        }
        $imported++;
    } else {
        $skipped++;
    }
}

imap_expunge($mailbox);
imap_close($mailbox);

echo "Imported or duplicate: {$imported}; skipped: {$skipped}\n";

function extract_message_body($mailbox, int $messageNumber): string
{
    $structure = imap_fetchstructure($mailbox, $messageNumber);
    if (!$structure) {
        return quoted_printable_decode((string)imap_body($mailbox, $messageNumber));
    }

    $parts = [];
    collect_body_parts($mailbox, $messageNumber, $structure, '', $parts);
    if (isset($parts['plain'])) {
        return $parts['plain'];
    }
    if (isset($parts['html'])) {
        return $parts['html'];
    }
    return quoted_printable_decode((string)imap_body($mailbox, $messageNumber));
}

function collect_body_parts($mailbox, int $messageNumber, object $structure, string $prefix, array &$parts): void
{
    if (isset($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $index => $part) {
            $partNumber = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);
            collect_body_parts($mailbox, $messageNumber, $part, $partNumber, $parts);
        }
        return;
    }

    $subtype = strtolower((string)($structure->subtype ?? ''));
    if (!in_array($subtype, ['plain', 'html'], true)) {
        return;
    }

    $body = imap_fetchbody($mailbox, $messageNumber, $prefix ?: '1');
    $encoding = (int)($structure->encoding ?? 0);
    if ($encoding === ENCBASE64) {
        $body = base64_decode($body) ?: '';
    } elseif ($encoding === ENCQUOTEDPRINTABLE) {
        $body = quoted_printable_decode($body);
    }

    $charset = 'UTF-8';
    foreach (($structure->parameters ?? []) as $parameter) {
        if (strtolower((string)$parameter->attribute) === 'charset') {
            $charset = (string)$parameter->value;
        }
    }
    if (strtoupper($charset) !== 'UTF-8') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
        if ($converted !== false) {
            $body = $converted;
        }
    }

    $parts[$subtype] = $body;
}
