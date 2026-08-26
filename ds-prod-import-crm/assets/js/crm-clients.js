/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="clients"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, formatAmount, buildModuleUrl, DsCrmUI } = DsCrm;
	const canCreate = root.dataset.canCreate === '1';
	const canEdit = root.dataset.canEdit === '1';
	const canDelete = root.dataset.canDelete === '1';
	const modal = document.getElementById('ds-crm-client-modal');
	const ledgerModal = document.getElementById('ds-crm-client-ledger-modal');
	const ledgerBody = ledgerModal?.querySelector('.ds-crm-ledger-modal-body');
	const form = modal?.querySelector('.ds-crm-client-form');
	const tbody = root.querySelector('.ds-crm-clients-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');
	const errorBox = form?.querySelector('.ds-crm-form-error');
	const portalUserSelect = form?.querySelector('[name="wp_user_id"]');

	const state = {
		page: 1,
		perPage: 10,
		sortBy: 'created_at',
		sortDir: 'DESC',
		search: '',
		status: '',
		dateFrom: '',
		dateTo: '',
		totalPages: 1,
	};

	DsCrmUI.wireModal(modal);
	DsCrmUI.wireModal(ledgerModal);

	let portalUsers = [];

	const renderPortalUserOptions = (selectedId = 0) => {
		if (!portalUserSelect) {
			return;
		}
		const selected = String(selectedId || '');
		const options = [
			`<option value="">${escapeHtml(DsCrmApp.i18n?.noPortalUser || '— No portal login —')}</option>`,
			...portalUsers.map(
				(user) =>
					`<option value="${user.id}" ${String(user.id) === selected ? 'selected' : ''}>${escapeHtml(user.label)}</option>`
			),
		];
		portalUserSelect.innerHTML = options.join('');
	};

	const loadFormData = async () => {
		const result = await postAjax('crm_clients_form_data');
		if (result.success) {
			portalUsers = result.data.portal_users || [];
		}
	};

	const statCard = (label, value, extraClass = '') =>
		`<div class="ds-crm-stat-card ${extraClass}"><span class="ds-crm-stat-label">${escapeHtml(label)}</span><span class="ds-crm-stat-value">${value}</span></div>`;

	const renderLedgerTable = (headers, rows, emptyMsg) => {
		if (!rows.length) {
			return `<p class="ds-crm-ledger-empty">${escapeHtml(emptyMsg)}</p>`;
		}
		return `
			<div class="ds-crm-table-wrap">
				<table class="ds-crm-table">
					<thead><tr>${headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
					<tbody>${rows.join('')}</tbody>
				</table>
			</div>`;
	};

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const setFormError = (message) => {
		if (!errorBox) {
			return;
		}
		if (!message) {
			errorBox.hidden = true;
			errorBox.textContent = '';
			return;
		}
		errorBox.hidden = false;
		errorBox.textContent = message;
	};

	const COLSPAN = 8;

	const amountCell = (value, { due = false } = {}) => {
		const num = parseFloat(value || 0);
		const dueClass = due && num > 0 ? ' ds-crm-amount-due' : '';
		return `<td class="ds-crm-amount-cell${dueClass}">${formatAmount(num)}</td>`;
	};

	const statusLabel = (status) => {
		const labels = { active: 'Active', inactive: 'Inactive' };
		return labels[status] || status || '—';
	};

	const renderRows = (items) => {
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No clients found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map((item) => {
				const ledger = item.ledger || {};
				const status = item.status || 'active';
				return `
			<tr>
				<td class="ds-crm-client-name-cell">
					<span class="ds-crm-cell-primary">${escapeHtml(item.name)}</span>
					<span class="ds-crm-badge ds-crm-badge-${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</span>
				</td>
				<td>${escapeHtml(item.phone || '—')}</td>
				<td>${escapeHtml(item.email || '—')}</td>
				${amountCell(ledger.total_bill)}
				${amountCell(ledger.total_paid)}
				${amountCell(ledger.total_due, { due: true })}
				<td class="ds-crm-datetime">${formatListDateTime(item)}</td>
				<td class="ds-crm-actions">
					${DsCrmUI.wrapActions(
						DsCrmUI.actionButton('Ledger', 'ledger', { className: 'ds-crm-ledger', attrs: `data-id="${item.id}"`, iconOnly: true }),
						canEdit
							? DsCrmUI.actionButton('Edit', 'edit', { className: 'ds-crm-edit', attrs: `data-id="${item.id}"`, iconOnly: true })
							: '',
						canDelete
							? DsCrmUI.actionButton('Delete', 'delete', { className: 'ds-crm-delete', attrs: `data-id="${item.id}"`, iconOnly: true })
							: ''
					)}
				</td>
			</tr>`;
			})
			.join('');
	};

	const loadList = async () => {
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${COLSPAN}">Loading…</td></tr>`;

		const result = await postAjax('crm_clients_list', {
			page: state.page,
			per_page: state.perPage,
			sort_by: state.sortBy,
			sort_dir: state.sortDir,
			search: state.search,
			status: state.status,
			date_from: state.dateFrom,
			date_to: state.dateTo,
		});

		if (!result.success) {
			tbody.innerHTML = `<tr><td colspan="${COLSPAN}">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		renderRows(result.data.items || []);
		DsCrmUI.renderModuleSummary(root, result.data.summary);
		DsCrmUI.renderFinancialOverview(root, result.data.financial, { title: 'All clients' });
		state.totalPages = result.data.total_pages || 1;

		paginationEl.hidden = state.totalPages <= 1 && !result.data.total;
		pageInfo.textContent = `Page ${result.data.page} of ${state.totalPages} (${result.data.total} total)`;
		prevBtn.disabled = state.page <= 1;
		nextBtn.disabled = state.page >= state.totalPages;
	};

	const openForm = async (id = 0, trigger = null) => {
		if (!id) {
			setFormError('');
			form.reset();
			form.querySelector('[name="id"]').value = '';
			renderPortalUserOptions(0);
			document.getElementById('ds-crm-client-modal-title').textContent = 'Add Client';
			DsCrmUI.openModal(modal);
			return;
		}

		await DsCrmUI.runModalAction({
			modal,
			trigger,
			label: 'Loading client…',
			task: async () => {
				setFormError('');
				form.reset();
				form.querySelector('[name="id"]').value = String(id);
				document.getElementById('ds-crm-client-modal-title').textContent = 'Edit Client';

				const result = await postAjax('crm_clients_get', { id });
				if (!result.success) {
					DsCrmUI.toast(result.data?.message || 'Failed to load client.', 'error');
					return false;
				}
				const item = result.data.item;
				portalUsers = result.data.portal_users || portalUsers;
				form.querySelector('[name="name"]').value = item.name || '';
				form.querySelector('[name="phone"]').value = item.phone || '';
				form.querySelector('[name="email"]').value = item.email || '';
				form.querySelector('[name="address"]').value = item.address || '';
				form.querySelector('[name="notes"]').value = item.notes || '';
				form.querySelector('[name="status"]').value = item.status || 'active';
				renderPortalUserOptions(item.wp_user_id || 0);
				return true;
			},
		});
	};

	const saveForm = async (event) => {
		event.preventDefault();
		setFormError('');

		const name = form.querySelector('[name="name"]').value.trim();
		if (!name) {
			setFormError('Client name is required.');
			form.querySelector('[name="name"]').classList.add('ds-crm-invalid');
			return;
		}

		form.querySelectorAll('.ds-crm-invalid').forEach((el) => el.classList.remove('ds-crm-invalid'));

		const payload = {
			id: form.querySelector('[name="id"]').value,
			name,
			phone: form.querySelector('[name="phone"]').value,
			email: form.querySelector('[name="email"]').value,
			address: form.querySelector('[name="address"]').value,
			notes: form.querySelector('[name="notes"]').value,
			status: form.querySelector('[name="status"]').value,
			wp_user_id: form.querySelector('[name="wp_user_id"]')?.value || '0',
		};

		const result = await postAjax('crm_clients_save', payload);
		if (!result.success) {
			setFormError(result.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Saved.');
		DsCrmUI.closeModal(modal);
		DsCrm.clearAjaxGetCache('crm_clients_');
		await loadList();
	};

	const openLedger = async (id, trigger = null) => {
		await DsCrmUI.runModalAction({
			modal: ledgerModal,
			trigger,
			label: 'Loading ledger…',
			task: async () => {
				const result = await postAjax('crm_clients_ledger', { id });
				if (!result.success) {
					DsCrmUI.toast(result.data?.message || 'Failed to load ledger.', 'error');
					return false;
				}

				const { client, summary, orders, payments, permissions } = result.data;
				const canRecordPayment = Boolean(permissions?.can_record_payment);
				const dueClass = summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
				const orderDueClass = summary.order_due > 0 ? 'ds-crm-stat-card--due' : '';
				const deliveryDueClass = summary.delivery_due > 0 ? 'ds-crm-stat-card--due' : '';
				const paymentUrl = buildModuleUrl('payments', { client_id: client.id });
				const ordersUrl = buildModuleUrl('orders', { client_id: client.id });

				document.getElementById('ds-crm-client-ledger-title').textContent = `Ledger — ${client.name}`;

				const orderRows = (orders || []).map((o) => {
					const s = o.summary || {};
					const dueClass = s.total_due > 0 ? 'ds-crm-amount-due' : '';
					return `
					<tr>
						<td>${escapeHtml(o.order_number || '—')}</td>
						<td>${formatDate(o.order_date)}</td>
						<td><span class="ds-crm-badge ds-crm-badge-${o.status || 'pending'}">${escapeHtml(o.status || '—')}</span></td>
						<td>${formatAmount(s.total_bill || 0)}</td>
						<td>${formatAmount(s.total_paid || 0)}</td>
						<td class="${dueClass}">${formatAmount(s.total_due || 0)}</td>
					</tr>`;
				});

				const paymentRows = (payments || []).map(
					(p) => `
					<tr>
						<td>${escapeHtml(p.payment_number || '—')}</td>
						<td>${formatDate(p.payment_date)}</td>
						<td>${formatAmount(p.amount)}</td>
						<td>${escapeHtml(p.order_number || '—')}</td>
						<td>${escapeHtml(p.payment_method || '—')}</td>
						<td>${escapeHtml(p.reference || '—')}</td>
					</tr>`
				);

				ledgerBody.innerHTML = `
					<div class="ds-crm-ledger-meta">
						<strong>${escapeHtml(client.name)}</strong>
						${client.phone ? ` · ${escapeHtml(client.phone)}` : ''}
						${client.email ? ` · ${escapeHtml(client.email)}` : ''}
					</div>
					<div class="ds-crm-ledger-summary">
						<h3 class="ds-crm-ledger-summary-title">Client totals</h3>
						<div class="ds-crm-order-stats ds-crm-order-stats--compact">
							${statCard('Total bill', formatAmount(summary.total_bill))}
							${statCard('Paid', formatAmount(summary.total_paid))}
							${statCard('Due', formatAmount(summary.total_due), dueClass)}
						</div>
					</div>
					<div class="ds-crm-ledger-summary">
						<h3 class="ds-crm-ledger-summary-title">Order billing</h3>
						<div class="ds-crm-order-stats ds-crm-order-stats--compact">
							${statCard('Order bill', formatAmount(summary.order_bill))}
							${statCard('Order paid', formatAmount(summary.order_paid))}
							${statCard('Order due', formatAmount(summary.order_due), orderDueClass)}
						</div>
					</div>
					<div class="ds-crm-ledger-summary">
						<h3 class="ds-crm-ledger-summary-title">Delivery billing</h3>
						<div class="ds-crm-order-stats ds-crm-order-stats--compact">
							${statCard('Delivery bill', formatAmount(summary.delivery_bill))}
							${statCard('Delivery paid', formatAmount(summary.delivery_paid))}
							${statCard('Delivery due', formatAmount(summary.delivery_due), deliveryDueClass)}
						</div>
					</div>
					<section class="ds-crm-ledger-section">
						<h3>Orders <span class="description">(allocated share of client payments, oldest first)</span></h3>
						${renderLedgerTable(
							['Order #', 'Date', 'Status', 'Bill', 'Paid', 'Due'],
							orderRows,
							'No orders for this client.'
						)}
					</section>
					<section class="ds-crm-ledger-section">
						<h3>Payments received</h3>
						${renderLedgerTable(
							['Payment #', 'Date', 'Amount', 'Ref. order', 'Method', 'Reference'],
							paymentRows,
							'No payments recorded yet.'
						)}
					</section>
					<div class="ds-crm-ledger-actions">
						${canRecordPayment ? `<a class="button button-primary" href="${paymentUrl}">Record payment</a>` : ''}
						<a class="button" href="${ordersUrl}">View all orders</a>
					</div>
				`;
				return true;
			},
		});
	};

	const deleteItem = async (id) => {
		if (!window.confirm('Delete this client?')) {
			return;
		}

		const result = await postAjax('crm_clients_delete', { id });
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Delete failed.', 'error');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Deleted.');
		await loadList();
	};

	root.querySelector('.ds-crm-btn-add-client')?.addEventListener('click', () => openForm());
	root.querySelector('.ds-crm-search')?.addEventListener(
		'input',
		debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			loadList();
		})
	);
	root.querySelector('.ds-crm-filter-status')?.addEventListener('change', (e) => {
		state.status = e.target.value;
		state.page = 1;
		loadList();
	});
	root.querySelector('.ds-crm-date-from')?.addEventListener('change', (e) => {
		state.dateFrom = e.target.value;
		state.page = 1;
		loadList();
	});
	root.querySelector('.ds-crm-date-to')?.addEventListener('change', (e) => {
		state.dateTo = e.target.value;
		state.page = 1;
		loadList();
	});
	root.querySelector('.ds-crm-per-page')?.addEventListener('change', (e) => {
		state.perPage = parseInt(e.target.value, 10);
		state.page = 1;
		loadList();
	});

	root.querySelectorAll('.ds-crm-clients-table th[data-sort]').forEach((th) => {
		th.addEventListener('click', () => {
			const col = th.dataset.sort;
			if (state.sortBy === col) {
				state.sortDir = state.sortDir === 'ASC' ? 'DESC' : 'ASC';
			} else {
				state.sortBy = col;
				state.sortDir = 'ASC';
			}
			loadList();
		});
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

	tbody.addEventListener('click', (e) => {
		const ledgerBtn = e.target.closest('.ds-crm-ledger');
		const editBtn = e.target.closest('.ds-crm-edit');
		const deleteBtn = e.target.closest('.ds-crm-delete');
		if (ledgerBtn) {
			openLedger(parseInt(ledgerBtn.dataset.id, 10), ledgerBtn);
		}
		if (editBtn) {
			openForm(parseInt(editBtn.dataset.id, 10), editBtn);
		}
		if (deleteBtn) {
			deleteItem(parseInt(deleteBtn.dataset.id, 10));
		}
	});

	form?.addEventListener('submit', saveForm);

	loadFormData().then(loadList);
})();
