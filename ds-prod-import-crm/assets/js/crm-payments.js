/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="payments"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, formatAmount, DsCrmUI, buildModuleUrl } = DsCrm;
	const isClient = root.dataset.isClient === '1';
	const canCreate = root.dataset.canCreate === '1';
	const canEdit = root.dataset.canEdit === '1';
	const canDelete = root.dataset.canDelete === '1';
	const showSuppliers = root.dataset.showSuppliers === '1';
	const canRecordSupplier = root.dataset.canRecordSupplier === '1';
	const colCount = isClient ? 6 : 8;
	const supplierColCount = 8;
	const clientsPanel = root.querySelector('.ds-crm-payments-clients');
	const suppliersPanel = root.querySelector('.ds-crm-payments-suppliers');
	const addClientBtn = root.querySelector('.ds-crm-btn-add-payment');
	const addSupplierBtn = root.querySelector('.ds-crm-btn-add-supplier-payment');

	const modal = document.getElementById('ds-crm-payment-modal');
	const form = modal?.querySelector('.ds-crm-payment-form');
	const tbody = clientsPanel?.querySelector('.ds-crm-payments-table tbody');
	const paginationEl = clientsPanel?.querySelector('.ds-crm-pagination');
	const pageInfo = clientsPanel?.querySelector('.ds-crm-page-info');
	const prevBtn = clientsPanel?.querySelector('.ds-crm-page-prev');
	const nextBtn = clientsPanel?.querySelector('.ds-crm-page-next');
	const errorBox = form?.querySelector('.ds-crm-form-error');
	const clientFilter = clientsPanel?.querySelector('.ds-crm-filter-client');
	const clientSelect = form?.querySelector('[name="client_id"]');
	const orderSelect = form?.querySelector('[name="order_id"]');
	const orderPreviewWrap = form?.querySelector('.ds-crm-payment-order-preview');
	const orderPreviewInner = form?.querySelector('.ds-crm-payment-order-preview-inner');
	const clientPreviewWrap = form?.querySelector('.ds-crm-payment-client-preview');
	const clientPreviewInner = form?.querySelector('.ds-crm-payment-client-preview-inner');
	const amountInput = form?.querySelector('[name="amount"]');
	const balanceWrap = clientsPanel?.querySelector('.ds-crm-client-payment-balance');
	const balanceStats = clientsPanel?.querySelector('.ds-crm-client-payment-balance-stats');

	const supplierModal = document.getElementById('ds-crm-supplier-payment-modal');
	const supplierForm = supplierModal?.querySelector('.ds-crm-supplier-payment-form');
	const supplierTbody = suppliersPanel?.querySelector('.ds-crm-supplier-payments-table tbody');
	const supplierPaginationEl = suppliersPanel?.querySelector('.ds-crm-pagination');
	const supplierPageInfo = suppliersPanel?.querySelector('.ds-crm-page-info');
	const supplierPrevBtn = suppliersPanel?.querySelector('.ds-crm-page-prev');
	const supplierNextBtn = suppliersPanel?.querySelector('.ds-crm-page-next');
	const supplierErrorBox = supplierForm?.querySelector('.ds-crm-form-error');
	const companyFilter = suppliersPanel?.querySelector('.ds-crm-filter-company');
	const companySelect = supplierForm?.querySelector('[name="company_id"]');
	const supplierPreviewWrap = supplierForm?.querySelector('.ds-crm-payment-supplier-preview');
	const supplierPreviewInner = supplierForm?.querySelector('.ds-crm-payment-supplier-preview-inner');
	const supplierAmountInput = supplierForm?.querySelector('[name="amount"]');

	const urlParams = new URLSearchParams(window.location.search);
	const presetClientId = urlParams.get('client_id') || '';
	const presetOrderId = urlParams.get('order_id') || '';
	const presetCompanyId = root.dataset.presetCompanyId || urlParams.get('company_id') || '';

	const state = {
		page: 1,
		perPage: 10,
		search: '',
		clientId: '',
		dateFrom: '',
		dateTo: '',
		period: '',
		totalPages: 1,
	};

	const supplierState = {
		page: 1,
		perPage: 10,
		search: '',
		companyId: presetCompanyId || '',
		dateFrom: '',
		dateTo: '',
		period: '',
		totalPages: 1,
	};

	const itemsCache = {};
	const supplierItemsCache = {};
	let clientsLoaded = false;
	let companiesLoaded = false;
	let lastSuggestedAmount = null;
	let lastSuggestedSupplierAmount = null;
	let activeTab = root.dataset.paymentsTab === 'suppliers' && showSuppliers ? 'suppliers' : 'clients';
	let supplierListLoaded = false;
	let clientSummary = [];
	let supplierSummary = [];

	if (modal) {
		DsCrmUI.wireModal(modal);
	}
	if (supplierModal) {
		DsCrmUI.wireModal(supplierModal);
	}

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const formatMethod = (method) => {
		if (!method) {
			return '—';
		}
		return method.replace(/_/g, ' ');
	};

	const companyTypeLabel = (type) => {
		if (type === 'local_supplier') return 'Local supplier';
		if (type === 'cargo') return 'Cargo';
		return type || 'Company';
	};

	const setFormError = (box, message) => {
		if (!box) {
			return;
		}
		if (!message) {
			box.hidden = true;
			box.textContent = '';
			return;
		}
		box.hidden = false;
		box.textContent = message;
	};

	const syncTabUrl = (tab) => {
		const url = new URL(window.location.href);
		if (tab === 'suppliers') {
			url.searchParams.set('payments_tab', 'suppliers');
		} else {
			url.searchParams.delete('payments_tab');
		}
		window.history.replaceState({}, '', url.toString());
	};

	const setActiveTab = (tab, { updateUrl = true } = {}) => {
		if (!showSuppliers && tab === 'suppliers') {
			tab = 'clients';
		}
		activeTab = tab;
		root.dataset.paymentsTab = tab;

		root.querySelectorAll('.ds-crm-payments-tabs [data-tab]').forEach((btn) => {
			const isActive = btn.dataset.tab === tab;
			btn.classList.toggle('is-active', isActive);
			btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		if (clientsPanel) {
			clientsPanel.hidden = tab !== 'clients';
		}
		if (suppliersPanel) {
			suppliersPanel.hidden = tab !== 'suppliers';
		}
		if (addClientBtn) {
			addClientBtn.hidden = tab !== 'clients';
		}
		if (addSupplierBtn) {
			addSupplierBtn.hidden = tab !== 'suppliers';
		}

		if (updateUrl) {
			syncTabUrl(tab);
		}

		paintSummary();
	};

	const paintSummary = () => {
		if (isClient) {
			return;
		}
		const cards = activeTab === 'suppliers' ? supplierSummary : clientSummary;
		const period = activeTab === 'suppliers' ? supplierState.period : state.period;
		DsCrmUI.renderModuleSummary(root, cards, {
			onCardClick: (payload) => {
				const next = payload?.period || '';
				if (!next) {
					return;
				}
				applyPeriodFilter(next);
			},
		});
		DsCrmUI.syncModuleSummaryFilter(root, '', '', period || 'all');
	};

	const applyPeriodFilter = (period) => {
		if (activeTab === 'suppliers') {
			supplierState.period = supplierState.period === period ? '' : period === 'all' ? '' : period;
			if (supplierState.period) {
				supplierState.dateFrom = '';
				supplierState.dateTo = '';
				const fromEl = suppliersPanel?.querySelector('.ds-crm-date-from');
				const toEl = suppliersPanel?.querySelector('.ds-crm-date-to');
				if (fromEl) fromEl.value = '';
				if (toEl) toEl.value = '';
			}
			supplierState.page = 1;
			loadSupplierList();
			return;
		}

		state.period = state.period === period ? '' : period === 'all' ? '' : period;
		if (state.period) {
			state.dateFrom = '';
			state.dateTo = '';
			const fromEl = clientsPanel?.querySelector('.ds-crm-date-from');
			const toEl = clientsPanel?.querySelector('.ds-crm-date-to');
			if (fromEl) fromEl.value = '';
			if (toEl) toEl.value = '';
		}
		state.page = 1;
		loadList();
	};

	const clearOrderPreview = () => {
		if (orderPreviewWrap) {
			orderPreviewWrap.hidden = true;
		}
		if (orderPreviewInner) {
			orderPreviewInner.innerHTML = '';
		}
	};

	const clearClientPreview = () => {
		if (clientPreviewWrap) {
			clientPreviewWrap.hidden = true;
		}
		if (clientPreviewInner) {
			clientPreviewInner.innerHTML = '';
		}
	};

	const clearSupplierPreview = () => {
		if (supplierPreviewWrap) {
			supplierPreviewWrap.hidden = true;
		}
		if (supplierPreviewInner) {
			supplierPreviewInner.innerHTML = '';
		}
	};

	const renderClientPreview = (client, summary, openOrders) => {
		if (!clientPreviewWrap || !clientPreviewInner) {
			return;
		}

		const stat = (label, value, mod = '') =>
			`<div class="ds-crm-stat-card ${mod}"><span class="ds-crm-stat-label">${label}</span><span class="ds-crm-stat-value">${value}</span></div>`;

		clientPreviewInner.innerHTML = `
			<div class="ds-crm-payment-order-preview-head">
				<strong>${escapeHtml(client.name)}</strong>
				${client.phone ? `<span class="description">${escapeHtml(client.phone)}</span>` : ''}
			</div>
			<p class="description">${openOrders} open order${openOrders === 1 ? '' : 's'} — payments apply to the whole client balance (oldest order first).</p>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${stat('Total bill', formatAmount(summary.total_bill))}
				${stat('Paid', formatAmount(summary.total_paid))}
				${stat('Due', formatAmount(summary.total_due), summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok')}
			</div>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${stat('Order due', formatAmount(summary.order_due), summary.order_due > 0 ? 'ds-crm-stat-card--due' : '')}
				${stat('Delivery due', formatAmount(summary.delivery_due), summary.delivery_due > 0 ? 'ds-crm-stat-card--due' : '')}
			</div>
		`;
		clientPreviewWrap.hidden = false;
	};

	const loadClientPreview = async (clientId, { suggestAmount = false } = {}) => {
		if (!clientId) {
			clearClientPreview();
			return;
		}

		if (clientPreviewInner) {
			clientPreviewInner.innerHTML = '<p class="ds-crm-preview-loading">Loading client balance…</p>';
			clientPreviewWrap.hidden = false;
		}

		const result = await postAjax('crm_payments_client_preview', { client_id: clientId });
		if (!result.success) {
			clearClientPreview();
			return;
		}

		const { client, summary, open_orders: openOrders } = result.data;
		renderClientPreview(client, summary, openOrders || 0);

		if (suggestAmount && amountInput && summary.total_due > 0) {
			amountInput.value = parseFloat(summary.total_due).toFixed(2);
			lastSuggestedAmount = amountInput.value;
		}
	};

	const renderOrderPreview = (order, summary, itemCount, clientSummary = null) => {
		if (!orderPreviewWrap || !orderPreviewInner) {
			return;
		}

		const stat = (label, value, mod = '') =>
			`<div class="ds-crm-stat-card ${mod}"><span class="ds-crm-stat-label">${label}</span><span class="ds-crm-stat-value">${value}</span></div>`;

		orderPreviewInner.innerHTML = `
			<div class="ds-crm-payment-order-preview-head">
				<strong>${escapeHtml(order.order_number)}</strong>
				<span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(order.status_label || order.status)}</span>
			</div>
			<div class="ds-crm-order-meta-grid ds-crm-order-meta-grid--compact">
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Client</span>
					<span class="ds-crm-meta-value">${escapeHtml(order.client_name || '—')}</span>
				</div>
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Order date</span>
					<span class="ds-crm-meta-value">${formatDate(order.order_date)}</span>
				</div>
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Line items</span>
					<span class="ds-crm-meta-value">${itemCount}</span>
				</div>
			</div>
			<p class="description">Allocated share of client payments for this order (oldest orders paid first).</p>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${stat('Total bill', formatAmount(summary.total_bill))}
				${stat('Paid', formatAmount(summary.total_paid))}
				${stat('Due', formatAmount(summary.total_due), summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok')}
			</div>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${stat('Order bill', formatAmount(summary.order_bill))}
				${stat('Delivery bill', formatAmount(summary.delivery_bill))}
				${stat('Order due', formatAmount(summary.order_due), summary.order_due > 0 ? 'ds-crm-stat-card--due' : '')}
				${stat('Delivery due', formatAmount(summary.delivery_due), summary.delivery_due > 0 ? 'ds-crm-stat-card--due' : '')}
			</div>
			${clientSummary ? `<p class="description">Client total due (all orders): ${formatAmount(clientSummary.total_due)}</p>` : ''}
			${order.notes ? `<p class="ds-crm-payment-order-note"><span class="ds-crm-meta-label">Notes</span> ${escapeHtml(order.notes)}</p>` : ''}
		`;
		orderPreviewWrap.hidden = false;
	};

	const loadOrderPreview = async (orderId, { suggestAmount = false } = {}) => {
		if (!orderId) {
			clearOrderPreview();
			return;
		}

		if (orderPreviewInner) {
			orderPreviewInner.innerHTML = '<p class="ds-crm-preview-loading">Loading order…</p>';
			orderPreviewWrap.hidden = false;
		}

		const result = await postAjax('crm_payments_order_preview', { order_id: orderId });
		if (!result.success) {
			clearOrderPreview();
			DsCrmUI.toast(result.data?.message || 'Could not load order details.', 'error');
			return;
		}

		const { order, summary, item_count: itemCount, client_summary: clientSummary } = result.data;
		clearClientPreview();
		renderOrderPreview(order, summary, itemCount, clientSummary);

		if (suggestAmount && amountInput && summary.total_due > 0) {
			amountInput.value = parseFloat(summary.total_due).toFixed(2);
			lastSuggestedAmount = amountInput.value;
		}
	};

	const populateClientOptions = (clients, selectEl, includeEmpty = true) => {
		if (!selectEl) {
			return;
		}

		const current = selectEl.value;
		const emptyLabel = selectEl === clientFilter ? 'All clients' : 'Select client…';

		selectEl.innerHTML = includeEmpty ? `<option value="">${emptyLabel}</option>` : '';

		(clients || []).forEach((client) => {
			const opt = document.createElement('option');
			opt.value = String(client.id);
			opt.textContent = client.phone ? `${client.name} (${client.phone})` : client.name;
			selectEl.appendChild(opt);
		});

		if (current) {
			selectEl.value = current;
		}
	};

	const populateCompanyOptions = (companies, selectEl) => {
		if (!selectEl) {
			return;
		}

		const current = selectEl.value;
		const emptyLabel = selectEl === companyFilter ? 'All companies' : 'Select company…';
		selectEl.innerHTML = `<option value="">${emptyLabel}</option>`;

		(companies || []).forEach((company) => {
			const opt = document.createElement('option');
			opt.value = String(company.id);
			const type = companyTypeLabel(company.company_type);
			opt.textContent = company.phone ? `${company.name} (${type})` : `${company.name} — ${type}`;
			if (company.status && company.status !== 'active') {
				opt.textContent += ` [${company.status}]`;
			}
			selectEl.appendChild(opt);
		});

		if (current) {
			selectEl.value = current;
		}
	};

	const populateOrderOptions = (orders, selectedId = '') => {
		if (!orderSelect) {
			return;
		}

		orderSelect.innerHTML = '<option value="">General client payment</option>';
		(orders || []).forEach((order) => {
			const opt = document.createElement('option');
			opt.value = String(order.id);
			opt.textContent = `${order.order_number} — ${formatDate(order.order_date)} (${order.status})`;
			orderSelect.appendChild(opt);
		});

		if (selectedId) {
			orderSelect.value = String(selectedId);
		}
	};

	const loadFormData = async (clientId = 0) => {
		const payload = clientId ? { client_id: clientId } : {};
		const result = await postAjax('crm_payments_form_data', payload);
		if (!result.success) {
			return null;
		}
		return result.data;
	};

	const ensureClients = async () => {
		if (clientsLoaded) {
			return;
		}

		const data = await loadFormData();
		if (!data) {
			return;
		}

		populateClientOptions(data.clients, clientFilter);
		populateClientOptions(data.clients, clientSelect);
		clientsLoaded = true;
	};

	const ensureCompanies = async () => {
		if (companiesLoaded || !showSuppliers) {
			return;
		}

		const result = await postAjax('crm_payments_supplier_form_data', {});
		if (!result.success) {
			return;
		}

		populateCompanyOptions(result.data.companies, companyFilter);
		populateCompanyOptions(result.data.companies, companySelect);
		if (supplierState.companyId && companyFilter) {
			companyFilter.value = String(supplierState.companyId);
		}
		companiesLoaded = true;
	};

	const loadClientOrders = async (clientId, selectedOrderId = '', { suggestAmount = false } = {}) => {
		clearOrderPreview();
		if (!clientId) {
			populateOrderOptions([]);
			clearClientPreview();
			return;
		}

		const data = await loadFormData(clientId);
		populateOrderOptions(data?.orders || [], selectedOrderId);

		if (selectedOrderId) {
			await loadOrderPreview(selectedOrderId, { suggestAmount });
		} else {
			await loadClientPreview(clientId, { suggestAmount });
		}
	};

	const renderSupplierPreview = (company, summary) => {
		if (!supplierPreviewWrap || !supplierPreviewInner) {
			return;
		}

		const dueClass = summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
		const ledgerHref = buildModuleUrl('companies', { company_action: 'ledger', company_id: company.id });
		const stat = (label, value, mod = '') =>
			`<div class="ds-crm-stat-card ${mod}"><span class="ds-crm-stat-label">${label}</span><span class="ds-crm-stat-value">${value}</span></div>`;

		supplierPreviewInner.innerHTML = `
			<div class="ds-crm-payment-order-preview-head">
				<strong>${escapeHtml(company.name)}</strong>
				<span class="description">${escapeHtml(companyTypeLabel(company.company_type))}${company.phone ? ` · ${escapeHtml(company.phone)}` : ''}</span>
			</div>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact">
				${stat('Company bill', formatAmount(summary.total_bill))}
				${stat('Paid', formatAmount(summary.total_paid))}
				${stat('Due', formatAmount(summary.total_due), dueClass)}
			</div>
			<p class="description"><a href="${ledgerHref}">Open full company ledger</a></p>
		`;
		supplierPreviewWrap.hidden = false;
	};

	const loadSupplierPreview = async (companyId, { suggestAmount = false } = {}) => {
		if (!companyId) {
			clearSupplierPreview();
			return;
		}

		if (supplierPreviewInner) {
			supplierPreviewInner.innerHTML = '<p class="ds-crm-preview-loading">Loading company balance…</p>';
			supplierPreviewWrap.hidden = false;
		}

		const result = await postAjax('crm_payments_supplier_preview', { company_id: companyId });
		if (!result.success) {
			clearSupplierPreview();
			return;
		}

		const { company, summary } = result.data;
		renderSupplierPreview(company, summary);

		if (suggestAmount && supplierAmountInput && summary.total_due > 0) {
			supplierAmountInput.value = parseFloat(summary.total_due).toFixed(2);
			lastSuggestedSupplierAmount = supplierAmountInput.value;
		}
	};

	const renderClientBalance = (summary) => {
		if (!isClient || !balanceWrap || !balanceStats || !summary) {
			if (balanceWrap) {
				balanceWrap.hidden = true;
			}
			return;
		}

		const dueClass = summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
		const paidClass = summary.total_paid > 0 ? 'ds-crm-stat-card--ok' : '';
		balanceStats.innerHTML = `
			<div class="ds-crm-stat-card"><span class="ds-crm-stat-label">Total bill</span><span class="ds-crm-stat-value">${formatAmount(summary.total_bill)}</span></div>
			<div class="ds-crm-stat-card ${paidClass}"><span class="ds-crm-stat-label">Total paid</span><span class="ds-crm-stat-value">${formatAmount(summary.total_paid)}</span></div>
			<div class="ds-crm-stat-card ${dueClass}"><span class="ds-crm-stat-label">Total due</span><span class="ds-crm-stat-value">${formatAmount(summary.total_due)}</span></div>
			<div class="ds-crm-stat-card"><span class="ds-crm-stat-label">Order due</span><span class="ds-crm-stat-value">${formatAmount(summary.order_due)}</span></div>
			<div class="ds-crm-stat-card"><span class="ds-crm-stat-label">Delivery due</span><span class="ds-crm-stat-value">${formatAmount(summary.delivery_due)}</span></div>
		`;
		balanceWrap.hidden = false;
	};

	const renderRows = (items) => {
		if (!tbody) {
			return;
		}
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="${colCount}" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No payments found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map((item) => {
				itemsCache[item.id] = item;
				const clientCell = isClient ? '' : `<td>${escapeHtml(item.client_name || '—')}</td>`;
				const actionsCell = isClient
					? ''
					: `<td class="ds-crm-actions">
					${DsCrmUI.wrapActions(
						canEdit
							? DsCrmUI.actionButton('Edit', 'edit', { className: 'ds-crm-edit', attrs: `data-id="${item.id}"`, iconOnly: true })
							: '',
						canDelete
							? DsCrmUI.actionButton('Delete', 'delete', { className: 'ds-crm-delete', attrs: `data-id="${item.id}"`, iconOnly: true })
							: ''
					)}
				</td>`;
				return `
			<tr>
				<td>${escapeHtml(item.payment_number)}</td>
				${clientCell}
				<td>${escapeHtml(item.order_number || '—')}</td>
				<td class="ds-crm-datetime">${formatListDateTime(item, 'payment_date')}</td>
				<td class="ds-crm-amount-cell">${formatAmount(item.amount)}</td>
				<td>${escapeHtml(formatMethod(item.payment_method))}</td>
				<td>${escapeHtml(item.reference || '—')}</td>
				${actionsCell}
			</tr>`;
			})
			.join('');
	};

	const loadList = async () => {
		if (!tbody) {
			return;
		}
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${colCount}">Loading…</td></tr>`;

		const result = await postAjax('crm_payments_list', {
			page: state.page,
			per_page: state.perPage,
			search: state.search,
			client_id: state.clientId,
			date_from: state.period ? '' : state.dateFrom,
			date_to: state.period ? '' : state.dateTo,
			period: state.period || 'all',
		});

		if (!result.success) {
			tbody.innerHTML = `<tr><td colspan="${colCount}">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		renderRows(result.data.items || []);
		renderClientBalance(result.data.client_balance);
		if (!isClient) {
			clientSummary = result.data.summary || [];
			if (activeTab === 'clients') {
				paintSummary();
			}
		}
		state.totalPages = result.data.total_pages || 1;

		if (paginationEl) {
			paginationEl.hidden = state.totalPages <= 1 && !result.data.total;
			pageInfo.textContent = `Page ${result.data.page} of ${state.totalPages} (${result.data.total} total)`;
			prevBtn.disabled = state.page <= 1;
			nextBtn.disabled = state.page >= state.totalPages;
		}
	};

	const renderSupplierRows = (items) => {
		if (!supplierTbody) {
			return;
		}
		if (!items.length) {
			supplierTbody.innerHTML = `<tr><td colspan="${supplierColCount}" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No supplier payments found.'}</td></tr>`;
			return;
		}

		supplierTbody.innerHTML = items
			.map((item) => {
				supplierItemsCache[item.id] = item;
				const ledgerHref = buildModuleUrl('companies', {
					company_action: 'ledger',
					company_id: item.company_id,
				});
				const actionsCell = `<td class="ds-crm-actions">
					${DsCrmUI.wrapActions(
						canRecordSupplier
							? DsCrmUI.actionButton('Edit', 'edit', { className: 'ds-crm-supplier-edit', attrs: `data-id="${item.id}"`, iconOnly: true })
							: '',
						canRecordSupplier
							? DsCrmUI.actionButton('Delete', 'delete', { className: 'ds-crm-supplier-delete', attrs: `data-id="${item.id}"`, iconOnly: true })
							: '',
						DsCrmUI.actionButton('Ledger', 'open', { tag: 'a', attrs: `href="${ledgerHref}"` })
					)}
				</td>`;
				return `
			<tr>
				<td>${escapeHtml(item.payment_number || '—')}</td>
				<td><a href="${ledgerHref}">${escapeHtml(item.company_name || '—')}</a></td>
				<td class="ds-crm-datetime">${formatListDateTime(item, 'payment_date')}</td>
				<td class="ds-crm-amount-cell">${formatAmount(item.amount)}</td>
				<td>${escapeHtml(formatMethod(item.payment_method))}</td>
				<td>${escapeHtml(item.reference || '—')}</td>
				<td>${escapeHtml(item.notes || '—')}</td>
				${actionsCell}
			</tr>`;
			})
			.join('');
	};

	const loadSupplierList = async () => {
		if (!supplierTbody) {
			return;
		}
		supplierTbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${supplierColCount}">Loading…</td></tr>`;

		const result = await postAjax('crm_payments_supplier_list', {
			page: supplierState.page,
			per_page: supplierState.perPage,
			search: supplierState.search,
			company_id: supplierState.companyId,
			date_from: supplierState.period ? '' : supplierState.dateFrom,
			date_to: supplierState.period ? '' : supplierState.dateTo,
			period: supplierState.period || 'all',
		});

		if (!result.success) {
			supplierTbody.innerHTML = `<tr><td colspan="${supplierColCount}">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		renderSupplierRows(result.data.items || []);
		supplierSummary = result.data.summary || [];
		supplierState.totalPages = result.data.total_pages || 1;
		supplierListLoaded = true;
		if (activeTab === 'suppliers') {
			paintSummary();
		}

		if (supplierPaginationEl) {
			supplierPaginationEl.hidden = supplierState.totalPages <= 1 && !result.data.total;
			supplierPageInfo.textContent = `Page ${result.data.page} of ${supplierState.totalPages} (${result.data.total} total)`;
			supplierPrevBtn.disabled = supplierState.page <= 1;
			supplierNextBtn.disabled = supplierState.page >= supplierState.totalPages;
		}
	};

	const resetPaymentForm = async () => {
		setFormError(errorBox, '');
		form.reset();
		form.querySelector('[name="id"]').value = '';
		document.getElementById('ds-crm-payment-modal-title').textContent = 'Record client payment';
		await ensureClients();
		populateOrderOptions([]);
		clearOrderPreview();
		clearClientPreview();
		const today = new Date().toISOString().slice(0, 10);
		form.querySelector('[name="payment_date"]').value = today;
		lastSuggestedAmount = null;
	};

	const openForm = async (id = 0, trigger = null, preset = {}) => {
		if (id) {
			setFormError(errorBox, '');
			form.reset();
			form.querySelector('[name="id"]').value = String(id);
			document.getElementById('ds-crm-payment-modal-title').textContent = 'Edit client payment';

			const item = itemsCache[id];
			if (!item) {
				DsCrmUI.toast('Payment data not found. Refresh the list.', 'error');
				return;
			}

			DsCrmUI.openModal(modal);
			DsCrmUI.setButtonLoading(trigger, true);
			DsCrmUI.showModalLoading(modal, 'Loading payment…');
			try {
				await ensureClients();
				clientSelect.value = String(item.client_id);
				await loadClientOrders(item.client_id, item.order_id || '');
				form.querySelector('[name="payment_date"]').value = item.payment_date || '';
				form.querySelector('[name="amount"]').value = item.amount || '';
				form.querySelector('[name="payment_method"]').value = item.payment_method || '';
				form.querySelector('[name="reference"]').value = item.reference || '';
				form.querySelector('[name="notes"]').value = item.notes || '';
				if (item.order_id) {
					await loadOrderPreview(item.order_id);
				}
			} finally {
				DsCrmUI.hideModalLoading(modal);
				DsCrmUI.setButtonLoading(trigger, false);
			}
			return;
		}

		await DsCrmUI.runModalAction({
			modal,
			trigger,
			label: 'Loading form…',
			task: async () => {
				await resetPaymentForm();

				const clientId = preset.clientId || presetClientId;
				const orderId = preset.orderId || presetOrderId;

				if (clientId) {
					clientSelect.value = String(clientId);
					await loadClientOrders(clientId, orderId || '', { suggestAmount: true });
				}

				return true;
			},
		});
	};

	const saveForm = async (event) => {
		event.preventDefault();
		setFormError(errorBox, '');

		const clientId = parseInt(clientSelect.value, 10);
		const paymentDate = form.querySelector('[name="payment_date"]').value;
		const amount = parseFloat(form.querySelector('[name="amount"]').value);

		if (!clientId || !paymentDate || !amount || amount <= 0) {
			setFormError(errorBox, 'Client, date, and a valid amount are required.');
			return;
		}

		form.querySelectorAll('.ds-crm-invalid').forEach((el) => el.classList.remove('ds-crm-invalid'));

		const payload = {
			id: form.querySelector('[name="id"]').value,
			client_id: clientId,
			order_id: form.querySelector('[name="order_id"]').value || 0,
			payment_date: paymentDate,
			amount,
			payment_method: form.querySelector('[name="payment_method"]').value,
			reference: form.querySelector('[name="reference"]').value,
			notes: form.querySelector('[name="notes"]').value,
		};

		const result = await postAjax('crm_payments_save', payload);
		if (!result.success) {
			setFormError(errorBox, result.data?.message || 'Save failed.');
			return;
		}

		DsCrm.clearAjaxGetCache('crm_payments_');
		DsCrmUI.toast(result.data.message || 'Saved.');
		DsCrmUI.closeModal(modal);
		await loadList();
	};

	const deleteItem = async (id) => {
		if (!window.confirm('Delete this payment?')) {
			return;
		}

		const result = await postAjax('crm_payments_delete', { id });
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Delete failed.', 'error');
			return;
		}

		delete itemsCache[id];
		DsCrm.clearAjaxGetCache('crm_payments_');
		DsCrmUI.toast(result.data.message || 'Deleted.');
		await loadList();
	};

	const resetSupplierForm = async () => {
		setFormError(supplierErrorBox, '');
		supplierForm.reset();
		supplierForm.querySelector('[name="id"]').value = '';
		document.getElementById('ds-crm-supplier-payment-modal-title').textContent = 'Record payment to supplier';
		await ensureCompanies();
		clearSupplierPreview();
		const today = new Date().toISOString().slice(0, 10);
		supplierForm.querySelector('[name="payment_date"]').value = today;
		lastSuggestedSupplierAmount = null;
	};

	const openSupplierForm = async (id = 0, trigger = null, preset = {}) => {
		if (!supplierForm) {
			return;
		}

		if (id) {
			const item = supplierItemsCache[id];
			if (!item) {
				DsCrmUI.toast('Payment data not found. Refresh the list.', 'error');
				return;
			}

			DsCrmUI.openModal(supplierModal);
			DsCrmUI.setButtonLoading(trigger, true);
			DsCrmUI.showModalLoading(supplierModal, 'Loading payment…');
			try {
				await ensureCompanies();
				setFormError(supplierErrorBox, '');
				supplierForm.reset();
				supplierForm.querySelector('[name="id"]').value = String(id);
				document.getElementById('ds-crm-supplier-payment-modal-title').textContent = 'Edit supplier payment';
				companySelect.value = String(item.company_id);
				supplierForm.querySelector('[name="payment_date"]').value = item.payment_date || '';
				supplierForm.querySelector('[name="amount"]').value = item.amount || '';
				const methodSelect = supplierForm.querySelector('[name="payment_method"]');
				const methodValue = item.payment_method || '';
				if (methodValue && methodSelect && !Array.from(methodSelect.options).some((opt) => opt.value === methodValue)) {
					const opt = document.createElement('option');
					opt.value = methodValue;
					opt.textContent = methodValue.replace(/_/g, ' ');
					methodSelect.appendChild(opt);
				}
				methodSelect.value = methodValue;
				supplierForm.querySelector('[name="reference"]').value = item.reference || '';
				supplierForm.querySelector('[name="notes"]').value = item.notes || '';
				await loadSupplierPreview(item.company_id);
			} finally {
				DsCrmUI.hideModalLoading(supplierModal);
				DsCrmUI.setButtonLoading(trigger, false);
			}
			return;
		}

		await DsCrmUI.runModalAction({
			modal: supplierModal,
			trigger,
			label: 'Loading form…',
			task: async () => {
				await resetSupplierForm();
				const companyId = preset.companyId || presetCompanyId;
				if (companyId) {
					companySelect.value = String(companyId);
					await loadSupplierPreview(companyId, { suggestAmount: true });
				}
				return true;
			},
		});
	};

	const saveSupplierForm = async (event) => {
		event.preventDefault();
		setFormError(supplierErrorBox, '');

		const companyId = parseInt(companySelect.value, 10);
		const paymentDate = supplierForm.querySelector('[name="payment_date"]').value;
		const amount = parseFloat(supplierForm.querySelector('[name="amount"]').value);

		if (!companyId || !paymentDate || !amount || amount <= 0) {
			setFormError(supplierErrorBox, 'Company, date, and a valid amount are required.');
			return;
		}

		const result = await postAjax('crm_payments_supplier_save', {
			id: supplierForm.querySelector('[name="id"]').value,
			company_id: companyId,
			payment_date: paymentDate,
			amount,
			payment_method: supplierForm.querySelector('[name="payment_method"]').value,
			reference: supplierForm.querySelector('[name="reference"]').value,
			notes: supplierForm.querySelector('[name="notes"]').value,
		});

		if (!result.success) {
			setFormError(supplierErrorBox, result.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Saved.');
		DsCrmUI.closeModal(supplierModal);
		await loadSupplierList();
	};

	const deleteSupplierItem = async (id) => {
		if (!window.confirm('Delete this supplier payment?')) {
			return;
		}

		const result = await postAjax('crm_payments_supplier_delete', { id });
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Delete failed.', 'error');
			return;
		}

		delete supplierItemsCache[id];
		DsCrmUI.toast(result.data.message || 'Deleted.');
		await loadSupplierList();
	};

	addClientBtn?.addEventListener('click', () => openForm());
	addSupplierBtn?.addEventListener('click', () => openSupplierForm());

	root.querySelectorAll('.ds-crm-payments-tabs [data-tab]').forEach((btn) => {
		btn.addEventListener('click', async () => {
			const tab = btn.dataset.tab;
			setActiveTab(tab);
			if (tab === 'suppliers') {
				await ensureCompanies();
				if (!supplierListLoaded) {
					await loadSupplierList();
				}
			} else {
				await loadList();
			}
		});
	});

	clientsPanel?.querySelector('.ds-crm-search')?.addEventListener(
		'input',
		debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			loadList();
		})
	);

	clientFilter?.addEventListener('change', (e) => {
		state.clientId = e.target.value;
		state.page = 1;
		loadList();
	});

	clientsPanel?.querySelector('.ds-crm-date-from')?.addEventListener('change', (e) => {
		state.dateFrom = e.target.value;
		state.period = '';
		state.page = 1;
		loadList();
	});

	clientsPanel?.querySelector('.ds-crm-date-to')?.addEventListener('change', (e) => {
		state.dateTo = e.target.value;
		state.period = '';
		state.page = 1;
		loadList();
	});

	clientsPanel?.querySelector('.ds-crm-per-page')?.addEventListener('change', (e) => {
		state.perPage = parseInt(e.target.value, 10);
		state.page = 1;
		loadList();
	});

	prevBtn?.addEventListener('click', () => {
		if (state.page > 1) {
			state.page -= 1;
			loadList();
		}
	});

	nextBtn?.addEventListener('click', () => {
		if (state.page < state.totalPages) {
			state.page += 1;
			loadList();
		}
	});

	clientSelect?.addEventListener('change', (e) => {
		const clientId = parseInt(e.target.value, 10) || 0;
		const suggestAmount = !form.querySelector('[name="id"]').value;
		loadClientOrders(clientId, '', { suggestAmount });
	});

	orderSelect?.addEventListener('change', (e) => {
		const orderId = parseInt(e.target.value, 10) || 0;
		const clientId = parseInt(clientSelect?.value, 10) || 0;
		const suggestAmount = !form.querySelector('[name="id"]').value;
		if (!orderId) {
			clearOrderPreview();
			loadClientPreview(clientId, { suggestAmount });
			return;
		}
		if (suggestAmount && amountInput?.value && lastSuggestedAmount && amountInput.value !== lastSuggestedAmount) {
			loadOrderPreview(orderId);
			return;
		}
		loadOrderPreview(orderId, { suggestAmount });
	});

	tbody?.addEventListener('click', (e) => {
		const editBtn = e.target.closest('.ds-crm-edit');
		const deleteBtn = e.target.closest('.ds-crm-delete');
		if (editBtn) {
			openForm(parseInt(editBtn.dataset.id, 10), editBtn);
		}
		if (deleteBtn) {
			deleteItem(parseInt(deleteBtn.dataset.id, 10));
		}
	});

	form?.addEventListener('submit', saveForm);

	suppliersPanel?.querySelector('.ds-crm-search')?.addEventListener(
		'input',
		debounce((e) => {
			supplierState.search = e.target.value.trim();
			supplierState.page = 1;
			loadSupplierList();
		})
	);

	companyFilter?.addEventListener('change', (e) => {
		supplierState.companyId = e.target.value;
		supplierState.page = 1;
		loadSupplierList();
	});

	suppliersPanel?.querySelector('.ds-crm-date-from')?.addEventListener('change', (e) => {
		supplierState.dateFrom = e.target.value;
		supplierState.period = '';
		supplierState.page = 1;
		loadSupplierList();
	});

	suppliersPanel?.querySelector('.ds-crm-date-to')?.addEventListener('change', (e) => {
		supplierState.dateTo = e.target.value;
		supplierState.period = '';
		supplierState.page = 1;
		loadSupplierList();
	});

	suppliersPanel?.querySelector('.ds-crm-per-page')?.addEventListener('change', (e) => {
		supplierState.perPage = parseInt(e.target.value, 10);
		supplierState.page = 1;
		loadSupplierList();
	});

	supplierPrevBtn?.addEventListener('click', () => {
		if (supplierState.page > 1) {
			supplierState.page -= 1;
			loadSupplierList();
		}
	});

	supplierNextBtn?.addEventListener('click', () => {
		if (supplierState.page < supplierState.totalPages) {
			supplierState.page += 1;
			loadSupplierList();
		}
	});

	companySelect?.addEventListener('change', (e) => {
		const companyId = parseInt(e.target.value, 10) || 0;
		const suggestAmount = !supplierForm.querySelector('[name="id"]').value;
		if (
			suggestAmount &&
			supplierAmountInput?.value &&
			lastSuggestedSupplierAmount &&
			supplierAmountInput.value !== lastSuggestedSupplierAmount
		) {
			loadSupplierPreview(companyId);
			return;
		}
		loadSupplierPreview(companyId, { suggestAmount });
	});

	supplierTbody?.addEventListener('click', (e) => {
		const editBtn = e.target.closest('.ds-crm-supplier-edit');
		const deleteBtn = e.target.closest('.ds-crm-supplier-delete');
		if (editBtn) {
			openSupplierForm(parseInt(editBtn.dataset.id, 10), editBtn);
		}
		if (deleteBtn) {
			deleteSupplierItem(parseInt(deleteBtn.dataset.id, 10));
		}
	});

	supplierForm?.addEventListener('submit', saveSupplierForm);

	const boot = async () => {
		setActiveTab(activeTab, { updateUrl: false });

		if (!isClient) {
			await ensureClients();
		}

		if (activeTab === 'suppliers') {
			await ensureCompanies();
			await loadSupplierList();
			if (presetCompanyId && canRecordSupplier) {
				await openSupplierForm(0, null, { companyId: presetCompanyId });
			}
		} else {
			await loadList();
			if (!isClient && (presetClientId || presetOrderId)) {
				await openForm(0, null, {
					clientId: presetClientId,
					orderId: presetOrderId,
				});
			}
		}
	};

	boot();
})();
