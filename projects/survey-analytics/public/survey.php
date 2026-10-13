<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$slug = (string)($_GET['form'] ?? '');
$form = preg_match('/^[a-z_]+$/', $slug) ? form_by_slug($slug) : null;
if (!$form) {
    http_response_code(404);
}

$questions = $form ? questions_for_form((int)$form['id']) : [];
$result = null;

if ($form && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = save_site_response($form, $_POST);
    if ($result['status'] === 'imported') {
        $_POST = [];
    }
}

$commentPrompts = [
    'dining' => 'Что мы можем улучшить в питании или обслуживании?',
    'medical' => 'Что мы можем улучшить в работе медицинского центра?',
    'room' => 'Что нужно улучшить в номере или работе службы размещения?',
    'leisure' => 'Каких мероприятий или услуг вам не хватило?',
    'final' => 'Что вам понравилось больше всего? Что нам нужно улучшить в первую очередь?',
    'quick' => 'Что особенно понравилось? Что можно улучшить?',
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $form ? h($form['department']) : 'Анкета' ?> | Demo organization</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body class="survey-page">
  <main class="survey-shell">
    <?php if (!$form): ?>
      <section class="survey-card text-center">
        <img src="<?= h(url_for('/assets/img/demo-logo.svg')) ?>" alt="Survey demo" class="survey-logo">
        <h1>Анкета не найдена</h1>
        <p class="text-muted">Проверьте ссылку или QR-код.</p>
      </section>
    <?php else: ?>
      <section class="survey-card">
        <div class="survey-header">
          <img src="<?= h(url_for('/assets/img/demo-logo.svg')) ?>" alt="Survey demo" class="survey-logo">
          <div>
            <p class="survey-eyebrow">организация «Demo organization»</p>
            <h1><?= h($form['department']) ?></h1>
            <p>Поставьте оценку от 0 до 10, где 0 - совсем не доволен, 10 - полностью доволен.</p>
          </div>
        </div>

        <?php if ($result): ?>
          <div class="alert <?= $result['status'] === 'imported' ? 'alert-success' : 'alert-danger' ?>"><?= h($result['message']) ?></div>
        <?php endif; ?>

        <?php if (!$result || $result['status'] !== 'imported'): ?>
          <form method="post" class="survey-form">
            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label">Дата</label>
                <input type="date" class="form-control" name="response_date" value="<?= h($_POST['response_date'] ?? date('Y-m-d')) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label"><?= $form['slug'] === 'quick' ? 'Локация / услуга' : 'Номер проживания' ?></label>
                <input type="text" class="form-control" name="room" value="<?= h($_POST['room'] ?? '') ?>" placeholder="<?= $form['slug'] === 'quick' ? 'Например, бассейн' : 'Например, 214' ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Контакт по желанию</label>
                <input type="text" class="form-control" name="contact" value="<?= h($_POST['contact'] ?? '') ?>" placeholder="Телефон или email">
              </div>
            </div>

            <?php foreach ($questions as $question): ?>
              <?php $field = 'score_' . (int)$question['question_number']; ?>
              <fieldset class="score-question">
                <legend><span><?= (int)$question['question_number'] ?></span><?= h($question['question_text']) ?></legend>
                <div class="score-scale">
                  <?php for ($score = 0; $score <= 10; $score++): ?>
                    <label>
                      <input type="radio" name="<?= h($field) ?>" value="<?= $score ?>" <?= (string)($_POST[$field] ?? '') === (string)$score ? 'checked' : '' ?> required>
                      <span><?= $score ?></span>
                    </label>
                  <?php endfor; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>

            <div class="mb-3">
              <label class="form-label"><?= h($commentPrompts[$form['slug']] ?? 'Комментарий') ?></label>
              <textarea class="form-control" name="comment" rows="4"><?= h($_POST['comment'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-brand btn-lg w-100">Отправить анкету</button>
          </form>
        <?php else: ?>
          <div class="thanks-box">
            <h2>Спасибо за обратную связь!</h2>
            <p>Ваше мнение помогает нам быстрее замечать и исправлять то, что важно для гостей.</p>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>

