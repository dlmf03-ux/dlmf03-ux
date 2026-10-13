<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();

$rows = db_fetch_all(
    'SELECT r.*, f.department, ROUND(AVG(s.score), 2) avg_score
     FROM survey_responses r
     JOIN survey_forms f ON f.id = r.form_id
     LEFT JOIN survey_scores s ON s.response_id = r.id
     GROUP BY r.id
     ORDER BY r.response_date DESC, r.id DESC
     LIMIT 200'
);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ответы</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body>
  <?php include APP_ROOT . '/app/admin_header.php'; ?>
  <main class="admin-layout">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
      <div>
        <h1 class="admin-title">Ответы гостей</h1>
        <p class="text-muted mb-0">Последние 200 сохраненных анкет.</p>
      </div>
      <a class="btn btn-outline-brand" href="<?= h(url_for('/surveys.php')) ?>">Ссылки на анкеты</a>
    </div>
    <section class="panel">
      <div class="table-wrap">
        <table class="table align-middle">
          <thead><tr><th>Дата</th><th>Блок</th><th>ID ответа</th><th>Средняя</th><th>Комментарий</th><th>Источник</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= h($row['response_date']) ?></td>
                <td><?= h($row['department']) ?></td>
                <td><?= h($row['external_answer_id']) ?></td>
                <td><?= h((string)$row['avg_score']) ?></td>
                <td><?= h(truncate_text((string)$row['comment'])) ?></td>
                <td><span class="badge text-bg-light"><?= h($row['imported_via']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
