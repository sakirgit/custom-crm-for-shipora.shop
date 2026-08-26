/* global DsCrm, DsCrmApp, Chart */

(() => {
	const dashboard = document.querySelector('[data-crm-module="dashboard"]');
	if (!dashboard) {
		return;
	}

	const { postAjax, formatAmount, DsCrmUI } = DsCrm;
	const themeColors = DsCrmApp?.themeColors || {};
	const accentColor = themeColors.accent || '#2563eb';

	const hexToRgba = (hex, alpha = 1) => {
		let h = String(hex || '').replace('#', '');
		if (h.length === 3) {
			h = h
				.split('')
				.map((c) => c + c)
				.join('');
		}
		const r = parseInt(h.slice(0, 2), 16) || 37;
		const g = parseInt(h.slice(2, 4), 16) || 99;
		const b = parseInt(h.slice(4, 6), 16) || 235;
		return `rgba(${r},${g},${b},${alpha})`;
	};
	const warehouseGrid = document.getElementById('ds-crm-kpi-warehouse');
	const accountantGrid = document.getElementById('ds-crm-kpi-accountant');
	const orderStatusCanvas = document.getElementById('ds-crm-chart-order-status');
	const paymentsCanvas = document.getElementById('ds-crm-chart-payments');
	const periodLabelEl = document.getElementById('ds-crm-dashboard-period-label');
	const loadingEl = document.getElementById('ds-crm-dashboard-loading');
	const bodyEl = document.getElementById('ds-crm-dashboard-body');
	const customPanel = document.getElementById('ds-crm-period-custom');
	const dateFromInput = dashboard.querySelector('.ds-crm-period-from');
	const dateToInput = dashboard.querySelector('.ds-crm-period-to');
	const topSellingBody = document.getElementById('ds-crm-top-selling-body');
	const topClientsBody = document.getElementById('ds-crm-top-clients-body');
	const stockStatsEl = document.getElementById('ds-crm-stock-stats');
	const newOrdersEl = document.getElementById('ds-crm-insight-new-orders');
	const orderChartTitle = document.getElementById('ds-crm-chart-order-status-title');
	const paymentsChartTitle = document.getElementById('ds-crm-chart-payments-title');

	const showInsights = dashboard.dataset.showInsights === '1';

	let orderChart = null;
	let paymentsChart = null;
	let activePeriod = 'today';
	let loadToken = 0;

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const setLoading = (on) => {
		if (loadingEl) {
			loadingEl.hidden = !on;
		}
		if (bodyEl) {
			bodyEl.classList.toggle('is-loading', on);
		}
	};

	const renderKpiCard = (key, card) => {
		const color = card.color || 'blue';
		return `
			<div class="ds-crm-kpi-card ds-crm-kpi-${color}" data-card="${key}">
				<span class="ds-crm-kpi-icon" aria-hidden="true">${card.icon || '📊'}</span>
				<div class="ds-crm-kpi-body">
					<span class="ds-crm-kpi-label">${escapeHtml(card.label || key)}</span>
					<strong class="ds-crm-kpi-value">${escapeHtml(String(card.value ?? '—'))}</strong>
				</div>
			</div>`;
	};

	const renderKpiGrid = (container, cards) => {
		if (!container) {
			return;
		}
		if (!cards || !Object.keys(cards).length) {
			container.innerHTML = '<p class="ds-crm-empty">No data for this period.</p>';
			return;
		}
		container.innerHTML = Object.entries(cards)
			.map(([key, card]) => renderKpiCard(key, card))
			.join('');
	};

	const renderOrderStatusChart = (breakdown) => {
		if (!orderStatusCanvas || typeof Chart === 'undefined') {
			return;
		}
		if (orderChart) {
			orderChart.destroy();
			orderChart = null;
		}
		if (!breakdown?.length) {
			return;
		}
		orderChart = new Chart(orderStatusCanvas, {
			type: 'pie',
			data: {
				labels: breakdown.map((row) => row.label),
				datasets: [
					{
						data: breakdown.map((row) => row.count),
						backgroundColor: breakdown.map((row) => row.color || '#6b7280'),
						borderWidth: 2,
						borderColor: '#ffffff',
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { position: 'bottom' } },
			},
		});
	};

	const renderPaymentsChart = (paymentsByDay) => {
		if (!paymentsCanvas || typeof Chart === 'undefined') {
			return;
		}
		if (paymentsChart) {
			paymentsChart.destroy();
			paymentsChart = null;
		}
		if (!paymentsByDay?.labels?.length) {
			return;
		}
		paymentsChart = new Chart(paymentsCanvas, {
			type: 'bar',
			data: {
				labels: paymentsByDay.labels,
				datasets: [
					{
						label: 'Payments',
						data: paymentsByDay.amounts || [],
						backgroundColor: hexToRgba(accentColor, 0.75),
						borderColor: accentColor,
						borderWidth: 1,
						borderRadius: 6,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label(context) {
								return formatAmount(context.parsed.y);
							},
						},
					},
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							callback(value) {
								return formatAmount(value);
							},
						},
					},
				},
			},
		});
	};

	const renderTopSelling = (rows) => {
		if (!topSellingBody) {
			return;
		}
		if (!rows?.length) {
			topSellingBody.innerHTML =
				'<tr><td colspan="4" class="ds-crm-empty">No sales in this period.</td></tr>';
			return;
		}
		topSellingBody.innerHTML = rows
			.map(
				(row, i) => `
			<tr>
				<td>${i + 1}</td>
				<td>${DsCrmUI.productCell(row.product_name, row.product_image_url, { size: 'sm' })}</td>
				<td>${row.qty_sold}</td>
				<td>${escapeHtml(row.revenue)}</td>
			</tr>`
			)
			.join('');
	};

	const renderTopClients = (rows) => {
		if (!topClientsBody) {
			return;
		}
		if (!rows?.length) {
			topClientsBody.innerHTML =
				'<tr><td colspan="3" class="ds-crm-empty">No client sales in this period.</td></tr>';
			return;
		}
		topClientsBody.innerHTML = rows
			.map(
				(row) => `
			<tr>
				<td>${escapeHtml(row.client_name)}</td>
				<td>${row.order_count}</td>
				<td>${escapeHtml(row.revenue)}</td>
			</tr>`
			)
			.join('');
	};

	const renderStockInsights = (insights) => {
		if (stockStatsEl && insights?.stock) {
			const items = stockStatsEl.querySelectorAll('li');
			if (items[0]) {
				items[0].querySelector('strong').textContent = String(insights.stock.unique_products);
			}
			if (items[1]) {
				items[1].querySelector('strong').textContent = String(insights.stock.total_pieces);
			}
		}
		if (newOrdersEl && insights?.summary) {
			newOrdersEl.textContent = String(insights.summary.new_orders ?? 0);
		}
	};

	const setActiveTab = (period) => {
		activePeriod = period;
		dashboard.querySelectorAll('.ds-crm-period-tab').forEach((tab) => {
			const isActive = tab.dataset.period === period;
			tab.classList.toggle('is-active', isActive);
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});
		if (customPanel) {
			customPanel.hidden = period !== 'custom';
		}
	};

	const loadStats = async (period = activePeriod) => {
		const token = ++loadToken;
		setLoading(true);

		const payload = { period };
		if (period === 'custom') {
			payload.date_from = dateFromInput?.value || '';
			payload.date_to = dateToInput?.value || '';
		}

		try {
			const result = await postAjax('crm_dashboard_stats', payload, { cache: false });

			if (token !== loadToken) {
				return;
			}

			if (!result.success) {
				DsCrmUI.toast(result.data?.message || 'Failed to load dashboard.', 'error');
				return;
			}

			const { cards, charts, insights, period_label: periodLabel, date_from: dateFrom, date_to: dateTo } =
				result.data || {};

			if (periodLabelEl && periodLabel) {
				let subtitle = periodLabel;
				if (dateFrom && dateTo && !['today', 'yesterday'].includes(period)) {
					subtitle += ` (${dateFrom} – ${dateTo})`;
				}
				if (insights?.summary?.new_orders !== undefined) {
					subtitle += ` · ${insights.summary.new_orders} new orders`;
				}
				periodLabelEl.textContent = subtitle;
			}

			if (orderChartTitle) {
				orderChartTitle.textContent = 'Orders by Status';
			}
			if (paymentsChartTitle) {
				paymentsChartTitle.textContent = 'Payments';
			}

			renderKpiGrid(warehouseGrid, cards?.warehouse);
			renderKpiGrid(accountantGrid, cards?.accountant);
			renderOrderStatusChart(charts?.order_status_breakdown);
			renderPaymentsChart(charts?.payments_by_day);

			if (showInsights) {
				renderTopSelling(insights?.top_selling);
				renderTopClients(insights?.top_clients);
				renderStockInsights(insights);
			}
		} finally {
			if (token === loadToken) {
				setLoading(false);
			}
		}
	};

	dashboard.querySelectorAll('.ds-crm-period-tab').forEach((tab) => {
		tab.addEventListener('click', () => {
			const period = tab.dataset.period || 'today';
			setActiveTab(period);
			if (period !== 'custom') {
				loadStats(period);
			}
		});
	});

	dashboard.querySelector('.ds-crm-period-apply')?.addEventListener('click', () => {
		setActiveTab('custom');
		loadStats('custom');
	});

	const today = new Date().toISOString().slice(0, 10);
	const weekAgo = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);
	if (dateFromInput) {
		dateFromInput.value = weekAgo;
	}
	if (dateToInput) {
		dateToInput.value = today;
	}

	loadStats('today');
})();
