/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="product-categories"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, DsCrmUI } = DsCrm;
	const canCreate = root.dataset.canCreate === '1';
	const canEdit = root.dataset.canEdit === '1';
	const canDelete = root.dataset.canDelete === '1';
	const modal = document.getElementById('ds-crm-product-category-modal');
	const form = modal?.querySelector('.ds-crm-product-category-form');
	const tbody = root.querySelector('.ds-crm-product-categories-table tbody');
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

	const renderRows = (items) => {
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="5" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No categories found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map(
				(item) => `
			<tr>
				<td>${escapeHtml(item.name)}</td>
				<td>${escapeHtml(item.description || '—')}</td>
				<td><span class="ds-crm-badge ds-crm-badge-${item.status}">${escapeHtml(item.status)}</span></td>
				<td class="ds-crm-datetime">${formatListDateTime(item)}</td>
				<td class="ds-crm-actions">
					${canEdit ? `<button type="button" class="button button-small ds-crm-edit" data-id="${item.id}">Edit</button>` : ''}
					${canDelete ? `<button type="button" class="button button-small ds-crm-delete" data-id="${item.id}">Delete</button>` : ''}
				</td>
			</tr>`
			)
			.join('');
	};

	const loadList = async () => {
		tbody.innerHTML = '<tr class="ds-crm-loading-row"><td colspan="5">Loading…</td></tr>';

		const result = await postAjax('crm_product_categories_list', {
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
			tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		renderRows(result.data.items || []);
		DsCrmUI.renderModuleSummary(root, result.data.summary);
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
			document.getElementById('ds-crm-product-category-modal-title').textContent = 'Add Category';
			DsCrmUI.openModal(modal);
			return;
		}

		await DsCrmUI.runModalAction({
			modal,
			trigger,
			label: 'Loading category…',
			task: async () => {
				setFormError('');
				form.reset();
				form.querySelector('[name="id"]').value = String(id);
				document.getElementById('ds-crm-product-category-modal-title').textContent = 'Edit Category';

				const result = await postAjax('crm_product_categories_get', { id });
				if (!result.success) {
					DsCrmUI.toast(result.data?.message || 'Failed to load category.', 'error');
					return false;
				}
				const item = result.data.item;
				form.querySelector('[name="name"]').value = item.name || '';
				form.querySelector('[name="description"]').value = item.description || '';
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
			setFormError('Category name is required.');
			form.querySelector('[name="name"]').classList.add('ds-crm-invalid');
			return;
		}

		form.querySelectorAll('.ds-crm-invalid').forEach((el) => el.classList.remove('ds-crm-invalid'));

		const result = await postAjax('crm_product_categories_save', {
			id: form.querySelector('[name="id"]').value,
			name,
			description: form.querySelector('[name="description"]').value,
			status: form.querySelector('[name="status"]').value,
		});

		if (!result.success) {
			setFormError(result.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Saved.');
		DsCrmUI.closeModal(modal);
		DsCrm.clearAjaxGetCache('crm_product_categories_');
		await loadList();
	};

	const deleteItem = async (id) => {
		if (!window.confirm('Delete this category? Products in it will move to Uncategorized.')) {
			return;
		}

		const result = await postAjax('crm_product_categories_delete', { id });
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Delete failed.', 'error');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Deleted.');
		await loadList();
	};

	root.querySelector('.ds-crm-btn-add-category')?.addEventListener('click', () => openForm());
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

	root.querySelectorAll('.ds-crm-product-categories-table th[data-sort]').forEach((th) => {
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
