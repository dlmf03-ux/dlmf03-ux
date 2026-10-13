const data = window.dashboardData || { trend: [], distribution: [], forms: [] };

function chartOptions(extra = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    resizeDelay: 150,
    ...extra,
  };
}

function renderTrendChart(canvasId, trend) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) {
    return;
  }

  new Chart(canvas, {
    type: 'line',
    data: {
      labels: trend.map((item) => item.label),
      datasets: [
        {
          label: 'Средняя оценка',
          data: trend.map((item) => Number(item.avg_score)),
          borderColor: '#1877b9',
          backgroundColor: 'rgba(24, 119, 185, 0.12)',
          tension: 0.25,
          fill: true,
          pointRadius: 3,
        },
        {
          label: 'Анкет',
          data: trend.map((item) => Number(item.responses)),
          borderColor: '#2f9f76',
          backgroundColor: 'rgba(47, 159, 118, 0.08)',
          yAxisID: 'count',
          tension: 0.25,
        },
      ],
    },
    options: chartOptions({
      scales: {
        y: { min: 0, max: 10 },
        count: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } },
      },
    }),
  });
}

function renderDistributionChart(canvasId, distribution) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) {
    return;
  }

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: Array.from({ length: 11 }, (_, index) => String(index)),
      datasets: [
        {
          label: 'Количество оценок',
          data: distribution,
          backgroundColor: distribution.map((_, score) => score <= 6 ? '#b83a48' : score <= 8 ? '#c48a2d' : '#2f9f76'),
          borderRadius: 4,
        },
      ],
    },
    options: chartOptions({
      scales: { y: { beginAtZero: true } },
    }),
  });
}

renderTrendChart('trendChart', data.trend || []);
renderDistributionChart('distributionChart', data.distribution || []);

(data.forms || []).forEach((form) => {
  renderTrendChart(`trend-${form.slug}`, form.trend || []);
  renderDistributionChart(`distribution-${form.slug}`, form.distribution || []);
});
