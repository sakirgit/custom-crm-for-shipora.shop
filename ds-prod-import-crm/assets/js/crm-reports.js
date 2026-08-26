/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="reports"]');
	if (!root) return;

	const { postAjax, formatDate, formatDateTime, formatAmount, DsCrmUI } = DsCrm;
	const outputWrap = root.querySelector('.ds-crm-reports-output');
	const resultEl = root.querySelector('.ds-crm-reports-result');
	const printMeta = root.querySelector('.ds-crm-report-print-meta');
	const clientSelect = root.querySelector('#report-client-id');
	const statementClientSelect = root.querySelector('#report-statement-client-id');
	const fullClientSelect = root.querySelector('#report-full-client-id');
	const companySelect = root.querySelector('#report-company-id');
	const isPortal = root.dataset.isPortal === '1';
	const linkedClientId = root.dataset.linkedClientId || '';
	const presetClientId = root.dataset.presetClientId || '';

	let lastReport = null;
	let lastReportTitle = '';

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const formatRange = (from, to) => {
		if (from && to) return `${formatDate(from)} – ${formatDate(to)}`;
		if (from) return `${formatDate(from)} →`;
		if (to) return `→ ${formatDate(to)}`;
		return 'All dates';
	};

	/** Safe slug for download / print filenames */
	const slugifyFilenamePart = (value, fallback = 'report') => {
		const cleaned = String(value ?? '')
			.normalize('NFKD')
			.replace(/[\u0300-\u036f]/g, '')
			.replace(/[^a-zA-Z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '')
			.slice(0, 60);
		return cleaned || fallback;
	};

	const datePartForFilename = (value) => {
		const raw = String(value || '').trim();
		if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
			return raw;
		}
		return '';
	};

	const buildReportFilename = (data, title = '') => {
		const typeMap = {
			client_full: 'Full-Report',
			client_statement: 'Billing-Statement',
			client_ledger: 'Client-Ledger',
			supplier_ledger: 'Supplier-Ledger',
			stock: 'Stock-Report',
		};
		const prefix = typeMap[data?.report_type] || slugifyFilenamePart(title, 'CRM-Report');
		const entityName =
			data?.entity?.name ||
			(data?.report_type === 'stock' ? 'Inventory' : '');
		const entity = slugifyFilenamePart(entityName, 'report');

		const from = datePartForFilename(data?.date_from);
		const to = datePartForFilename(data?.date_to);
		let range = 'all-dates';
		if (from && to) {
			range = `${from}_to_${to}`;
		} else if (from) {
			range = `from_${from}`;
		} else if (to) {
			range = `to_${to}`;
		}

		return `${prefix}_${entity}_${range}`;
	};

	const statusBadge = (status, label) => {
		const key = status || 'unpaid';
		return `<span class="ds-crm-badge ds-crm-badge-${escapeHtml(key)}">${escapeHtml(label || key)}</span>`;
	};

	const statCard = (label, value, extra = '') =>
		`<div class="ds-crm-stat-card ${extra}"><span class="ds-crm-stat-label">${escapeHtml(label)}</span><span class="ds-crm-stat-value">${value}</span></div>`;

	const renderLedgerTable = (entries, showBalance = true) => {
		if (!entries?.length) {
			return '<p class="ds-crm-ledger-empty">No transactions in this period.</p>';
		}
		const balanceCol = showBalance ? '<th>Balance</th>' : '';
		const rows = entries
			.map(
				(e) => `
			<tr>
				<td>${formatDate(e.date)}</td>
				<td>${escapeHtml(e.label)}</td>
				<td>${escapeHtml(e.reference || '—')}</td>
				<td>${e.debit > 0 ? formatAmount(e.debit) : '—'}</td>
				<td>${e.credit > 0 ? formatAmount(e.credit) : '—'}</td>
				${showBalance ? `<td>${formatAmount(e.balance)}</td>` : ''}
			</tr>`
			)
			.join('');
		return `
			<div class="ds-crm-table-wrap">
				<table class="ds-crm-table ds-crm-report-table">
					<thead>
						<tr>
							<th>Date</th><th>Type</th><th>Reference</th>
							<th>Debit (bill)</th><th>Credit (paid)</th>${balanceCol}
						</tr>
					</thead>
					<tbody>${rows}</tbody>
				</table>
			</div>`;
	};

	const renderClientLedger = (data) => {
		const c = data.entity;
		const dueClass = data.closing > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
		return `
			<h2 class="ds-crm-report-title">Client ledger — ${escapeHtml(c.name)}</h2>
			<p class="ds-crm-report-subtitle">${escapeHtml(c.phone || '')}${c.email ? ` · ${escapeHtml(c.email)}` : ''}</p>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${statCard('Opening', formatAmount(data.opening))}
				${statCard('Bills (period)', formatAmount(data.period_debit))}
				${statCard('Payments (period)', formatAmount(data.period_credit))}
				${statCard('Closing balance', formatAmount(data.closing), dueClass)}
			</div>
			<p class="ds-crm-report-note">Lifetime due (all dates): <strong>${formatAmount(data.summary?.total_due ?? 0)}</strong></p>
			${renderLedgerTable(data.entries)}
		`;
	};

	const renderBillingBody = (data, { showLedgerNote = true } = {}) => {
		const s = data.summary || {};
		const dueClass = (s.total_due || 0) > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
		const orderDueClass = (s.order_due || 0) > 0 ? 'ds-crm-stat-card--due' : '';
		const deliveryDueClass = (s.delivery_due || 0) > 0 ? 'ds-crm-stat-card--due' : '';
		const orders = data.orders || [];

		const screenOrderRows = orders
			.map(
				(o) => `
			<tr>
				<td>${escapeHtml(o.order_number || '—')}</td>
				<td>${formatDate(o.order_date)}</td>
				<td>${formatAmount(o.order_bill)}</td>
				<td>${formatAmount(o.order_paid)}</td>
				<td>${formatAmount(o.order_due)}</td>
				<td>${formatAmount(o.delivery_bill)}</td>
				<td>${formatAmount(o.delivery_paid)}</td>
				<td>${formatAmount(o.delivery_due)}</td>
				<td>${formatAmount(o.total_due)}</td>
				<td>${statusBadge(o.payment_status, o.payment_status_label)}</td>
			</tr>`
			)
			.join('');

		const printProductRows = orders
			.map(
				(o) => `
			<tr>
				<td>${escapeHtml(o.order_number || '—')}</td>
				<td>${formatDate(o.order_date)}</td>
				<td>${formatAmount(o.order_bill)}</td>
				<td>${formatAmount(o.order_paid)}</td>
				<td>${formatAmount(o.order_due)}</td>
				<td>${statusBadge(o.payment_status, o.payment_status_label)}</td>
			</tr>`
			)
			.join('');

		const printDeliveryRows = orders
			.map(
				(o) => `
			<tr>
				<td>${escapeHtml(o.order_number || '—')}</td>
				<td>${formatDate(o.order_date)}</td>
				<td>${formatAmount(o.delivery_bill)}</td>
				<td>${formatAmount(o.delivery_paid)}</td>
				<td>${formatAmount(o.delivery_due)}</td>
				<td>${formatAmount(o.total_due)}</td>
			</tr>`
			)
			.join('');

		const paymentRows = (data.payments || [])
			.map(
				(p) => `
			<tr>
				<td>${formatDate(p.payment_date)}</td>
				<td>${escapeHtml(p.payment_number || '—')}</td>
				<td>${escapeHtml(p.payment_purpose_label || p.payment_purpose || '—')}</td>
				<td>${formatAmount(p.amount)}</td>
				<td>${escapeHtml(p.payment_method || '—')}</td>
				<td>${escapeHtml(p.reference || '—')}</td>
			</tr>`
			)
			.join('');

		const emptyOrders = '<tr><td colspan="10">No orders in this period.</td></tr>';
		const emptyProduct = '<tr><td colspan="6">No orders in this period.</td></tr>';
		const emptyDelivery = '<tr><td colspan="6">No orders in this period.</td></tr>';

		return `
			<div class="ds-crm-ledger-summary">
				<h3 class="ds-crm-ledger-summary-title">Account summary</h3>
				<div class="ds-crm-order-stats ds-crm-order-stats--compact">
					${statCard('Total bill', formatAmount(s.total_bill || 0))}
					${statCard('Paid (applied)', formatAmount(s.total_paid || 0))}
					${statCard('Total due', formatAmount(s.total_due || 0), dueClass)}
				</div>
			</div>
			<div class="ds-crm-payment-dues-stack ds-crm-report-dues-stack">
				<div class="ds-crm-order-stats ds-crm-order-stats--compact ds-crm-payment-dues-row">
					${statCard('Order bill', formatAmount(s.order_bill || 0))}
					${statCard('Order paid', formatAmount(s.order_paid || 0))}
					${statCard('Order due', formatAmount(s.order_due || 0), orderDueClass)}
				</div>
				<div class="ds-crm-order-stats ds-crm-order-stats--compact ds-crm-payment-dues-row">
					${statCard('Delivery bill', formatAmount(s.delivery_bill || 0))}
					${statCard('Delivery paid', formatAmount(s.delivery_paid || 0))}
					${statCard('Delivery due', formatAmount(s.delivery_due || 0), deliveryDueClass)}
				</div>
			</div>
			<section class="ds-crm-ledger-section">
				<h3>Orders — bill / paid / due</h3>
				${showLedgerNote ? '<p class="description ds-crm-report-screen-only">Unpaid / Partial / Paid reflects allocated payments (order vs delivery purpose).</p>' : ''}
				<div class="ds-crm-table-wrap ds-crm-report-screen-only">
					<table class="ds-crm-table ds-crm-report-table">
						<thead>
							<tr>
								<th>Order #</th><th>Date</th>
								<th>Order bill</th><th>Order paid</th><th>Order due</th>
								<th>Delivery bill</th><th>Delivery paid</th><th>Delivery due</th>
								<th>Total due</th><th>Status</th>
							</tr>
						</thead>
						<tbody>${screenOrderRows || emptyOrders}</tbody>
					</table>
				</div>
				<div class="ds-crm-report-print-only ds-crm-report-print-orders">
					<p class="ds-crm-report-print-caption">Order (product) billing</p>
					<table class="ds-crm-table ds-crm-report-table ds-crm-report-table--print">
						<thead>
							<tr>
								<th>Order #</th><th>Date</th><th>Bill</th><th>Paid</th><th>Due</th><th>Status</th>
							</tr>
						</thead>
						<tbody>${printProductRows || emptyProduct}</tbody>
					</table>
					<p class="ds-crm-report-print-caption">Delivery (shipping) billing</p>
					<table class="ds-crm-table ds-crm-report-table ds-crm-report-table--print">
						<thead>
							<tr>
								<th>Order #</th><th>Date</th><th>Bill</th><th>Paid</th><th>Due</th><th>Total due</th>
							</tr>
						</thead>
						<tbody>${printDeliveryRows || emptyDelivery}</tbody>
					</table>
				</div>
			</section>
			<section class="ds-crm-ledger-section">
				<h3>Payments received</h3>
				<div class="ds-crm-table-wrap ds-crm-report-table-wrap--payments">
					<table class="ds-crm-table ds-crm-report-table ds-crm-report-table--print-fit">
						<thead>
							<tr>
								<th>Date</th><th>Payment #</th><th>Purpose</th><th>Amount</th><th>Method</th><th>Reference</th>
							</tr>
						</thead>
						<tbody>${paymentRows || '<tr><td colspan="6">No payments in this period.</td></tr>'}</tbody>
					</table>
				</div>
			</section>
		`;
	};

	const renderClientStatement = (data) => {
		const c = data.entity;
		return `
			<h2 class="ds-crm-report-title">Billing statement — ${escapeHtml(c.name)}</h2>
			<p class="ds-crm-report-subtitle">
				${escapeHtml(c.phone || '')}${c.email ? ` · ${escapeHtml(c.email)}` : ''}
				${c.address ? `<br>${escapeHtml(c.address)}` : ''}
			</p>
			${renderBillingBody(data)}
		`;
	};

	const renderClientFull = (data) => {
		const c = data.entity;
		const s = data.summary || {};
		const title = isPortal ? 'Account report' : 'Full client report';
		return `
			<h2 class="ds-crm-report-title">${title} — ${escapeHtml(c.name)}</h2>
			<p class="ds-crm-report-subtitle">
				${escapeHtml(c.phone || '')}${c.email ? ` · ${escapeHtml(c.email)}` : ''}
				${c.address ? `<br>${escapeHtml(c.address)}` : ''}
			</p>
			<p class="ds-crm-report-note">${data.open_orders || 0} open order(s) · Lifetime due: <strong>${formatAmount(s.total_due || 0)}</strong></p>
			${renderBillingBody(data)}
			<section class="ds-crm-ledger-section ds-crm-report-full-ledger">
				<h3>Transaction ledger</h3>
				<div class="ds-crm-order-stats ds-crm-order-stats--compact">
					${statCard('Opening', formatAmount(data.opening))}
					${statCard('Bills (period)', formatAmount(data.period_debit))}
					${statCard('Payments (period)', formatAmount(data.period_credit))}
					${statCard('Closing', formatAmount(data.closing), data.closing > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok')}
				</div>
				${renderLedgerTable(data.entries)}
			</section>
		`;
	};

	const renderSupplierLedger = (data) => {
		const co = data.entity;
		const dueClass = data.closing > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
		return `
			<h2 class="ds-crm-report-title">Supplier ledger — ${escapeHtml(co.name)}</h2>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${statCard('Opening', formatAmount(data.opening))}
				${statCard('Billed (period)', formatAmount(data.period_debit))}
				${statCard('Paid (period)', formatAmount(data.period_credit))}
				${statCard('Closing balance', formatAmount(data.closing), dueClass)}
			</div>
			<p class="ds-crm-report-note">Lifetime due (all dates): <strong>${formatAmount(data.summary?.total_due ?? 0)}</strong></p>
			${renderLedgerTable(data.entries)}
		`;
	};

	const renderStockReport = (data) => {
		const productRows = (data.product_totals || [])
			.map(
				(p) => `
			<tr>
				<td>${DsCrmUI.productCell(p.product_name, p.product_image_url, { size: 'sm' })}</td>
				<td>${escapeHtml(p.category)}</td>
				<td>${p.variants}</td>
				<td>${p.quantity}</td>
				<td>${formatAmount(p.unit_price)}</td>
			</tr>`
			)
			.join('');

		const variantRows = (data.rows || [])
			.map(
				(r) => `
			<tr>
				<td>${DsCrmUI.productCell(r.product_name, r.product_image_url, { size: 'sm' })}</td>
				<td>${escapeHtml(r.color || '—')}</td>
				<td>${escapeHtml(r.size || '—')}</td>
				<td>${r.quantity}</td>
				<td>${formatAmount(r.unit_price)}</td>
			</tr>`
			)
			.join('');

		return `
			<h2 class="ds-crm-report-title">Stock report</h2>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${statCard('Products', String(data.product_count))}
				${statCard('Variants', String(data.variant_count))}
				${statCard('Total pieces', String(data.total_pieces))}
				${statCard('Low stock lines', String(data.low_stock_count))}
			</div>
			<section class="ds-crm-ledger-section">
				<h3>By product</h3>
				<div class="ds-crm-table-wrap">
					<table class="ds-crm-table ds-crm-report-table">
						<thead><tr><th>Product</th><th>Category</th><th>Variants</th><th>Qty</th><th>Unit price</th></tr></thead>
						<tbody>${productRows || '<tr><td colspan="5">No stock.</td></tr>'}</tbody>
					</table>
				</div>
			</section>
			<section class="ds-crm-ledger-section">
				<h3>By variant (color / size)</h3>
				<div class="ds-crm-table-wrap">
					<table class="ds-crm-table ds-crm-report-table">
						<thead><tr><th>Product</th><th>Color</th><th>Size</th><th>Qty</th><th>Unit price</th></tr></thead>
						<tbody>${variantRows || '<tr><td colspan="5">No stock.</td></tr>'}</tbody>
					</table>
				</div>
			</section>
		`;
	};

	const showReport = (data, title, rangeText) => {
		lastReport = data;
		lastReportTitle = title || '';
		if (printMeta) {
			printMeta.textContent = `${title} · ${rangeText} · ${formatDateTime(new Date().toISOString())}`;
		}
		if (data.report_type === 'client_full') {
			resultEl.innerHTML = renderClientFull(data);
		} else if (data.report_type === 'client_ledger') {
			resultEl.innerHTML = renderClientLedger(data);
		} else if (data.report_type === 'client_statement') {
			resultEl.innerHTML = renderClientStatement(data);
		} else if (data.report_type === 'supplier_ledger') {
			resultEl.innerHTML = renderSupplierLedger(data);
		} else if (data.report_type === 'stock') {
			resultEl.innerHTML = renderStockReport(data);
		}
		outputWrap.hidden = false;
		outputWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	const fillClientSelect = (selectEl, clients, selectedId = '') => {
		if (!selectEl || selectEl.tagName !== 'SELECT') return;
		selectEl.innerHTML =
			'<option value="">— Select client —</option>' +
			(clients || []).map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
		if (selectedId) {
			selectEl.value = String(selectedId);
		}
	};

	const runFullReport = async ({ clientId, dateFrom = '', dateTo = '', btn = null } = {}) => {
		if (!clientId) {
			DsCrmUI.toast('Select a client.', 'error');
			return false;
		}
		if (btn) btn.disabled = true;
		const res = await postAjax('crm_reports_client_full', {
			client_id: clientId,
			date_from: dateFrom,
			date_to: dateTo,
		});
		if (btn) btn.disabled = false;
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Report failed.', 'error');
			return false;
		}
		showReport(
			res.data,
			isPortal ? 'Account report' : 'Full client report',
			formatRange(res.data.date_from, res.data.date_to)
		);
		return true;
	};

	const loadFilters = async () => {
		const res = await postAjax('crm_reports_filters');
		if (!res.success) return;

		const clients = res.data.clients || [];
		const preferred =
			(isPortal && (res.data.linked_client_id || linkedClientId)) ||
			presetClientId ||
			'';

		fillClientSelect(fullClientSelect, clients, preferred);
		fillClientSelect(clientSelect, clients, preferred);
		fillClientSelect(statementClientSelect, clients, preferred);

		if (companySelect) {
			companySelect.innerHTML =
				'<option value="">— Select company —</option>' +
				(res.data.companies || [])
					.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
					.join('');
		}

		if (isPortal && (preferred || linkedClientId)) {
			await runFullReport({
				clientId: preferred || linkedClientId,
				btn: root.querySelector('.ds-crm-reports-filters-full [type="submit"]'),
			});
		} else if (!isPortal && preferred && root.dataset.presetReport === 'full') {
			await runFullReport({
				clientId: preferred,
				btn: root.querySelector('.ds-crm-reports-filters-full [type="submit"]'),
			});
		}
	};

	root.querySelectorAll('.ds-crm-reports-tab').forEach((tab) => {
		tab.addEventListener('click', () => {
			const key = tab.dataset.report;
			root.querySelectorAll('.ds-crm-reports-tab').forEach((t) => {
				t.classList.toggle('is-active', t === tab);
				t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
			});
			root.querySelectorAll('.ds-crm-reports-panel').forEach((panel) => {
				const active = panel.dataset.panel === key;
				panel.classList.toggle('is-active', active);
				panel.hidden = !active;
			});
		});
	});

	root.querySelector('.ds-crm-reports-filters-full')?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const clientId = fullClientSelect?.value || linkedClientId;
		await runFullReport({
			clientId,
			dateFrom: root.querySelector('#report-full-from')?.value || '',
			dateTo: root.querySelector('#report-full-to')?.value || '',
			btn: e.target.querySelector('[type="submit"]'),
		});
	});

	root.querySelector('.ds-crm-reports-filters-client')?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const clientId = clientSelect?.value;
		if (!clientId) {
			DsCrmUI.toast('Select a client.', 'error');
			return;
		}
		const btn = e.target.querySelector('[type="submit"]');
		if (btn) btn.disabled = true;
		const res = await postAjax('crm_reports_client_ledger', {
			client_id: clientId,
			date_from: root.querySelector('#report-client-from')?.value || '',
			date_to: root.querySelector('#report-client-to')?.value || '',
		});
		if (btn) btn.disabled = false;
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Report failed.', 'error');
			return;
		}
		showReport(res.data, 'Client ledger', formatRange(res.data.date_from, res.data.date_to));
	});

	root.querySelector('.ds-crm-reports-filters-statement')?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const clientId = statementClientSelect?.value;
		if (!clientId) {
			DsCrmUI.toast('Select a client.', 'error');
			return;
		}
		const btn = e.target.querySelector('[type="submit"]');
		if (btn) btn.disabled = true;
		const res = await postAjax('crm_reports_client_statement', {
			client_id: clientId,
			date_from: root.querySelector('#report-statement-from')?.value || '',
			date_to: root.querySelector('#report-statement-to')?.value || '',
		});
		if (btn) btn.disabled = false;
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Report failed.', 'error');
			return;
		}
		showReport(res.data, 'Client billing statement', formatRange(res.data.date_from, res.data.date_to));
	});

	root.querySelector('.ds-crm-reports-filters-supplier')?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const companyId = companySelect?.value;
		if (!companyId) {
			DsCrmUI.toast('Select a company.', 'error');
			return;
		}
		const btn = e.target.querySelector('[type="submit"]');
		if (btn) btn.disabled = true;
		const res = await postAjax('crm_reports_supplier_ledger', {
			company_id: companyId,
			date_from: root.querySelector('#report-supplier-from')?.value || '',
			date_to: root.querySelector('#report-supplier-to')?.value || '',
		});
		if (btn) btn.disabled = false;
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Report failed.', 'error');
			return;
		}
		showReport(res.data, 'Supplier ledger', formatRange(res.data.date_from, res.data.date_to));
	});

	root.querySelector('.ds-crm-reports-filters-stock')?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const form = e.target;
		const btn = form.querySelector('[type="submit"]');
		if (btn) btn.disabled = true;
		const res = await postAjax('crm_reports_stock', {
			search: form.querySelector('[name="search"]')?.value || '',
			low_stock_only: form.querySelector('[name="low_stock_only"]')?.checked ? '1' : '0',
			hide_zero: form.querySelector('[name="hide_zero"]')?.checked ? '1' : '0',
		});
		if (btn) btn.disabled = false;
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Report failed.', 'error');
			return;
		}
		showReport(res.data, 'Stock report', new Date().toLocaleDateString());
	});

	const printWithReportFilename = () => {
		if (!lastReport) {
			DsCrmUI.toast('Run a report first.', 'error');
			return;
		}

		const previousTitle = document.title;
		const filename = buildReportFilename(lastReport, lastReportTitle);
		document.title = filename;

		const restoreTitle = () => {
			document.title = previousTitle;
			window.removeEventListener('afterprint', restoreTitle);
		};
		window.addEventListener('afterprint', restoreTitle);
		// Fallback if afterprint is delayed/skipped in some browsers.
		window.setTimeout(restoreTitle, 2000);

		window.print();
	};

	root.querySelector('.ds-crm-reports-print')?.addEventListener('click', printWithReportFilename);

	const exportCsv = () => {
		if (!lastReport) return;

		let lines = [];
		const esc = (v) => {
			const s = String(v ?? '');
			return s.includes(',') || s.includes('"') || s.includes('\n') ? `"${s.replace(/"/g, '""')}"` : s;
		};

		if (lastReport.report_type === 'client_ledger' || lastReport.report_type === 'supplier_ledger') {
			lines.push(['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'].map(esc).join(','));
			(lastReport.entries || []).forEach((e) => {
				lines.push(
					[e.date, e.label, e.reference, e.debit, e.credit, e.balance].map(esc).join(',')
				);
			});
		} else if (lastReport.report_type === 'client_statement' || lastReport.report_type === 'client_full') {
			lines.push(['Section', 'Order # / Payment #', 'Date', 'Purpose / Status', 'Order bill', 'Order paid', 'Order due', 'Delivery bill', 'Delivery paid', 'Delivery due', 'Amount', 'Method', 'Reference'].map(esc).join(','));
			(lastReport.orders || []).forEach((o) => {
				lines.push(
					[
						'Order',
						o.order_number,
						o.order_date,
						o.payment_status_label,
						o.order_bill,
						o.order_paid,
						o.order_due,
						o.delivery_bill,
						o.delivery_paid,
						o.delivery_due,
						'',
						'',
						'',
					].map(esc).join(',')
				);
			});
			(lastReport.payments || []).forEach((p) => {
				lines.push(
					[
						'Payment',
						p.payment_number,
						p.payment_date,
						p.payment_purpose_label,
						'',
						'',
						'',
						'',
						'',
						'',
						p.amount,
						p.payment_method,
						p.reference,
					].map(esc).join(',')
				);
			});
			if (lastReport.report_type === 'client_full') {
				lines.push('');
				lines.push(['Ledger Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'].map(esc).join(','));
				(lastReport.entries || []).forEach((e) => {
					lines.push(
						[e.date, e.label, e.reference, e.debit, e.credit, e.balance].map(esc).join(',')
					);
				});
			}
		} else if (lastReport.report_type === 'stock') {
			lines.push(['Product', 'Color', 'Size', 'Quantity', 'Unit price'].map(esc).join(','));
			(lastReport.rows || []).forEach((r) => {
				lines.push(
					[r.product_name, r.color, r.size, r.quantity, r.unit_price].map(esc).join(',')
				);
			});
		}

		const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `${buildReportFilename(lastReport, lastReportTitle)}.csv`;
		a.click();
		URL.revokeObjectURL(url);
		DsCrmUI.toast('CSV downloaded.');
	};

	root.querySelector('.ds-crm-reports-export-csv')?.addEventListener('click', exportCsv);

	loadFilters();
})();
