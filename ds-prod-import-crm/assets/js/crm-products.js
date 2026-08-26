/* global DsCrm, DsCrmApp, DsCrmImageCrop */

(() => {
	const root = document.querySelector('[data-crm-module="products"]');
	if (!root) {
		return;
	}

	const { postAjax, postAjaxForm, debounce, formatAmount, formatDate, formatListDateTime, DsCrmUI } = DsCrm;
	const canCreate = root.dataset.canCreate === '1';
	const canEdit = root.dataset.canEdit === '1';
	const canDelete = root.dataset.canDelete === '1';
	const modal = document.getElementById('ds-crm-product-modal');
	const form = modal?.querySelector('.ds-crm-product-form');
	const tbody = root.querySelector('.ds-crm-products-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');
	const errorBox = form?.querySelector('.ds-crm-form-error');
	const imagePreview = form?.querySelector('.ds-crm-image-preview');
	const previewImg = imagePreview?.querySelector('img');
	const fileInput = form?.querySelector('[name="image"]');
	const imageDropzone = form?.querySelector('.ds-crm-image-dropzone');
	const categorySelect = form?.querySelector('[name="category_id"]');
	const isSinglePriceMode = Boolean(DsCrmApp?.isSinglePriceMode);
	const purchaseRateHeader = root.querySelector('.ds-crm-col-purchase-rate');
	const purchaseRateField = form?.querySelector('.ds-crm-purchase-rate-field');
	const unitPriceLabel = form?.querySelector('.ds-crm-label-unit-price');
	const unitPriceHeader = root.querySelector('.ds-crm-col-unit-price');

	if (purchaseRateHeader) {
		purchaseRateHeader.hidden = isSinglePriceMode;
	}
	if (purchaseRateField) {
		purchaseRateField.hidden = isSinglePriceMode;
	}
	if (unitPriceLabel) {
		unitPriceLabel.textContent = isSinglePriceMode ? 'Price (BDT)' : 'Selling price (BDT)';
	}
	if (unitPriceHeader) {
		unitPriceHeader.textContent = isSinglePriceMode ? 'Price' : 'Sell price';
	}

	const tableColspan = isSinglePriceMode ? 10 : 11;

	const state = {
		page: 1,
		perPage: 10,
		sortBy: 'created_at',
		sortDir: 'DESC',
		search: '',
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

	const updateImagePreview = (url) => {
		if (!imagePreview || !previewImg) {
			return;
		}
		if (url) {
			previewImg.src = url;
			imagePreview.hidden = false;
		} else {
			previewImg.src = '';
			imagePreview.hidden = true;
		}
	};

	const renderRows = (items) => {
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="${tableColspan}" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No products found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map((item) => {
				const imageUrl = (item.thumbnail_url || item.image_url) ? String(item.thumbnail_url || item.image_url).replace(/"/g, '') : '';
				const thumb = imageUrl
					? `<img class="ds-crm-thumb" src="${imageUrl}" alt="" />`
					: '<span class="ds-crm-thumb-placeholder">—</span>';

				return `
			<tr>
				<td>${thumb}</td>
				<td>${escapeHtml(item.sku || '—')}</td>
				<td>${escapeHtml(item.name)}</td>
				<td>${escapeHtml(item.color || '—')}</td>
				<td>${escapeHtml(item.size || '—')}</td>
				<td>${escapeHtml(item.category_name || '—')}</td>
				<td>${formatAmount(item.unit_price)}</td>
				${isSinglePriceMode ? '' : `<td>${formatAmount(item.purchase_rate || 0)}</td>`}
				<td>${escapeHtml(String(item.stock_qty ?? 0))}</td>
				<td class="ds-crm-datetime">${formatListDateTime(item)}</td>
				<td class="ds-crm-actions">
					${canEdit ? `<button type="button" class="button button-small ds-crm-edit" data-id="${item.id}">Edit</button>` : ''}
					${canDelete ? `<button type="button" class="button button-small ds-crm-delete" data-id="${item.id}">Delete</button>` : ''}
				</td>
			</tr>`;
			})
			.join('');
	};

	const loadList = async () => {
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${tableColspan}">Loading…</td></tr>`;

		const result = await postAjax('crm_products_list', {
			page: state.page,
			per_page: state.perPage,
			sort_by: state.sortBy,
			sort_dir: state.sortDir,
			search: state.search,
			date_from: state.dateFrom,
			date_to: state.dateTo,
		});

		if (!result.success) {
			tbody.innerHTML = `<tr><td colspan="${tableColspan}">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
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

	const loadCategoryOptions = async (selectedId = 0) => {
		if (!categorySelect) {
			return;
		}

		const result = await postAjax('crm_product_categories_options');
		const options = result.success ? result.data.items || [] : [];

		categorySelect.innerHTML = '<option value="">— Select category —</option>';
		options.forEach((cat) => {
			const option = document.createElement('option');
			option.value = String(cat.id);
			option.textContent = cat.name;
			categorySelect.appendChild(option);
		});

		if (selectedId) {
			categorySelect.value = String(selectedId);
		}
	};

	const openForm = async (id = 0, trigger = null) => {
		if (!id) {
			setFormError('');
			form.reset();
			form.querySelector('[name="id"]').value = '';
			form.querySelector('[name="image_url_current"]').value = '';
			form.querySelector('[name="thumbnail_url_current"]').value = '';
			form.querySelector('[name="remove_image"]').checked = false;
			updateImagePreview('');
			document.getElementById('ds-crm-product-modal-title').textContent = 'Add Product';
			DsCrmUI.openModal(modal);
			await loadCategoryOptions(0);
			return;
		}

		await DsCrmUI.runModalAction({
			modal,
			trigger,
			label: 'Loading product…',
			task: async () => {
				setFormError('');
				form.reset();
				form.querySelector('[name="id"]').value = String(id);
				form.querySelector('[name="image_url_current"]').value = '';
			form.querySelector('[name="thumbnail_url_current"]').value = '';
				form.querySelector('[name="remove_image"]').checked = false;
				updateImagePreview('');
				document.getElementById('ds-crm-product-modal-title').textContent = 'Edit Product';

				const result = await postAjax('crm_products_get', { id });
				if (!result.success) {
					DsCrmUI.toast(result.data?.message || 'Failed to load product.', 'error');
					return false;
				}
				const item = result.data.item;
				form.querySelector('[name="name"]').value = item.name || '';
				form.querySelector('[name="sku"]').value = item.sku || '';
				form.querySelector('[name="color"]').value = item.color || '';
				form.querySelector('[name="size"]').value = item.size || '';
				const selectedCategoryId = item.category_id || 0;
				form.querySelector('[name="unit_price"]').value = item.unit_price ?? 0;
				form.querySelector('[name="purchase_rate"]').value = item.purchase_rate ?? 0;
				form.querySelector('[name="description"]').value = item.description || '';
				form.querySelector('[name="image_url_current"]').value = item.image_url || '';
				form.querySelector('[name="thumbnail_url_current"]').value = item.thumbnail_url || '';
				updateImagePreview(item.thumbnail_url || item.image_url || '');
				await loadCategoryOptions(selectedCategoryId);
				return true;
			},
		});
	};

	const saveForm = async (event) => {
		event.preventDefault();
		setFormError('');

		const name = form.querySelector('[name="name"]').value.trim();
		if (!name) {
			setFormError('Product name is required.');
			form.querySelector('[name="name"]').classList.add('ds-crm-invalid');
			return;
		}

		form.querySelectorAll('.ds-crm-invalid').forEach((el) => el.classList.remove('ds-crm-invalid'));

		const formData = new FormData(form);
		if (isSinglePriceMode) {
			const unitPrice = form.querySelector('[name="unit_price"]')?.value || '0';
			formData.set('purchase_rate', unitPrice);
		}
		if (!form.querySelector('[name="remove_image"]').checked) {
			formData.delete('remove_image');
		} else {
			formData.set('remove_image', '1');
		}

		const result = await postAjaxForm('crm_products_save', formData);
		if (!result.success) {
			setFormError(result.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Saved.');
		DsCrmUI.closeModal(modal);
		DsCrm.clearAjaxGetCache('crm_products_');
		await loadList();
	};

	const deleteItem = async (id) => {
		if (!window.confirm('Delete this product?')) {
			return;
		}

		const result = await postAjax('crm_products_delete', { id });
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Delete failed.', 'error');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Deleted.');
		await loadList();
	};

	const showImagePreview = (file) => {
		const previewFile = file;
		if (previewFile && previewImg) {
			previewImg.src = URL.createObjectURL(previewFile);
			imagePreview.hidden = false;
			form.querySelector('[name="remove_image"]').checked = false;
		}
	};

	if (imageDropzone && window.DsCrmImageCrop) {
		DsCrmImageCrop.wireDropZone(imageDropzone, fileInput, (result) => {
			showImagePreview(result?.cropped || result?.original);
		});
	} else if (fileInput && window.DsCrmImageCrop) {
		DsCrmImageCrop.attach(fileInput, (result) => {
			showImagePreview(result?.cropped || result?.original);
		});
	} else {
		fileInput?.addEventListener('change', () => showImagePreview(fileInput.files?.[0]));
	}

	root.querySelector('.ds-crm-btn-add-product')?.addEventListener('click', () => openForm());
	root.querySelector('.ds-crm-search')?.addEventListener(
		'input',
		debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			loadList();
		})
	);
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

	root.querySelectorAll('.ds-crm-products-table th[data-sort]').forEach((th) => {
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
