<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_auth();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Справка | <?= h(config('APP_NAME', 'Система анкетирования')) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(url_for('/assets/css/app.css')) ?>">
</head>
<body>
  <?php include APP_ROOT . '/app/admin_header.php'; ?>

  <main class="admin-layout">
    <div class="dashboard-hero mb-4">
      <div>
        <p>Справочный раздел</p>
        <h1>Что означают показатели в дашборде</h1>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <section class="panel h-100">
          <h2>NPS</h2>
          <p><strong>Net Promoter Score</strong> показывает готовность гостя рекомендовать организация или услугу.</p>
          <ul class="mb-0">
            <li>9-10 баллов - сторонники.</li>
            <li>7-8 баллов - нейтральные гости.</li>
            <li>0-6 баллов - критики.</li>
          </ul>
          <p class="mt-3 mb-0">Формула: <strong>% сторонников - % критиков</strong>.</p>
        </section>
      </div>

      <div class="col-lg-6">
        <section class="panel h-100">
          <h2>Средняя оценка</h2>
          <p>Это обычное среднее арифметическое всех оценок по анкете или блоку.</p>
          <p class="mb-0">Если средняя ниже <strong>7,0</strong>, по методике это зона внимания и нужен разбор причин.</p>
        </section>
      </div>

      <div class="col-lg-6">
        <section class="panel h-100">
          <h2>Доля оценок 0-6</h2>
          <p>Показывает, какая часть ответов относится к негативным или слабым оценкам.</p>
          <p class="mb-0">Если доля выше <strong>15%</strong>, направление считается проблемным и требует корректирующих действий.</p>
        </section>
      </div>

      <div class="col-lg-6">
        <section class="panel h-100">
          <h2>Статусы</h2>
          <ul class="mb-0">
            <li><strong>Сильная зона</strong> - NPS выше 50.</li>
            <li><strong>Стабильно</strong> - NPS от 30 до 50.</li>
            <li><strong>Зона внимания</strong> - NPS от 0 до 30.</li>
            <li><strong>Критическая зона</strong> - NPS ниже 0 или слишком много низких оценок.</li>
          </ul>
        </section>
      </div>

      <div class="col-12">
        <section class="panel">
          <h2>Как читать отчеты по анкетам</h2>
          <div class="table-wrap">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Показатель</th>
                  <th>Что означает</th>
                  <th>Как использовать</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Анкет</td>
                  <td>Количество принятых ответов за выбранный период.</td>
                  <td>Понимать, достаточно ли данных для вывода.</td>
                </tr>
                <tr>
                  <td>Средняя</td>
                  <td>Средний балл по всем вопросам или по анкете.</td>
                  <td>Сравнивать качество между блоками и периодами.</td>
                </tr>
                <tr>
                  <td>NPS</td>
                  <td>Готовность гостя рекомендовать организация.</td>
                  <td>Главный индикатор лояльности.</td>
                </tr>
                <tr>
                  <td>% 0-6</td>
                  <td>Доля низких оценок в ответах.</td>
                  <td>Быстро видеть проблемные места.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="col-12">
        <section class="panel">
          <h2>Периодичность контроля</h2>
          <ul class="mb-0">
            <li><strong>Ежедневно</strong> - смотреть низкие оценки, жалобы и комментарии.</li>
            <li><strong>Еженедельно</strong> - анализировать каждый блок отдельно.</li>
            <li><strong>Ежемесячно</strong> - формировать сводный отчет по лояльности и проблемам.</li>
          </ul>
        </section>
      </div>
    </div>
  </main>
</body>
</html>

