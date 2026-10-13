<?php
declare(strict_types=1);

require __DIR__ . '/lib/db.php';
demo_require_admin();

$pdo = demo_pdo();
$days = max(1, min(3650, (int)($_GET['days'] ?? 30)));
$librarySearch = trim((string)($_GET['q'] ?? ''));

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function int_fmt(int|string|null $value): string
{
    return number_format((int)($value ?? 0), 0, ',', ' ');
}

function mb_fmt(int|string|null $bytes): string
{
    $bytes = (int)($bytes ?? 0);
    if ($bytes <= 0) return '';
    return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' MB';
}

function pct(int|string|null $part, int|string|null $total): string
{
    $part = (int)($part ?? 0);
    $total = (int)($total ?? 0);
    if ($total <= 0) return '0%';
    return number_format($part * 100 / $total, 1, ',', ' ') . '%';
}

function query_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function query_one(PDO $pdo, string $sql, array $params = []): array
{
    $rows = query_all($pdo, $sql, $params);
    return $rows[0] ?? [];
}

$periodSql = 'created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)';
$params = [':days' => $days];

$totals = query_one($pdo, "
    SELECT
        COUNT(*) AS events,
        SUM(event_type = 'library_download') AS downloads,
        SUM(event_type = 'library_operation' AND status = 'success') AS successful_operations,
        SUM(event_type = 'library_operation' AND status = 'failed') AS failed_operations,
        SUM(event_type = 'app_install') AS installs,
        SUM(event_type = 'app_start') AS starts,
        SUM(event_type = 'check_updates') AS checks,
        COUNT(DISTINCT client_id) AS clients,
        COUNT(DISTINCT ip_address) AS ips,
        SUM(CASE WHEN event_type = 'library_download' THEN bytes_downloaded ELSE 0 END) AS total_bytes
    FROM demo_update_events
    WHERE $periodSql
", $params);

$topWhere = "event_type = 'library_download' AND library_name IS NOT NULL AND $periodSql";
$topParams = $params;
if ($librarySearch !== '') {
    $topWhere .= ' AND library_name LIKE :q';
    $topParams[':q'] = '%' . $librarySearch . '%';
}

$topLibraries = query_all($pdo, "
    SELECT
        library_name, supplier, category, subcategory,
        COUNT(*) AS downloads,
        COUNT(DISTINCT client_id) AS unique_clients,
        COUNT(DISTINCT ip_address) AS unique_ips,
        SUM(bytes_downloaded) AS total_bytes,
        MAX(created_at) AS last_download_at
    FROM demo_update_events
    WHERE $topWhere
    GROUP BY library_name, supplier, category, subcategory
    ORDER BY downloads DESC, unique_clients DESC, last_download_at DESC
    LIMIT 100
", $topParams);

$daily = query_all($pdo, "
    SELECT
        DATE(created_at) AS day,
        SUM(event_type = 'library_download') AS downloads,
        SUM(event_type = 'app_install') AS installs,
        SUM(event_type = 'app_start') AS starts,
        SUM(event_type = 'check_updates') AS checks,
        COUNT(DISTINCT client_id) AS clients
    FROM demo_update_events
    WHERE $periodSql
    GROUP BY DATE(created_at)
    ORDER BY day DESC
    LIMIT 60
", $params);

$suppliers = query_all($pdo, "
    SELECT
        COALESCE(NULLIF(supplier, ''), 'Не указан') AS supplier_name,
        COUNT(*) AS downloads,
        COUNT(DISTINCT library_name) AS libraries,
        COUNT(DISTINCT client_id) AS clients,
        SUM(bytes_downloaded) AS total_bytes
    FROM demo_update_events
    WHERE event_type = 'library_download' AND $periodSql
    GROUP BY COALESCE(NULLIF(supplier, ''), 'Не указан')
    ORDER BY downloads DESC
    LIMIT 50
", $params);

$operations = query_all($pdo, "
    SELECT
        COALESCE(operation, 'unknown') AS operation_name,
        COALESCE(status, 'unknown') AS status_name,
        COUNT(*) AS events,
        COUNT(DISTINCT client_id) AS clients
    FROM demo_update_events
    WHERE event_type = 'library_operation' AND $periodSql
    GROUP BY COALESCE(operation, 'unknown'), COALESCE(status, 'unknown')
    ORDER BY events DESC
", $params);

$versions = query_all($pdo, "
    SELECT
        COALESCE(NULLIF(app_version, ''), 'Не указана') AS version_name,
        COUNT(DISTINCT client_id) AS clients,
        SUM(event_type = 'app_start') AS starts,
        SUM(event_type = 'app_install') AS installs,
        SUM(event_type = 'library_download') AS downloads,
        MAX(created_at) AS last_seen_at
    FROM demo_update_events
    WHERE $periodSql
    GROUP BY COALESCE(NULLIF(app_version, ''), 'Не указана')
    ORDER BY clients DESC, last_seen_at DESC
    LIMIT 50
", $params);

$geo = query_all($pdo, "
    SELECT
        COALESCE(NULLIF(country_code, ''), '??') AS country,
        COALESCE(NULLIF(region, ''), '') AS region_name,
        COALESCE(NULLIF(city, ''), '') AS city_name,
        COUNT(*) AS events,
        COUNT(DISTINCT client_id) AS clients,
        COUNT(DISTINCT ip_address) AS ips,
        SUM(event_type = 'library_download') AS downloads
    FROM demo_update_events
    WHERE $periodSql
    GROUP BY COALESCE(NULLIF(country_code, ''), '??'), COALESCE(NULLIF(region, ''), ''), COALESCE(NULLIF(city, ''), '')
    ORDER BY clients DESC, events DESC
    LIMIT 50
", $params);

$ips = query_all($pdo, "
    SELECT
        ip_address,
        country_code,
        region,
        city,
        COUNT(*) AS events,
        COUNT(DISTINCT client_id) AS clients,
        SUM(event_type = 'library_download') AS downloads,
        MAX(created_at) AS last_seen_at
    FROM demo_update_events
    WHERE ip_address IS NOT NULL AND ip_address <> '' AND $periodSql
    GROUP BY ip_address, country_code, region, city
    ORDER BY events DESC, last_seen_at DESC
    LIMIT 50
", $params);

$errors = query_all($pdo, "
    SELECT
        created_at,
        library_name,
        operation,
        app_version,
        ip_address,
        error_message
    FROM demo_update_events
    WHERE event_type = 'library_operation'
      AND status = 'failed'
      AND $periodSql
    ORDER BY created_at DESC
    LIMIT 50
", $params);

$recent = query_all($pdo, "
    SELECT
        created_at, event_type, client_id, app_version,
        library_name, library_version, supplier, operation, status,
        bytes_downloaded, ip_address, country_code, region, city
    FROM demo_update_events
    WHERE $periodSql
    ORDER BY created_at DESC
    LIMIT 100
", $params);

$totalDownloads = (int)($totals['downloads'] ?? 0);
$successOps = (int)($totals['successful_operations'] ?? 0);
$failedOps = (int)($totals['failed_operations'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Демонстрационный обновлятор</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --bg: #082838; --panel: #103f55; --panel2: #124960; --line: #276b82; --head: #16617c; --text: #e9f7fb; --muted: #9fc6d3; --accent: #e87935; --good: #6ee7b7; --bad: #fb7185; }
        * { box-sizing: border-box; }
        body { margin: 24px; font-family: Segoe UI, Arial, sans-serif; background: var(--bg); color: var(--text); }
        h1 { margin: 0 0 12px; }
        h2 { margin: 26px 0 10px; }
        a { color: #ffb272; }
        .muted { color: var(--muted); }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin: 14px 0 18px; }
        label { display: grid; gap: 4px; font-size: 13px; color: var(--muted); }
        input { background: #061f2c; color: white; border: 1px solid var(--line); border-radius: 7px; padding: 7px 9px; }
        button { background: var(--accent); color: white; border: 0; border-radius: 7px; padding: 8px 13px; font-weight: 700; cursor: pointer; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 18px 0; }
        .card { background: var(--panel2); border: 1px solid var(--line); border-radius: 12px; padding: 14px; min-height: 92px; }
        .value { font-size: 28px; font-weight: 800; color: #ff9b50; margin-top: 7px; }
        .grid2 { display: grid; grid-template-columns: 1fr; gap: 18px; align-items: start; }
        .panel { background: rgba(16,63,85,.65); border: 1px solid var(--line); border-radius: 12px; padding: 12px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; background: var(--panel); border-radius: 10px; overflow: hidden; }
        th, td { padding: 8px 9px; border-bottom: 1px solid #225b70; text-align: left; font-size: 13px; vertical-align: top; }
        th { background: var(--head); color: white; position: sticky; top: 0; }
        tr:hover { background: #15536b; }
        .ok { color: var(--good); font-weight: 700; }
        .fail { color: var(--bad); font-weight: 700; }
        .nowrap { white-space: nowrap; }
        .wide, .grid2 table { min-width: 980px; }
        .tiny { font-size: 12px; }
    </style>
</head>
<body>
    <h1>Демонстрационный обновлятор - Статистика использования</h1>
    <div class="muted">Статистика за выбранный период</div>

    <form class="toolbar" method="get">
        <label>Период, дней
            <input name="days" type="number" min="1" max="3650" value="<?= h((string)$days) ?>">
        </label>
        <label>Поиск библиотеки
            <input name="q" value="<?= h($librarySearch) ?>" placeholder="например U-DAS">
        </label>
        <button type="submit">Показать</button>
    </form>

    <div class="cards">
        <div class="card"><div class="muted">Скачиваний библиотек</div><div class="value"><?= int_fmt($totalDownloads) ?></div></div>
        <div class="card"><div class="muted">Уникальных клиентов</div><div class="value"><?= int_fmt($totals['clients'] ?? 0) ?></div></div>
        <div class="card"><div class="muted">Установок приложения</div><div class="value"><?= int_fmt($totals['installs'] ?? 0) ?></div></div>
        <div class="card"><div class="muted">Запусков приложения</div><div class="value"><?= int_fmt($totals['starts'] ?? 0) ?></div></div>
        <div class="card"><div class="muted">Проверок обновлений</div><div class="value"><?= int_fmt($totals['checks'] ?? 0) ?></div></div>
        <div class="card"><div class="muted">Успешных операций</div><div class="value"><?= int_fmt($successOps) ?></div></div>
        <div class="card"><div class="muted">Ошибок операций</div><div class="value"><?= int_fmt($failedOps) ?></div><div class="tiny muted"><?= pct($failedOps, $successOps + $failedOps) ?> от операций</div></div>
        <div class="card"><div class="muted">Общий объём</div><div class="value"><?= h(mb_fmt($totals['total_bytes'] ?? 0)) ?></div></div>
    </div>

    <h2>TOP 100 библиотек</h2>
    <div class="panel">
        <table class="wide">
            <thead><tr><th>#</th><th>Библиотека</th><th>Supplier</th><th>Категория</th><th>Скачиваний</th><th>Клиентов</th><th>IP</th><th>Объём</th><th>Последнее</th></tr></thead>
            <tbody>
            <?php foreach ($topLibraries as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td><td><?= h($row['library_name']) ?></td><td><?= h($row['supplier']) ?></td>
                    <td><?= h(trim(($row['category'] ?? '') . ' / ' . ($row['subcategory'] ?? ''), ' /')) ?></td>
                    <td><?= int_fmt($row['downloads']) ?></td><td><?= int_fmt($row['unique_clients']) ?></td><td><?= int_fmt($row['unique_ips']) ?></td>
                    <td><?= h(mb_fmt($row['total_bytes'])) ?></td><td class="nowrap"><?= h($row['last_download_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="grid2">
        <section class="panel">
            <h2>Динамика по дням</h2>
            <table><thead><tr><th>День</th><th>Скачивания</th><th>Установки</th><th>Запуски</th><th>Проверки</th><th>Клиенты</th></tr></thead><tbody>
            <?php foreach ($daily as $row): ?>
                <tr><td><?= h($row['day']) ?></td><td><?= int_fmt($row['downloads']) ?></td><td><?= int_fmt($row['installs']) ?></td><td><?= int_fmt($row['starts']) ?></td><td><?= int_fmt($row['checks']) ?></td><td><?= int_fmt($row['clients']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>

        <section class="panel">
            <h2>Версии приложения</h2>
            <table><thead><tr><th>Версия</th><th>Клиенты</th><th>Запуски</th><th>Установки</th><th>Скачивания</th><th>Последняя активность</th></tr></thead><tbody>
            <?php foreach ($versions as $row): ?>
                <tr><td><?= h($row['version_name']) ?></td><td><?= int_fmt($row['clients']) ?></td><td><?= int_fmt($row['starts']) ?></td><td><?= int_fmt($row['installs']) ?></td><td><?= int_fmt($row['downloads']) ?></td><td class="nowrap"><?= h($row['last_seen_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>

        <section class="panel">
            <h2>Поставщики</h2>
            <table><thead><tr><th>Supplier</th><th>Скачивания</th><th>Библиотеки</th><th>Клиенты</th><th>Объём</th></tr></thead><tbody>
            <?php foreach ($suppliers as $row): ?>
                <tr><td><?= h($row['supplier_name']) ?></td><td><?= int_fmt($row['downloads']) ?></td><td><?= int_fmt($row['libraries']) ?></td><td><?= int_fmt($row['clients']) ?></td><td><?= h(mb_fmt($row['total_bytes'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>

        <section class="panel">
            <h2>Операции install/update</h2>
            <table><thead><tr><th>Операция</th><th>Статус</th><th>Событий</th><th>Клиентов</th></tr></thead><tbody>
            <?php foreach ($operations as $row): ?>
                <?php $isFail = ($row['status_name'] ?? '') === 'failed'; ?>
                <tr><td><?= h($row['operation_name']) ?></td><td class="<?= $isFail ? 'fail' : 'ok' ?>"><?= h($row['status_name']) ?></td><td><?= int_fmt($row['events']) ?></td><td><?= int_fmt($row['clients']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>

        <section class="panel">
            <h2>География</h2>
            <table><thead><tr><th>Страна</th><th>Регион</th><th>Город</th><th>Клиенты</th><th>IP</th><th>Скачивания</th><th>События</th></tr></thead><tbody>
            <?php foreach ($geo as $row): ?>
                <tr><td><?= h($row['country']) ?></td><td><?= h($row['region_name']) ?></td><td><?= h($row['city_name']) ?></td><td><?= int_fmt($row['clients']) ?></td><td><?= int_fmt($row['ips']) ?></td><td><?= int_fmt($row['downloads']) ?></td><td><?= int_fmt($row['events']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>

        <section class="panel">
            <h2>TOP IP</h2>
            <table><thead><tr><th>IP</th><th>Страна</th><th>Регион/город</th><th>Клиенты</th><th>Скачивания</th><th>События</th><th>Последнее</th></tr></thead><tbody>
            <?php foreach ($ips as $row): ?>
                <tr><td><?= h($row['ip_address']) ?></td><td><?= h($row['country_code']) ?></td><td><?= h(trim(($row['region'] ?? '') . ' / ' . ($row['city'] ?? ''), ' /')) ?></td><td><?= int_fmt($row['clients']) ?></td><td><?= int_fmt($row['downloads']) ?></td><td><?= int_fmt($row['events']) ?></td><td class="nowrap"><?= h($row['last_seen_at']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
    </div>

    <h2>Последние ошибки операций</h2>
    <div class="panel">
        <table class="wide"><thead><tr><th>Дата</th><th>Библиотека</th><th>Операция</th><th>Версия app</th><th>IP</th><th>Ошибка</th></tr></thead><tbody>
        <?php foreach ($errors as $row): ?>
            <tr><td class="nowrap"><?= h($row['created_at']) ?></td><td><?= h($row['library_name']) ?></td><td><?= h($row['operation']) ?></td><td><?= h($row['app_version']) ?></td><td><?= h($row['ip_address']) ?></td><td class="fail"><?= h($row['error_message']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>

    <h2>Последние 100 событий</h2>
    <div class="panel">
        <table class="wide"><thead><tr><th>Дата</th><th>Событие</th><th>Client</th><th>Версия</th><th>Библиотека</th><th>Supplier</th><th>Операция</th><th>Статус</th><th>Объём</th><th>IP</th><th>Гео</th></tr></thead><tbody>
        <?php foreach ($recent as $row): ?>
            <tr>
                <td class="nowrap"><?= h($row['created_at']) ?></td><td><?= h($row['event_type']) ?></td><td class="tiny"><?= h($row['client_id']) ?></td><td><?= h($row['app_version']) ?></td>
                <td><?= h($row['library_name']) ?><div class="tiny muted"><?= h($row['library_version']) ?></div></td><td><?= h($row['supplier']) ?></td><td><?= h($row['operation']) ?></td>
                <td class="<?= ($row['status'] ?? '') === 'failed' ? 'fail' : 'ok' ?>"><?= h($row['status']) ?></td><td><?= h(mb_fmt($row['bytes_downloaded'])) ?></td><td><?= h($row['ip_address']) ?></td>
                <td><?= h(trim(($row['country_code'] ?? '') . ' ' . ($row['region'] ?? '') . ' ' . ($row['city'] ?? ''))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
</body>
</html>
