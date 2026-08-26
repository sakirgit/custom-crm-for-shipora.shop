/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="reports"]');
	if (!root) return;

	const { postAjax, formatDate, formatDateTime, formatAmount, DsCrmUI } = DsCrm;
	const outputWrap = root.querySelector('.ds-crm-reports-output');
	const resultEl = root.querySelector('.ds-crm-reports-result');
	const printMeta = root.querySelector('.ds-crm-report-print-meta');
	const clientSelect = root.querySelector('#report-client-id');
	const companySelect = root.querySelector('#report-company-id');

	let lastReport = null;

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
		if (printMeta) {
			printMeta.textContent = `${title} · ${rangeText} · ${formatDateTime(new Date().toISOString())}`;
		}
		if (data.report_type === 'client_ledger') {
			resultEl.innerHTML = renderClientLedger(data);
		} else if (data.report_type === 'supplier_ledger') {
			resultEl.innerHTML = renderSupplierLedger(data);
		} else if (data.report_type === 'stock') {
			resultEl.innerHTML = renderStockReport(data);
		}
		outputWrap.hidden = false;
		outputWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	const loadFilters = async () => {
		const res = await postAjax('crm_reports_filters');
		if (!res.success) return;

		if (clientSelect) {
			clientSelect.innerHTML =
				'<option value="">— Select client —</option>' +
				(res.data.clients || [])
					.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
					.join('');
		}
		if (companySelect) {
			companySelect.innerHTML =
				'<option value="">— Select company —</option>' +
				(res.data.companies || [])
					.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
					.join('');
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

	root.querySelector('.ds-crm-reports-print')?.addEventListener('click', () => {
		window.print();
	});

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
		a.download = `crm-report-${lastReport.report_type}-${Date.now()}.csv`;
		a.click();
		URL.revokeObjectURL(url);
		DsCrmUI.toast('CSV downloaded.');
	};

	root.querySelector('.ds-crm-reports-export-csv')?.addEventListener('click', exportCsv);

	loadFilters();
})();
