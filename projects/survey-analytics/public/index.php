<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();

$filters = filter_params();
$snapshot = metrics_snapshot($filters);
$forms = all_forms();
$today = date('Y-m-d');
$quickFilters = [
    'Сегодня' => ['from' => $today, 'to' => $today],
    '7 дней' => ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today],
    '30 дней' => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today],
    'Месяц' => ['from' => date('Y-m-01'), 'to' => $today],
    'Год' => ['from' => date('Y-01-01'), 'to' => $today],
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Дашборд | <?= h(config('APP_NAME', 'Система анкетирования')) ?></title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body>
  <?php include APP_ROOT . '/app/admin_header.php'; ?>

  <main class="admin-layout">
    <div class="dashboard-hero">
      <div>
        <p>Мониторинг качества услуг</p>
        <h1>Дашборд анкетирования</h1>
      </div>
      <a class="btn btn-light" href="<?= h(url_for('/surveys.php')) ?>">Ссылки и QR-коды</a>
    </div>
    <form class="filters" method="get">
      <label>С даты <input type="date" name="from" value="<?= h($filters['from']) ?>"></label>
      <label>По дату <input type="date" name="to" value="<?= h($filters['to']) ?>"></label>
      <label>Анкета
        <select name="form">
          <option value="">Все анкеты</option>
          <?php foreach ($forms as $form): ?>
            <option value="<?= h($form['slug']) ?>" <?= $filters['form'] === $form['slug'] ? 'selected' : '' ?>><?= h($form['department']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Показать</button>
      <div class="quick-filters" aria-label="Быстрые фильтры по датам">
        <?php foreach ($quickFilters as $label => $range): ?>
          <?php
            $query = [
                'from' => $range['from'],
                'to' => $range['to'],
            ];
            if ($filters['form'] !== '') {
                $query['form'] = $filters['form'];
            }
            $active = $filters['from'] === $range['from'] && $filters['to'] === $range['to'];
          ?>
          <a class="<?= $active ? 'active' : '' ?>" href="<?= h(url_for('/index.php') . '?' . http_build_query($query)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </form>

    <section class="kpi-grid">
      <article class="kpi"><span>Анкет</span><strong><?= (int)($snapshot['summary']['responses'] ?? 0) ?></strong></article>
      <article class="kpi"><span>Средняя оценка</span><strong><?= number_format((float)($snapshot['summary']['avg_score'] ?? 0), 2, ',', ' ') ?></strong></article>
      <article class="kpi"><span>NPS</span><strong><?= (int)$snapshot['nps'] ?></strong></article>
      <article class="kpi"><span>Доля 0-6</span><strong><?= ($snapshot['summary']['total_scores'] ?? 0) ? number_format(((int)$snapshot['summary']['low_scores'] / (int)$snapshot['summary']['total_scores']) * 100, 1, ',', ' ') : '0,0' ?>%</strong></article>
    </section>

    <section class="chart-grid">
      <article class="panel chart-panel"><h2>Динамика средней оценки</h2><div class="chart-box"><canvas id="trendChart"></canvas></div></article>
      <article class="panel chart-panel"><h2>Распределение оценок</h2><div class="chart-box"><canvas id="distributionChart"></canvas></div></article>
    </section>

    <section class="panel">
      <h2>Сводка по направлениям</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Блок</th><th>Анкет</th><th>Средняя</th><th>NPS</th><th>% 0-6</th><th>Статус</th></tr></thead>
          <tbody>
            <?php foreach ($snapshot['by_form'] as $row): ?>
              <tr>
                <td><?= h($row['department']) ?></td>
                <td><?= (int)$row['responses'] ?></td>
                <td><?= h((string)$row['avg_score']) ?></td>
                <td><?= (int)$row['nps'] ?></td>
                <td><?= h((string)$row['low_share']) ?>%</td>
                <td><span class="status"><?= h($row['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <h2>Вопросы, требующие внимания</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Блок</th><th>Вопрос</th><th>Ответов</th><th>Средняя</th><th>% 0-6</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($snapshot['by_question'], 0, 12) as $row): ?>
              <tr>
                <td><?= h($row['department']) ?></td>
                <td><?= h($row['question_text']) ?></td>
                <td><?= (int)$row['votes'] ?></td>
                <td><?= h((string)$row['avg_score']) ?></td>
                <td><?= h((string)$row['low_share']) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="section-heading">
      <h2>Аналитика по типам анкет</h2>
    </section>

    <?php foreach ($snapshot['forms'] as $formDetail): ?>
      <section class="panel form-analytics" id="form-<?= h($formDetail['slug']) ?>">
        <div class="form-head">
          <div>
            <h2><?= h($formDetail['department']) ?></h2>
            <p><?= h($formDetail['title']) ?></p>
          </div>
          <span class="status"><?= h($formDetail['status']) ?></span>
        </div>

        <div class="mini-kpi-grid">
          <article><span>Анкет</span><strong><?= (int)($formDetail['summary']['responses'] ?? 0) ?></strong></article>
          <article><span>Средняя</span><strong><?= number_format((float)$formDetail['avg_score'], 2, ',', ' ') ?></strong></article>
          <article><span>NPS</span><strong><?= (int)$formDetail['nps'] ?></strong></article>
          <article><span>Доля 0-6</span><strong><?= number_format((float)$formDetail['low_share'], 1, ',', ' ') ?>%</strong></article>
        </div>

        <div class="chart-grid compact">
          <div>
            <h3>Динамика</h3>
            <div class="chart-box small"><canvas id="trend-<?= h($formDetail['slug']) ?>"></canvas></div>
          </div>
          <div>
            <h3>Оценки 0-10</h3>
            <div class="chart-box small"><canvas id="distribution-<?= h($formDetail['slug']) ?>"></canvas></div>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead><tr><th>№</th><th>Вопрос</th><th>Ответов</th><th>Средняя</th><th>% 0-6</th></tr></thead>
            <tbody>
              <?php foreach ($formDetail['questions'] as $question): ?>
                <tr>
                  <td><?= (int)$question['question_number'] ?></td>
                  <td><?= h($question['question_text']) ?></td>
                  <td><?= (int)$question['votes'] ?></td>
                  <td><?= $question['avg_score'] !== null ? h((string)$question['avg_score']) : '-' ?></td>
                  <td><?= $question['low_share'] !== null ? h((string)$question['low_share']) . '%' : '-' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($formDetail['comments'])): ?>
          <div class="form-comments">
            <h3>Комментарии</h3>
            <div class="comments compact-comments">
              <?php foreach ($formDetail['comments'] as $comment): ?>
                <article>
                  <small><?= h($comment['response_date']) ?><?= $comment['source_email'] ? ' · ' . h($comment['source_email']) : '' ?></small>
                  <p><?= nl2br(h($comment['comment'])) ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <section class="panel">
      <h2>Последние комментарии гостей</h2>
      <div class="comments">
        <?php foreach ($snapshot['comments'] as $comment): ?>
          <article>
            <small><?= h($comment['response_date']) ?> · <?= h($comment['department']) ?><?= $comment['source_email'] ? ' · ' . h($comment['source_email']) : '' ?></small>
            <p><?= nl2br(h($comment['comment'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <script>
    window.dashboardData = <?= json_encode([
        'trend' => $snapshot['trend'],
        'distribution' => $snapshot['distribution'],
        'forms' => array_map(fn($form) => [
            'slug' => $form['slug'],
            'trend' => $form['trend'],
            'distribution' => $form['distribution'],
        ], $snapshot['forms']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="<?= h(url_for('/assets/js/dashboard.js')) ?>"></script>
</body>
</html>
