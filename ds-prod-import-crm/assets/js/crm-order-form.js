/* global DsCrm, DsCrmApp, DsCrmImageCrop */

(() => {
	const root = document.querySelector('[data-crm-module="orders-form"]');
	if (!root) {
		return;
	}

	const { postAjax, postAjaxForm, debounce, formatAmount, DsCrmUI, wireProductPicker, buildModuleUrl } = DsCrm;
	const orderForm = root.querySelector('.ds-crm-order-form');
	const linesBody = orderForm?.querySelector('.ds-crm-order-lines tbody');
	const orderTotalEl = orderForm?.querySelector('.ds-crm-order-total-value');
	const clientSearch = orderForm?.querySelector('.ds-crm-client-search');
	const clientIdInput = orderForm?.querySelector('[name="client_id"]');
	const selectedClientEl = orderForm?.querySelector('.ds-crm-selected-client');
	const clientSuggestions = orderForm?.querySelector('.ds-crm-client-suggestions');
	const clientFieldWrap = orderForm?.querySelector('.ds-crm-order-client-field');
	const errorBox = orderForm?.querySelector('.ds-crm-form-error');
	const orderFormHint = orderForm?.querySelector('.ds-crm-form-hint');
	const orderIdInput = orderForm?.querySelector('[name="id"]');
	const formTitle = root.querySelector('#ds-crm-order-form-title');
	const listUrl = buildModuleUrl('orders');

	const isSinglePriceMode = Boolean(DsCrmApp?.isSinglePriceMode);
	const orderPricesOptional = Boolean(DsCrmApp?.orderPricesOptional);
	const isEdit = root.dataset.mode === 'edit';
	const orderId = parseInt(root.dataset.orderId, 10) || 0;
	const priceHeader = orderForm?.querySelector('.ds-crm-order-price-header');
	if (priceHeader) {
		if (orderPricesOptional) {
			priceHeader.textContent = isSinglePriceMode ? 'Price (optional)' : 'Unit price (optional)';
		} else {
			priceHeader.textContent = isSinglePriceMode ? 'Price' : 'Unit price';
		}
	}

	let categoryOptions = [];
	let portalState = { locked: false, portal: null };

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
		window.scrollTo({ top: 0, behavior: 'smooth' });
	};

	const setFormLoading = (loading) => {
		root.classList.toggle('is-loading', loading);
		orderForm?.querySelectorAll('input, select, textarea, button').forEach((el) => {
			if (el.type === 'hidden') {
				return;
			}
			el.disabled = loading;
		});
	};

	const clearClientSelection = () => {
		if (portalState.locked) {
			return;
		}
		if (clientIdInput) {
			clientIdInput.value = '';
		}
		if (selectedClientEl) {
			selectedClientEl.hidden = true;
			selectedClientEl.textContent = '';
		}
		if (clientSearch) {
			clientSearch.hidden = false;
			clientSearch.value = '';
		}
	};

	const selectClient = (client, { lock = false } = {}) => {
		if (clientIdInput) {
			clientIdInput.value = client.id;
		}
		if (clientSearch) {
			clientSearch.hidden = true;
			clientSearch.value = '';
			if (lock) {
				clientSearch.disabled = true;
			}
		}
		if (selectedClientEl) {
			selectedClientEl.hidden = false;
			const changeBtn = lock
				? ''
				: ' <button type="button" class="button-link ds-crm-clear-client">Change</button>';
			selectedClientEl.innerHTML = `${escapeHtml(client.name)}${client.phone ? ` (${escapeHtml(client.phone)})` : ''}${changeBtn}`;
			if (!lock) {
				selectedClientEl.querySelector('.ds-crm-clear-client')?.addEventListener('click', clearClientSelection);
			}
		}
		if (clientSuggestions) {
			DsCrmUI.resetAutocompleteList(clientSuggestions);
		}
		if (lock) {
			portalState.locked = true;
			clientFieldWrap?.classList.add('is-portal-locked');
		}
	};

	const applyPortalMode = (portal) => {
		if (!portal?.is_client_user) {
			return;
		}

		portalState.portal = portal;

		if (portal.client) {
			selectClient(portal.client, { lock: true });
			return;
		}

		setFormError(
			'Your account is not linked to a client record. Contact the administrator to finish portal setup.'
		);
		orderForm?.querySelector('[type="submit"]')?.setAttribute('disabled', 'disabled');
	};

	const loadFormData = async () => {
		const res = await postAjax('crm_orders_form_data');
		if (res.success) {
			categoryOptions = res.data.categories || [];
			portalState.portal = res.data.portal || null;
		}
	};

	const buildCategoryOptions = (selectedId = 0) => {
		const opts = ['<option value="">Category (optional)</option>'];
		categoryOptions.forEach((cat) => {
			const sel = String(cat.id) === String(selectedId) ? ' selected' : '';
			opts.push(`<option value="${cat.id}"${sel}>${escapeHtml(cat.name)}</option>`);
		});
		return opts.join('');
	};

	const catalogSelectedLabel = (product) => DsCrmUI.productPickerSelectedHtml(product);

	const wireNewProductImagePreview = (tr) => {
		const dropzone = tr.querySelector('.ds-crm-image-dropzone');
		const fileInput = tr.querySelector('.line-product-image');
		const preview = tr.querySelector('.line-product-image-preview');
		const showPreview = (file) => {
			if (!file || !preview) {
				if (preview) preview.hidden = true;
				return;
			}
			preview.src = URL.createObjectURL(file);
			preview.hidden = false;
		};

		if (dropzone && window.DsCrmImageCrop) {
			DsCrmImageCrop.wireDropZone(dropzone, fileInput, (result) => {
				showPreview(result?.cropped || result?.original);
			});
			return;
		}

		if (fileInput && window.DsCrmImageCrop) {
			DsCrmImageCrop.attach(fileInput, (result) => {
				showPreview(result?.cropped || result?.original);
			});
			return;
		}

		fileInput?.addEventListener('change', () => showPreview(fileInput.files?.[0]));
	};

	const updateOrderTotal = () => {
		if (!orderTotalEl || !linesBody) {
			return;
		}
		let total = 0;
		linesBody.querySelectorAll('tr').forEach((row) => {
			const qty = parseFloat(row.querySelector('.line-qty')?.value) || 0;
			const price = parseFloat(row.querySelector('.line-unit-price')?.value) || 0;
			total += qty * price;
		});
		orderTotalEl.textContent = formatAmount(total);
	};

	const createLineRow = () => {
		const tr = document.createElement('tr');
		tr.innerHTML = `
			<td class="ds-crm-order-line-product-cell">
				<div class="ds-crm-product-picker">
					<input type="hidden" class="line-product-id" value="0" />
					<div class="ds-crm-picker-search">
						<input type="text" class="line-product-name" placeholder="Search or type new product name…" autocomplete="off" />
						<div class="ds-crm-autocomplete-list line-product-suggestions" hidden></div>
					</div>
					<div class="ds-crm-selected-product" hidden></div>
					<div class="ds-crm-line-new-product" hidden>
						<span class="ds-crm-new-product-badge">New product</span>
						<label class="ds-crm-line-new-product-name">
							<span>Product name <span class="required">*</span></span>
							<input type="text" class="ds-crm-new-product-name" placeholder="Enter product name…" autocomplete="off" />
						</label>
						<div class="ds-crm-line-new-product-meta">
							<label class="ds-crm-line-new-product-sku">
								<span>SKU</span>
								<input type="text" class="ds-crm-new-product-sku" placeholder="e.g. SHIRT-001" maxlength="100" autocomplete="off" />
							</label>
							<label class="ds-crm-line-new-product-category">
								<span>Category</span>
								<select class="line-product-category">${buildCategoryOptions()}</select>
							</label>
						</div>
						<div class="ds-crm-line-new-product-image-row">
							<label class="ds-crm-line-new-product-image">
								<span>Image <span class="required">*</span></span>
							</label>
							<div class="ds-crm-image-dropzone ds-crm-image-dropzone--compact" tabindex="0" role="button" aria-label="Upload product image">
								<input type="file" class="line-product-image ds-crm-image-dropzone-input" accept="image/jpeg,image/png,image/webp" />
								<div class="ds-crm-image-dropzone-prompt">
									<strong>Drop, paste, or browse</strong>
								</div>
							</div>
							<img class="line-product-image-preview ds-crm-line-image-preview" alt="" hidden />
						</div>
					</div>
				</div>
			</td>
			<td><input type="text" class="line-color" placeholder="Color" /></td>
			<td><input type="text" class="line-size" placeholder="Size" /></td>
			<td class="ds-crm-order-priority-cell">
				<div class="ds-crm-priority-field">
					<span class="ds-crm-priority-signal-slot"></span>
					<select class="line-delivery-priority ds-crm-priority-select">${DsCrmUI.deliveryPrioritySelectOptions()}</select>
				</div>
			</td>
			<td><input type="number" class="line-qty" min="1" step="1" value="1" /></td>
			<td><input type="number" class="line-unit-price" min="0" step="0.01" value="0" /></td>
			<td class="line-total">৳0.00</td>
			<td><button type="button" class="button button-small ds-crm-remove-line">×</button></td>
		`;

		const qtyInput = tr.querySelector('.line-qty');
		const priceInput = tr.querySelector('.line-unit-price');
		const totalCell = tr.querySelector('.line-total');
		const pickerEl = tr.querySelector('.ds-crm-product-picker');
		const prioritySelect = tr.querySelector('.line-delivery-priority');

		prioritySelect?.addEventListener('change', () => {
			DsCrmUI.syncDeliveryPriorityRow(tr);
		});

		const recalcLine = () => {
			const qty = parseFloat(qtyInput.value) || 0;
			const price = parseFloat(priceInput.value) || 0;
			totalCell.textContent = formatAmount(qty * price);
			updateOrderTotal();
		};

		qtyInput.addEventListener('input', recalcLine);
		priceInput.addEventListener('input', recalcLine);

		tr.querySelector('.ds-crm-remove-line')?.addEventListener('click', () => {
			if (linesBody.querySelectorAll('tr').length > 1) {
				tr.remove();
				updateOrderTotal();
			}
		});

		wireNewProductImagePreview(tr);

		wireProductPicker(pickerEl, {
			ajaxAction: 'crm_orders_products_search',
			allowNewProduct: true,
			selectedLabel: catalogSelectedLabel,
			formatSuggestion(product) {
				return DsCrmUI.productPickerSuggestionHtml(
					product,
					`${escapeHtml(product.name)} — ${formatAmount(product.unit_price)}`
				);
			},
			onSelect(product) {
				const colorInput = tr.querySelector('.line-color');
				const sizeInput = tr.querySelector('.line-size');
				if (colorInput && product.color && !colorInput.value.trim()) {
					colorInput.value = product.color;
				}
				if (sizeInput && product.size && !sizeInput.value.trim()) {
					sizeInput.value = product.size;
				}
				if (!orderPricesOptional && parseFloat(priceInput.value) <= 0 && product.unit_price) {
					priceInput.value = parseFloat(product.unit_price).toFixed(2);
				}
				recalcLine();
			},
			onNewProduct(_term, picker) {
				picker?.querySelector('.ds-crm-new-product-name')?.focus();
			},
		});

		recalcLine();
		DsCrmUI.syncDeliveryPriorityRow(tr);
		return tr;
	};

	const setOrderFormMode = (mode) => {
		const isNotesOnly = mode === 'edit-notes-only';
		const isLocked = isNotesOnly;

		if (orderFormHint) {
			if (isNotesOnly) {
				orderFormHint.hidden = false;
				orderFormHint.textContent =
					'This order has deliveries. Only notes can be changed. Void deliveries first to edit line items.';
			} else {
				orderFormHint.hidden = true;
				orderFormHint.textContent = '';
			}
		}

		orderForm?.querySelectorAll('.line-product-name, .line-color, .line-size, .line-delivery-priority, .line-qty, .line-unit-price, .ds-crm-remove-line, .ds-crm-add-order-line').forEach((el) => {
			el.disabled = isLocked;
		});

		clientSearch && (clientSearch.disabled = isLocked || portalState.locked);
		clientIdInput?.closest('p')?.querySelector('.ds-crm-clear-client')?.toggleAttribute('disabled', isLocked || portalState.locked);
		orderForm?.querySelector('[name="order_date"]')?.toggleAttribute('disabled', isLocked);
	};

	const resetOrderForm = () => {
		orderForm?.reset();
		if (orderIdInput) orderIdInput.value = '';
		setFormError('');
		setOrderFormMode('create');
		if (formTitle) formTitle.textContent = 'New order';
		clearClientSelection();
		if (linesBody) {
			linesBody.innerHTML = '';
			linesBody.appendChild(createLineRow());
		}
		const dateInput = orderForm?.querySelector('[name="order_date"]');
		if (dateInput) {
			dateInput.value = new Date().toISOString().slice(0, 10);
		}
	};

	const populateOrderForm = (data) => {
		const { order, items, can_edit_lines } = data;
		if (orderIdInput) orderIdInput.value = String(order.id);
		if (formTitle) formTitle.textContent = `Edit order ${order.order_number}`;

		selectClient({
			id: order.client_id,
			name: order.client_name,
			phone: order.client_phone || '',
		});

		const dateInput = orderForm?.querySelector('[name="order_date"]');
		if (dateInput) dateInput.value = order.order_date || '';
		const notesInput = orderForm?.querySelector('[name="notes"]');
		if (notesInput) notesInput.value = order.notes || '';

		if (linesBody) {
			linesBody.innerHTML = '';
			(items || []).forEach((item) => {
				const tr = createLineRow();
				linesBody.appendChild(tr);
				const picker = tr.querySelector('.ds-crm-product-picker');
				if (item.product_id && picker?._selectProduct) {
					picker._selectProduct({
						id: item.product_id,
						name: item.product_name,
						unit_price: item.unit_price,
						image_url: item.product_image_url || '',
					});
				} else {
					const nameInput = tr.querySelector('.line-product-name');
					if (nameInput) nameInput.value = item.product_name || '';
				}
				const colorInput = tr.querySelector('.line-color');
				const sizeInput = tr.querySelector('.line-size');
				const qtyInput = tr.querySelector('.line-qty');
				const priceInput = tr.querySelector('.line-unit-price');
				if (colorInput) colorInput.value = item.color || '';
				if (sizeInput) sizeInput.value = item.size || '';
				if (qtyInput) qtyInput.value = item.quantity || 1;
				if (priceInput) priceInput.value = parseFloat(item.unit_price || 0).toFixed(2);
				const prioritySelect = tr.querySelector('.line-delivery-priority');
				if (prioritySelect) {
					prioritySelect.value = item.delivery_priority || 'normal';
				}
				DsCrmUI.syncDeliveryPriorityRow(tr);
				qtyInput?.dispatchEvent(new Event('input'));
			});
			if (!items?.length) {
				linesBody.appendChild(createLineRow());
			}
		}

		setOrderFormMode(can_edit_lines ? 'edit-full' : 'edit-notes-only');
	};

	const collectLines = () => {
		const lines = [];
		let errorMessage = '';

		linesBody?.querySelectorAll('tr').forEach((tr, index) => {
			const lineNumber = index + 1;
			const pickerEl = tr.querySelector('.ds-crm-product-picker');
			const nameInput = tr.querySelector('.line-product-name');
			const quantity = parseInt(tr.querySelector('.line-qty')?.value, 10) || 0;
			const productId = parseInt(tr.querySelector('.line-product-id')?.value, 10) || 0;
			const newNameInput = pickerEl?.querySelector('.ds-crm-new-product-name');
			const isNewMode = pickerEl?.dataset.newProduct === '1';
			const typedName = isNewMode
				? newNameInput?.value?.trim() || nameInput?.value?.trim() || ''
				: nameInput?.value?.trim() ||
					pickerEl?.querySelector('.ds-crm-selected-product-name')?.textContent?.trim() ||
					'';
			let isCatalog = productId > 0 && pickerEl?.dataset.newProduct !== '1';
			let resolvedProductId = productId;
			let isNewProduct = pickerEl?.dataset.newProduct === '1' || (!isCatalog && typedName !== '');

			if (!isCatalog && typedName && pickerEl?._productCache) {
				const exact = Object.values(pickerEl._productCache).find(
					(p) => String(p.name).toLowerCase() === typedName.toLowerCase()
				);
				if (exact?.id) {
					isCatalog = true;
					isNewProduct = false;
					resolvedProductId = exact.id;
				}
			}

			if (!isCatalog && !typedName) {
				return;
			}

			if (quantity < 1) {
				return;
			}

			if (isNewProduct && !isCatalog) {
				const imageInput = tr.querySelector('.line-product-image');
				const hasImage = imageInput?.files?.length > 0;
				pickerEl._setPickerMode?.('new');

				if (!typedName) {
					errorMessage = `Line ${lineNumber}: enter a product name.`;
					newNameInput?.focus();
					return;
				}

				if (!hasImage) {
					errorMessage = `Line ${lineNumber}: add a product image for new product “${typedName}”.`;
					return;
				}

				lines.push({
					is_new_product: true,
					product_id: 0,
					product_name: typedName,
					sku: tr.querySelector('.ds-crm-new-product-sku')?.value?.trim() || '',
					category_id: parseInt(tr.querySelector('.line-product-category')?.value, 10) || 0,
					color: tr.querySelector('.line-color')?.value?.trim() || '',
					size: tr.querySelector('.line-size')?.value?.trim() || '',
					quantity,
					weight_kg: 0,
					unit_price: tr.querySelector('.line-unit-price')?.value || 0,
					delivery_priority: tr.querySelector('.line-delivery-priority')?.value || 'normal',
					_imageFile: imageInput.files[0],
					_lineIndex: lines.length,
				});
				return;
			}

			if (!isCatalog) {
				errorMessage = `Line ${lineNumber}: pick a catalog product or add “${typedName}” as a new product.`;
				return;
			}

			const productName =
				typedName ||
				pickerEl?._productCache?.[String(resolvedProductId)]?.name ||
				tr.querySelector('.ds-crm-selected-product')?.textContent?.trim() ||
				'';

			lines.push({
				is_new_product: false,
				product_id: resolvedProductId,
				product_name: productName,
				category_id: 0,
				color: tr.querySelector('.line-color')?.value?.trim() || '',
				size: tr.querySelector('.line-size')?.value?.trim() || '',
				quantity,
				weight_kg: 0,
				unit_price: tr.querySelector('.line-unit-price')?.value || 0,
				delivery_priority: tr.querySelector('.line-delivery-priority')?.value || 'normal',
				_lineIndex: lines.length,
			});
		});

		if (errorMessage) {
			return { error: errorMessage };
		}

		return { lines };
	};

	const loadOrderForEdit = async () => {
		if (!isEdit || !orderId) {
			return;
		}

		setFormLoading(true);
		const result = await postAjax('crm_orders_get', { id: orderId });
		setFormLoading(false);

		if (!result.success) {
			setFormError(result.data?.message || 'Failed to load order.');
			return;
		}

		if (!result.data.can_edit) {
			setFormError(result.data.edit_blocked_reason || 'This order cannot be edited.');
			orderForm?.querySelectorAll('input, select, textarea, button[type="submit"]').forEach((el) => {
				el.disabled = true;
			});
			return;
		}

		populateOrderForm(result.data);
	};

	orderForm?.querySelector('.ds-crm-add-order-line')?.addEventListener('click', () => {
		linesBody?.appendChild(createLineRow());
	});

	clientSearch?.addEventListener(
		'input',
		debounce(async () => {
			const term = clientSearch.value.trim();
			if (term.length < 2) {
				if (term.length === 0) {
					DsCrmUI.resetAutocompleteList(clientSuggestions);
				} else {
					clientSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Type at least 2 characters to search clients…</div>';
					DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
				}
				return;
			}
			clientSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Searching…</div>';
			DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);

			const result = await postAjax('crm_orders_clients_search', { search: term });
			if (!result.success) {
				clientSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Could not load clients. Try again.</div>';
				DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
				return;
			}
			if (result.data?.hint && !result.data.items?.length) {
				clientSuggestions.innerHTML = `<div class="ds-crm-autocomplete-hint">${escapeHtml(result.data.hint)}</div>`;
				DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
				return;
			}
			if (!result.data.items?.length) {
				clientSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">No clients found. Add the client in Clients first.</div>';
				DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
				return;
			}
			clientSuggestions.innerHTML = result.data.items
				.map(
					(c) =>
						`<button type="button" class="ds-crm-suggestion" data-id="${c.id}" data-name="${escapeHtml(c.name)}" data-phone="${escapeHtml(c.phone || '')}">${escapeHtml(c.name)}${c.phone ? ` — ${escapeHtml(c.phone)}` : ''}</button>`
				)
				.join('');
			DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
		}, 300)
	);

	clientSearch?.addEventListener('focus', () => {
		const term = clientSearch.value.trim();
		if (term.length >= 2) {
			DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
		} else {
			clientSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Type at least 2 characters to search clients…</div>';
			DsCrmUI.positionAutocompleteList(clientSuggestions, clientSearch);
		}
	});

	clientSuggestions?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-suggestion');
		if (!btn) {
			return;
		}
		selectClient({
			id: btn.dataset.id,
			name: btn.dataset.name,
			phone: btn.dataset.phone,
		});
	});

	orderForm?.addEventListener('submit', async (e) => {
		e.preventDefault();
		setFormError('');

		const orderDate = orderForm.querySelector('[name="order_date"]')?.value;
		if (!orderDate) {
			setFormError('Order date is required.');
			return;
		}

		if (!clientIdInput?.value) {
			setFormError('Please pick a client from the list (type name/phone, then click a suggestion).');
			return;
		}

		const collected = collectLines();
		if (collected.error) {
			setFormError(collected.error);
			return;
		}

		const lines = collected.lines;
		if (!lines.length) {
			setFormError('Add at least one line item with a product and quantity.');
			return;
		}

		const submitBtn = orderForm.querySelector('[type="submit"]');
		submitBtn.disabled = true;

		const payloadLines = lines.map(({ _imageFile, _lineIndex, ...line }) => line);
		const hasNewProductFiles = lines.some((line) => line._imageFile);

		let result;
		if (hasNewProductFiles) {
			const formData = new FormData();
			formData.append('id', orderIdInput?.value || '');
			formData.append('client_id', clientIdInput.value);
			formData.append('order_date', orderDate);
			formData.append('notes', orderForm.querySelector('[name="notes"]')?.value || '');
			formData.append('items', JSON.stringify(payloadLines));
			lines.forEach((line) => {
				if (line._imageFile) {
					formData.append(`new_product_image_${line._lineIndex}`, line._imageFile);
				}
			});
			result = await postAjaxForm('crm_orders_save', formData);
		} else {
			result = await postAjax('crm_orders_save', {
				id: orderIdInput?.value || '',
				client_id: clientIdInput.value,
				order_date: orderDate,
				notes: orderForm.querySelector('[name="notes"]')?.value || '',
				items: JSON.stringify(payloadLines),
			});
		}

		submitBtn.disabled = false;

		if (!result.success) {
			setFormError(result.data?.message || 'Failed to save order.');
			return;
		}

		DsCrm.clearAjaxGetCache('crm_orders_');
		sessionStorage.setItem('ds_crm_toast', result.data?.message || 'Order saved.');
		window.location.assign(listUrl);
	});

	const init = async () => {
		await loadFormData();
		if (isEdit) {
			await loadOrderForEdit();
		} else {
			resetOrderForm();
		}
		applyPortalMode(portalState.portal);
	};

	init();
})();
