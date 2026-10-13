<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();

$result = null;
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = (string)($_POST['email_body'] ?? '');
    $preview = parse_yandex_form_email($body);
    if (isset($_POST['import'])) {
        $result = import_parsed_response($preview, 'manual');
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Импорт письма</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body>
  <?php include APP_ROOT . '/app/admin_header.php'; ?>
  <main class="admin-layout">
    <div class="row g-4">
      <section class="col-lg-8">
        <div class="panel">
          <h1 class="admin-title">Импорт письма</h1>
          <p class="text-muted">Резервный импорт старых писем из web forms.</p>
          <?php if ($result): ?><div class="alert <?= h($result['status']) ?>"><?= h($result['message']) ?></div><?php endif; ?>
          <form method="post">
            <textarea class="form-control" name="email_body" rows="20" required><?= h($_POST['email_body'] ?? '') ?></textarea>
            <div class="actions">
              <button class="btn btn-outline-brand" type="submit" name="preview" value="1">Проверить</button>
              <button class="btn btn-brand" type="submit" name="import" value="1">Импортировать</button>
            </div>
          </form>
        </div>
      </section>
      <aside class="col-lg-4">
        <div class="panel">
          <h2>Что распознано</h2>
          <?php if ($preview): ?>
            <dl class="preview">
              <dt>Анкета</dt><dd><?= h($preview['form']['department'] ?? 'не определена') ?></dd>
              <dt>ID ответа</dt><dd><?= h($preview['meta']['external_answer_id'] ?? '') ?></dd>
              <dt>Дата</dt><dd><?= h($preview['meta']['response_date'] ?? '') ?></dd>
              <dt>Email</dt><dd><?= h($preview['meta']['source_email'] ?? '') ?></dd>
              <dt>Оценки</dt><dd><?= h(json_encode($preview['scores'], JSON_UNESCAPED_UNICODE)) ?></dd>
              <dt>Комментарий</dt><dd><?= nl2br(h($preview['comment'])) ?></dd>
            </dl>
          <?php else: ?>
            <p class="text-muted">Вставьте письмо и нажмите “Проверить”.</p>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </main>
</body>
</html>

