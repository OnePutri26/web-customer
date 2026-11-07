(() => {
    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.admin-sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', () => {
            const isOpen = sidebar.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(isOpen));
        });

        document.addEventListener('click', (event) => {
            if (
                window.innerWidth <= 760
                && sidebar.classList.contains('is-open')
                && !sidebar.contains(event.target)
                && !toggle.contains(event.target)
            ) {
                sidebar.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    const canvas = document.getElementById('operationsChart');
    const data = window.yesnetDashboard;
    if (!canvas || !data || typeof Chart === 'undefined') {
        return;
    }

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Pelanggan baru',
                    data: data.customers,
                    borderColor: '#3975ed',
                    backgroundColor: '#3975ed18',
                    yAxisID: 'customers',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5
                },
                {
                    label: 'Pendapatan (Rp)',
                    data: data.income,
                    borderColor: '#24ad76',
                    backgroundColor: '#24ad7614',
                    yAxisID: 'income',
                    fill: false,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, boxWidth: 7, boxHeight: 7, padding: 18, font: { size: 10 } }
                },
                tooltip: {
                    callbacks: {
                        label(context) {
                            const value = Number(context.raw || 0);
                            return context.dataset.yAxisID === 'income'
                                ? `${context.dataset.label}: Rp ${new Intl.NumberFormat('id-ID').format(value)}`
                                : `${context.dataset.label}: ${new Intl.NumberFormat('id-ID').format(value)}`;
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#8994a4', font: { size: 9 } } },
                customers: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    grid: { color: '#edf0f5' },
                    ticks: { precision: 0, color: '#8994a4', font: { size: 9 } }
                },
                income: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: {
                        color: '#8994a4',
                        font: { size: 9 },
                        callback(value) {
                            return `Rp ${new Intl.NumberFormat('id-ID', { notation: 'compact' }).format(value)}`;
                        }
                    }
                }
            }
        }
    });
})();
