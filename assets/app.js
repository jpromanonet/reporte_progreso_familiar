(() => {
  const yearSelect = document.querySelector('[data-year-select]');
  if (yearSelect) {
    yearSelect.addEventListener('change', () => {
      const year = yearSelect.value;
      const url = new URL(window.location.href);
      url.searchParams.set('year', year);
      window.location.href = url.toString();
    });
  }

  document.querySelectorAll('.month-card').forEach((card, i) => {
    card.style.animationDelay = `${0.04 * i}s`;
  });

  const raw = document.getElementById('chart-data');
  if (!raw || typeof Chart === 'undefined') {
    return;
  }

  let data;
  try {
    data = JSON.parse(raw.textContent || '{}');
  } catch {
    return;
  }

  const labels = data.labels || [];
  const grid = 'rgba(140, 120, 100, 0.12)';
  const tick = '#8d7d6d';

  const common = {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
      legend: {
        labels: { color: '#3b322a', boxWidth: 12, font: { size: 11 } },
      },
    },
    scales: {
      x: {
        ticks: { color: tick },
        grid: { color: grid },
      },
      y: {
        beginAtZero: true,
        ticks: { color: tick },
        grid: { color: grid },
      },
    },
  };

  const progress = document.getElementById('chartProgress');
  if (progress) {
    new Chart(progress, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Tareas',
            data: data.tasks || [],
            backgroundColor: 'rgba(95, 146, 135, 0.75)',
            borderRadius: 6,
          },
          {
            label: 'Hábitos',
            data: data.habits || [],
            backgroundColor: 'rgba(184, 148, 78, 0.75)',
            borderRadius: 6,
          },
          {
            label: 'Reparaciones',
            data: data.repairs || [],
            backgroundColor: 'rgba(196, 132, 108, 0.75)',
            borderRadius: 6,
          },
        ],
      },
      options: common,
    });
  }

  const spend = document.getElementById('chartSpend');
  if (spend) {
    new Chart(spend, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Gastos',
            data: data.spend || [],
            borderColor: 'rgba(194, 122, 118, 0.95)',
            backgroundColor: 'rgba(194, 122, 118, 0.18)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointBackgroundColor: '#c27a76',
          },
        ],
      },
      options: common,
    });
  }
})();
