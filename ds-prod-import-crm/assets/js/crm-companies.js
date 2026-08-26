/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="companies"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatListDateTime, formatAmount, DsCrmUI, buildModuleUrl } = DsCrm;
	const canCreate = root.dataset.canCreate === '1';
	const canEdit = root.dataset.canEdit === '1';
	const canDelete = root.dataset.canDelete === '1';
	const modal = document.getElementById('ds-crm-company-modal');
	const form = modal?.querySelector('.ds-crm-company-form');
	const tbody = root.querySelector('.ds-crm-companies-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');
	const errorBox = form?.querySelector('.ds-crm-form-error');

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

	const COLSPAN = 9;

	const amountCell = (value, { due = false } = {}) => {
		const num = parseFloat(value || 0);
		const dueClass = due && num > 0 ? ' ds-crm-amount-due' : '';
		return `<td class="ds-crm-amount-cell${dueClass}">${formatAmount(num)}</td>`;
	};

	const renderRows = (items) => {
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No companies found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map((item) => {
				const ledger = item.ledger || {};
				return `
			<tr>
				<td>${escapeHtml(item.name)}</td>
				<td>${escapeHtml(item.contact_person || '—')}</td>
				<td>${escapeHtml(item.phone || '—')}</td>
				${amountCell(ledger.total_bill)}
				${amountCell(ledger.total_paid)}
				${amountCell(ledger.total_due, { due: true })}
				<td><span class="ds-crm-badge ds-crm-badge-${item.status}">${escapeHtml(item.status)}</span></td>
				<td class="ds-crm-datetime">${formatListDateTime(item)}</td>
				<td class="ds-crm-actions">
					<a class="button button-small" href="${buildModuleUrl('companies', { company_action: 'ledger', company_id: item.id })}">Ledger</a>
					${canEdit ? `<button type="button" class="button button-small ds-crm-edit" data-id="${item.id}">Edit</button>` : ''}
					${canDelete ? `<button type="button" class="button button-small ds-crm-delete" data-id="${item.id}">Delete</button>` : ''}
				</td>
			</tr>`;
			})
			.join('');
	};

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const loadList = async () => {
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${COLSPAN}">Loading…</td></tr>`;

		const result = await postAjax('crm_companies_list', {
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
		DsCrmUI.renderFinancialOverview(root, result.data.financial, { title: 'All shipping companies' });
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
			document.getElementById('ds-crm-company-modal-title').textContent = 'Add Company';
			DsCrmUI.openModal(modal);
			return;
		}

		await DsCrmUI.runModalAction({
			modal,
			trigger,
			label: 'Loading company…',
			task: async () => {
				setFormError('');
				form.reset();
				form.querySelector('[name="id"]').value = String(id);
				document.getElementById('ds-crm-company-modal-title').textContent = 'Edit Company';

				const result = await postAjax('crm_companies_get', { id });
				if (!result.success) {
					DsCrmUI.toast(result.data?.message || 'Failed to load company.', 'error');
					return false;
				}
				const item = result.data.item;
				form.querySelector('[name="name"]').value = item.name || '';
				form.querySelector('[name="contact_person"]').value = item.contact_person || '';
				form.querySelector('[name="phone"]').value = item.phone || '';
				form.querySelector('[name="address"]').value = item.address || '';
				form.querySelector('[name="notes"]').value = item.notes || '';
				form.querySelector('[name="status"]').value = item.status || 'active';
				return true;
			},
		});
	};

	const saveForm = async (event) => {
		event.preventDefault();
		setFormError('');

		const name = form.querySelector('[name="name"]').value.trim();
		if (!name) {
			setFormError('Company name is required.');
			form.querySelector('[name="name"]').classList.add('ds-crm-invalid');
			return;
		}

		form.querySelectorAll('.ds-crm-invalid').forEach((el) => el.classList.remove('ds-crm-invalid'));

		const payload = {
			id: form.querySelector('[name="id"]').value,
			name,
			contact_person: form.querySelector('[name="contact_person"]').value,
			phone: form.querySelector('[name="phone"]').value,
			address: form.querySelector('[name="address"]').value,
			notes: form.querySelector('[name="notes"]').value,
			status: form.querySelector('[name="status"]').value,
		};

		const result = await postAjax('crm_companies_save', payload);
		if (!result.success) {
			setFormError(result.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Saved.');
		DsCrmUI.closeModal(modal);
		DsCrm.clearAjaxGetCache('crm_companies_');
		await loadList();
	};

	const deleteItem = async (id) => {
		if (!window.confirm('Delete this company?')) {
			return;
		}

		const result = await postAjax('crm_companies_delete', { id });
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Delete failed.', 'error');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Deleted.');
		await loadList();
	};

	root.querySelector('.ds-crm-btn-add-company')?.addEventListener('click', () => openForm());
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

	root.querySelectorAll('.ds-crm-companies-table th[data-sort]').forEach((th) => {
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

	loadList();
})();
