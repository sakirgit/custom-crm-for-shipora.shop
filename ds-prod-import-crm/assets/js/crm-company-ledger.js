/* global DsCrm */

(() => {
	const root = document.querySelector('[data-crm-module="companies-ledger"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, formatAmount, formatWeight, DsCrmUI } = DsCrm;

	const companyId = parseInt(root.dataset.companyId, 10) || 0;
	const canManageBilling = root.dataset.canManageBilling === '1';
	const metaEl = root.querySelector('.ds-crm-ledger-page-meta');
	const totalsWrap = root.querySelector('.ds-crm-ledger-page-totals');
	const totalsStats = root.querySelector('.ds-crm-ledger-totals-stats');
	const breakdownWrap = root.querySelector('.ds-crm-ledger-page-breakdown');
	const breakdownStats = root.querySelector('.ds-crm-ledger-breakdown-stats');
	const titleEl = root.querySelector('.ds-crm-ledger-page-title');
	const thead = root.querySelector('.ds-crm-ledger-entries-table thead');
	const tbody = root.querySelector('.ds-crm-ledger-entries-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');
	const searchInput = root.querySelector('.ds-crm-search');
	const filterClient = root.querySelector('.ds-crm-filter-client');
	const billForm = root.querySelector('.ds-crm-ledger-bill-form');

	const state = {
		section: root.dataset.ledgerSection || 'payments',
		page: 1,
		perPage: 25,
		search: '',
		clientId: '',
		dateFrom: '',
		dateTo: '',
		totalPages: 1,
	};

	const headers = {
		payments: ['Payment #', 'Client', 'Date', 'Amount', 'Method', 'Reference', 'Notes'],
		receives: ['Receive #', 'Date', 'Client', 'Order', 'Shipment', 'Total KG', 'Shipping bill'],
		bills: ['Date', 'Amount', 'Reference', 'Notes'],
	};

	const searchPlaceholders = {
		payments: 'Search payment #, client, method, reference…',
		receives: 'Search receive #, client, shipment…',
		bills: 'Search reference or notes…',
	};

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const companyTypeLabel = (type) => {
		if (type === 'local_supplier') return 'Local supplier';
		if (type === 'cargo') return 'Cargo';
		return type || '—';
	};

	const statCard = (label, value, extraClass = '') =>
		`<div class="ds-crm-stat-card ${extraClass}"><span class="ds-crm-stat-label">${escapeHtml(label)}</span><span class="ds-crm-stat-value">${value}</span></div>`;

	const syncSectionUrl = () => {
		const url = new URL(window.location.href);
		url.searchParams.set('ledger_section', state.section);
		window.history.replaceState({}, '', url.toString());
	};

	const fillClientFilter = (clients) => {
		if (!filterClient) {
			return;
		}
		const current = state.clientId;
		const options = (clients || [])
			.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
			.join('');
		filterClient.innerHTML = `<option value="">All clients</option>${options}`;
		if (current && [...filterClient.options].some((opt) => opt.value === String(current))) {
			filterClient.value = String(current);
		} else {
			state.clientId = '';
			filterClient.value = '';
		}
	};

	const setSection = (section) => {
		state.section = section;
		root.dataset.ledgerSection = section;
		root.querySelectorAll('.ds-crm-ledger-tabs [data-section]').forEach((btn) => {
			btn.classList.toggle('is-active', btn.dataset.section === section);
		});
		if (searchInput) {
			searchInput.placeholder = searchPlaceholders[section] || 'Search this table…';
		}
		if (filterClient) {
			filterClient.hidden = section !== 'receives' && section !== 'payments';
			if (section !== 'receives' && section !== 'payments') {
				state.clientId = '';
				filterClient.value = '';
			}
		}
		syncSectionUrl();
	};

	const renderHeader = () => {
		thead.innerHTML = `<tr>${(headers[state.section] || []).map((label) => `<th>${escapeHtml(label)}</th>`).join('')}</tr>`;
	};

	const colspan = () => (headers[state.section] || ['']).length;

	const renderRows = (items) => {
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="${colspan()}" class="ds-crm-empty">No records found.</td></tr>`;
			return;
		}

		if (state.section === 'payments') {
			tbody.innerHTML = items
				.map(
					(p) => `<tr>
						<td>${escapeHtml(p.payment_number || '—')}</td>
						<td>${escapeHtml(p.client_name || '—')}</td>
						<td class="ds-crm-datetime">${formatListDateTime(p, 'payment_date')}</td>
						<td class="ds-crm-amount-cell">${formatAmount(p.amount)}</td>
						<td>${escapeHtml((p.payment_method || '—').replace(/_/g, ' '))}</td>
						<td>${escapeHtml(p.reference || '—')}</td>
						<td>${escapeHtml(p.notes || '—')}</td>
					</tr>`
				)
				.join('');
			return;
		}

		if (state.section === 'receives') {
			tbody.innerHTML = items
				.map(
					(r) => `<tr>
						<td>${escapeHtml(r.receive_number || '—')}</td>
						<td>${formatDate(r.receive_date)}</td>
						<td>${escapeHtml(r.client_name || '—')}</td>
						<td>${escapeHtml(r.order_number || '—')}</td>
						<td>${escapeHtml(r.shipment_number || '—')}</td>
						<td>${formatWeight(r.total_kg, { withUnit: true })}</td>
						<td class="ds-crm-amount-cell">${formatAmount(r.shipping_bill)}</td>
					</tr>`
				)
				.join('');
			return;
		}

		tbody.innerHTML = items
			.map(
				(b) => `<tr>
					<td>${formatDate(b.bill_date)}</td>
					<td class="ds-crm-amount-cell">${formatAmount(b.amount)}</td>
					<td>${escapeHtml(b.reference || '—')}</td>
					<td>${escapeHtml(b.notes || '—')}</td>
				</tr>`
			)
			.join('');
	};

	const loadHeader = async () => {
		const result = await postAjax('crm_companies_ledger', { id: companyId });
		if (!result.success) {
			metaEl.textContent = result.data?.message || 'Failed to load company ledger.';
			return false;
		}

		const { company, summary } = result.data;
		if (titleEl) {
			titleEl.textContent = `Ledger — ${company.name}`;
		}
		document.title = `Ledger — ${company.name}`;
		metaEl.innerHTML = `<strong>${escapeHtml(company.name)}</strong> · ${escapeHtml(companyTypeLabel(company.company_type))}${
			company.phone ? ` · ${escapeHtml(company.phone)}` : ''
		}${company.contact_person ? ` · ${escapeHtml(company.contact_person)}` : ''}`;

		const dueClass = summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
		totalsStats.innerHTML = `
			${statCard('Company bill', formatAmount(summary.total_bill))}
			${statCard('Paid', formatAmount(summary.total_paid))}
			${statCard('Due', formatAmount(summary.total_due), dueClass)}
			${statCard('Receives', String(summary.receive_count || 0))}
		`;
		breakdownStats.innerHTML = `
			${statCard('Receive shipping', formatAmount(summary.shipping_bill))}
			${statCard('Manual bills', formatAmount(summary.manual_bills))}
		`;
		totalsWrap.hidden = false;
		breakdownWrap.hidden = false;
		return true;
	};

	const loadEntries = async () => {
		renderHeader();
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${colspan()}">Loading…</td></tr>`;

		const payload = {
			company_id: companyId,
			section: state.section,
			search: state.search,
			date_from: state.dateFrom,
			date_to: state.dateTo,
			page: state.page,
			per_page: state.perPage,
		};

		if ((state.section === 'receives' || state.section === 'payments') && state.clientId) {
			payload.client_id = state.clientId;
		}

		const result = await postAjax('crm_companies_ledger_entries', payload);

		if (!result.success) {
			tbody.innerHTML = `<tr><td colspan="${colspan()}">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		if (state.section === 'receives' || state.section === 'payments') {
			fillClientFilter(result.data.filter_clients || []);
		}

		renderRows(result.data.items || []);
		state.totalPages = result.data.total_pages || 1;
		paginationEl.hidden = state.totalPages <= 1 && !result.data.total;
		pageInfo.textContent = `Page ${result.data.page} of ${state.totalPages} (${result.data.total} total)`;
		prevBtn.disabled = state.page <= 1;
		nextBtn.disabled = state.page >= state.totalPages;
	};

	root.querySelectorAll('.ds-crm-ledger-tabs [data-section]').forEach((btn) => {
		btn.addEventListener('click', () => {
			state.page = 1;
			state.search = '';
			state.clientId = '';
			if (searchInput) {
				searchInput.value = '';
			}
			if (filterClient) {
				filterClient.value = '';
			}
			setSection(btn.dataset.section);
			loadEntries();
		});
	});

	searchInput?.addEventListener(
		'input',
		debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			loadEntries();
		})
	);

	filterClient?.addEventListener('change', (e) => {
		state.clientId = e.target.value;
		state.page = 1;
		loadEntries();
	});

	root.querySelector('.ds-crm-date-from')?.addEventListener('change', (e) => {
		state.dateFrom = e.target.value;
		state.page = 1;
		loadEntries();
	});

	root.querySelector('.ds-crm-date-to')?.addEventListener('change', (e) => {
		state.dateTo = e.target.value;
		state.page = 1;
		loadEntries();
	});

	root.querySelector('.ds-crm-per-page')?.addEventListener('change', (e) => {
		state.perPage = parseInt(e.target.value, 10);
		state.page = 1;
		loadEntries();
	});

	prevBtn?.addEventListener('click', () => {
		if (state.page > 1) {
			state.page -= 1;
			loadEntries();
		}
	});

	nextBtn?.addEventListener('click', () => {
		if (state.page < state.totalPages) {
			state.page += 1;
			loadEntries();
		}
	});

	billForm?.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (!canManageBilling) {
			return;
		}
		const amount = parseFloat(billForm.querySelector('[name="amount"]')?.value) || 0;
		if (amount <= 0) {
			DsCrmUI.toast('Enter a valid bill amount.', 'error');
			return;
		}

		const result = await postAjax('crm_companies_bill_save', {
			company_id: companyId,
			bill_date: billForm.querySelector('[name="bill_date"]')?.value,
			amount,
			reference: billForm.querySelector('[name="reference"]')?.value || '',
			notes: billForm.querySelector('[name="notes"]')?.value || '',
		});

		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Bill save failed.', 'error');
			return;
		}

		DsCrmUI.toast(result.data?.message || 'Bill recorded.');
		billForm.reset();
		const today = new Date().toISOString().slice(0, 10);
		billForm.querySelector('[name="bill_date"]').value = today;
		await loadHeader();
		if (state.section === 'bills') {
			state.page = 1;
			await loadEntries();
		}
	});

	const boot = async () => {
		if (companyId < 1) {
			metaEl.textContent = 'Invalid company.';
			return;
		}

		if (billForm?.querySelector('[name="bill_date"]')) {
			billForm.querySelector('[name="bill_date"]').value = new Date().toISOString().slice(0, 10);
		}

		setSection(state.section);
		const ok = await loadHeader();
		if (ok) {
			await loadEntries();
		}
	};

	boot();
})();
