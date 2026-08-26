/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="warehouse-form"]');
	if (!root) {
		return;
	}

	const { postAjax, formatAmount, formatWeight, parseWeight, sumWeights, DsCrmUI, wireProductPicker, buildModuleUrl } = DsCrm;
	const receiveForm = root.querySelector('.ds-crm-receive-form');
	const linesBody = receiveForm?.querySelector('.ds-crm-receive-lines tbody');
	const companySelect = receiveForm?.querySelector('select[name="company_id"]');
	const companyHidden = receiveForm?.querySelector('.ds-crm-company-id-hidden');
	const defaultShippingRateInput = receiveForm?.querySelector('.ds-crm-default-shipping-rate');
	const errorBox = receiveForm?.querySelector('.ds-crm-form-error');
	const listUrl = buildModuleUrl('warehouse');
	const shipmentId = parseInt(root.dataset.shipmentId || '0', 10) || 0;
	const isShipmentMode = shipmentId > 0;

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
		receiveForm?.querySelectorAll('input, select, textarea, button').forEach((el) => {
			if (el.type === 'hidden') {
				return;
			}
			el.disabled = loading;
		});
	};

	const fillCompanies = (companies) => {
		if (!companySelect) {
			return;
		}
		const options = (companies || [])
			.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
			.join('');
		companySelect.innerHTML = `<option value="">— Select company —</option>${options}`;
	};

	const loadFormData = async () => {
		const result = await postAjax('crm_warehouse_form_data');
		if (result.success) {
			fillCompanies(result.data.companies || []);
		}
	};

	const calcLineShipping = (row) => {
		const qty = parseInt(row.querySelector('.line-qty')?.value, 10) || 0;
		if (isShipmentMode && qty < 1) {
			return 0;
		}
		const weight = parseWeight(row.querySelector('.line-weight')?.value);
		const rate = parseFloat(row.querySelector('.line-shipping-rate')?.value) || 0;
		return Math.round(weight * rate * 100) / 100;
	};

	const getLineTotals = () => {
		let shippingBill = 0;
		const weights = [];

		linesBody?.querySelectorAll('tr').forEach((row) => {
			const weight = parseWeight(row.querySelector('.line-weight')?.value);
			const qty = parseInt(row.querySelector('.line-qty')?.value, 10) || 0;
			if (!isShipmentMode || qty > 0) {
				weights.push(weight);
			} else {
				weights.push(0);
			}
			shippingBill += calcLineShipping(row);
		});

		return { totalKg: sumWeights(weights), shippingBill };
	};

	const updateTotals = () => {
		const { totalKg, shippingBill } = getLineTotals();

		root.querySelector('.ds-crm-receive-total-kg').textContent = formatWeight(totalKg, { withUnit: true });
		root.querySelector('.ds-crm-receive-total-shipping').textContent = formatAmount(shippingBill);

		linesBody?.querySelectorAll('tr').forEach((row) => {
			const lineShippingEl = row.querySelector('.line-shipping-bill');
			if (lineShippingEl) {
				lineShippingEl.textContent = formatAmount(calcLineShipping(row));
			}
		});
	};

	const syncShipmentLineInputs = (row) => {
		const remaining = parseInt(row.dataset.qtyRemaining || '0', 10) || 0;
		const qtyInput = row.querySelector('.line-qty');
		const missingInput = row.querySelector('.line-missing');
		const weightInput = row.querySelector('.line-weight');
		const rateInput = row.querySelector('.line-shipping-rate');
		let qty = parseInt(qtyInput?.value, 10) || 0;
		let missing = parseInt(missingInput?.value, 10) || 0;

		if (qty < 0) {
			qty = 0;
		}
		if (missing < 0) {
			missing = 0;
		}
		if (qty + missing > remaining) {
			if (document.activeElement === missingInput) {
				missing = Math.max(0, remaining - qty);
				missingInput.value = String(missing);
			} else {
				qty = Math.max(0, remaining - missing);
				qtyInput.value = String(qty);
			}
		}

		const needsWeight = qty > 0;
		if (weightInput) {
			weightInput.disabled = !needsWeight;
			weightInput.required = needsWeight;
			if (!needsWeight) {
				weightInput.value = '0';
			} else if ((parseFloat(weightInput.value) || 0) <= 0) {
				const suggested = parseFloat(row.dataset.weightSuggested || '0') || 0;
				if (suggested > 0) {
					const ratio = remaining > 0 ? qty / remaining : 0;
					weightInput.value = (suggested * ratio).toFixed(3);
				}
			}
		}
		if (rateInput) {
			rateInput.disabled = !needsWeight;
			rateInput.required = needsWeight;
			if (!needsWeight) {
				rateInput.value = '0';
			} else if ((parseFloat(rateInput.value) || 0) <= 0) {
				const defaultRate = parseFloat(defaultShippingRateInput?.value) || 0;
				if (defaultRate > 0) {
					rateInput.value = defaultRate.toFixed(2);
				}
			}
		}

		updateTotals();
	};

	const createShipmentLineRow = (item) => {
		const remaining = parseInt(item.qty_remaining || 0, 10) || 0;
		const tr = document.createElement('tr');
		tr.dataset.exportShipmentItemId = String(item.export_shipment_item_id || 0);
		tr.dataset.qtyRemaining = String(remaining);
		tr.dataset.weightSuggested = String(item.weight_kg_suggested || 0);
		tr.dataset.productId = String(item.product_id || 0);
		tr.dataset.productName = item.product_name || '';
		tr.dataset.color = item.color || '';
		tr.dataset.size = item.size || '';

		const variant = [item.color, item.size].filter(Boolean).join(' / ');
		const productHtml = DsCrmUI.productCell
			? DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm' })
			: escapeHtml(item.product_name || '');

		tr.innerHTML = `
			<td>
				${productHtml}
				${variant ? `<div class="description">${escapeHtml(variant)}</div>` : ''}
			</td>
			<td>${escapeHtml(String(item.qty_shipped || 0))}</td>
			<td>${escapeHtml(String(item.qty_received || 0))}</td>
			<td>${escapeHtml(String(item.qty_missing || 0))}</td>
			<td><strong>${escapeHtml(String(remaining))}</strong></td>
			<td><input type="number" class="line-qty ds-crm-qty-input" min="0" max="${remaining}" step="1" value="${remaining > 0 ? remaining : 0}" ${remaining < 1 ? 'disabled' : ''} /></td>
			<td><input type="number" class="line-missing ds-crm-qty-input" min="0" max="${remaining}" step="1" value="0" ${remaining < 1 ? 'disabled' : ''} /></td>
			<td><input type="number" class="line-weight ds-crm-money-input" min="0" step="0.01" value="${remaining > 0 ? Number(item.weight_kg_suggested || 0).toFixed(3) : '0'}" /></td>
			<td><input type="number" class="line-shipping-rate ds-crm-money-input" min="0" step="0.01" value="0" /></td>
			<td class="ds-crm-line-shipping-bill"><span class="line-shipping-bill">৳0.00</span></td>
		`;

		if (remaining < 1) {
			tr.classList.add('is-complete');
		}

		tr.querySelector('.line-qty')?.addEventListener('input', () => syncShipmentLineInputs(tr));
		tr.querySelector('.line-missing')?.addEventListener('input', () => syncShipmentLineInputs(tr));
		tr.querySelector('.line-weight')?.addEventListener('input', updateTotals);
		tr.querySelector('.line-shipping-rate')?.addEventListener('input', updateTotals);

		syncShipmentLineInputs(tr);
		return tr;
	};

	const createManualLineRow = () => {
		const defaultRate = parseFloat(defaultShippingRateInput?.value) || 0;
		const tr = document.createElement('tr');
		tr.innerHTML = `
			<td>
				<div class="ds-crm-product-picker">
					<input type="hidden" class="line-product-id" value="0" />
					<input type="text" class="line-product-name ds-crm-money-input" placeholder="Search name or SKU…" autocomplete="off" />
					<span class="ds-crm-selected-product" hidden></span>
					<div class="ds-crm-autocomplete-list line-product-suggestions" hidden></div>
				</div>
			</td>
			<td><input type="text" class="line-color" placeholder="Color" /></td>
			<td><input type="text" class="line-size" placeholder="Size" /></td>
			<td><input type="number" class="line-qty ds-crm-qty-input" min="1" step="1" value="1" /></td>
			<td><input type="number" class="line-weight ds-crm-money-input" min="0" step="0.01" value="0" required /></td>
			<td><input type="number" class="line-shipping-rate ds-crm-money-input" min="0" step="0.01" value="${defaultRate > 0 ? defaultRate.toFixed(2) : '0'}" required /></td>
			<td class="ds-crm-line-shipping-bill"><span class="line-shipping-bill">৳0.00</span></td>
			<td><button type="button" class="button button-small ds-crm-remove-line" aria-label="Remove line">×</button></td>
		`;

		const weightInput = tr.querySelector('.line-weight');
		const shippingRateInput = tr.querySelector('.line-shipping-rate');
		const pickerEl = tr.querySelector('.ds-crm-product-picker');

		weightInput.addEventListener('input', updateTotals);
		shippingRateInput.addEventListener('input', updateTotals);

		tr.querySelector('.ds-crm-remove-line')?.addEventListener('click', () => {
			if (linesBody.querySelectorAll('tr').length > 1) {
				tr.remove();
				updateTotals();
			}
		});

		wireProductPicker(pickerEl, {
			ajaxAction: 'crm_warehouse_products_search',
			minSearchLength: 1,
			selectedLabel: (product) => DsCrmUI.productPickerSelectedHtml(product),
			formatSuggestion(product) {
				const sku = product.sku ? ` · ${escapeHtml(product.sku)}` : '';
				const text = `${escapeHtml(product.name)}${sku}`;
				return DsCrmUI.productPickerSuggestionHtml(product, text);
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
			},
		});

		return tr;
	};

	const addLineRow = () => {
		linesBody?.appendChild(createManualLineRow());
		updateTotals();
	};

	const collectShipmentLineItems = () => {
		const items = [];
		let invalidWeightLine = '';
		let invalidRateLine = '';
		let invalidComboLine = '';

		linesBody?.querySelectorAll('tr').forEach((row) => {
			const remaining = parseInt(row.dataset.qtyRemaining || '0', 10) || 0;
			if (remaining < 1) {
				return;
			}

			const quantity = parseInt(row.querySelector('.line-qty')?.value, 10) || 0;
			const missing = parseInt(row.querySelector('.line-missing')?.value, 10) || 0;
			if (quantity < 1 && missing < 1) {
				return;
			}

			if (quantity + missing > remaining) {
				invalidComboLine = row.dataset.productName || 'Product';
				return;
			}

			const weightKg = parseWeight(row.querySelector('.line-weight')?.value);
			const shippingRate = parseFloat(row.querySelector('.line-shipping-rate')?.value) || 0;

			if (quantity > 0 && weightKg <= 0) {
				invalidWeightLine = row.dataset.productName || 'Product';
				return;
			}
			if (quantity > 0 && shippingRate <= 0) {
				invalidRateLine = row.dataset.productName || 'Product';
				return;
			}

			items.push({
				export_shipment_item_id: parseInt(row.dataset.exportShipmentItemId || '0', 10) || 0,
				product_id: parseInt(row.dataset.productId || '0', 10) || 0,
				product_name: row.dataset.productName || '',
				color: row.dataset.color || '',
				size: row.dataset.size || '',
				quantity,
				missing_quantity: missing,
				weight_kg: quantity > 0 ? weightKg : 0,
				shipping_rate_per_kg: quantity > 0 ? shippingRate : 0,
			});
		});

		if (invalidComboLine) {
			return { error: `${invalidComboLine}: receive + missing cannot exceed remaining qty.` };
		}
		if (invalidWeightLine) {
			return { error: `${invalidWeightLine}: enter weight (kg) for received qty.` };
		}
		if (invalidRateLine) {
			return { error: `${invalidRateLine}: enter shipping rate (BDT / kg) for received qty.` };
		}

		return { items };
	};

	const collectManualLineItems = () => {
		const items = [];
		let invalidProductLine = 0;
		let invalidWeightLine = 0;
		let invalidRateLine = 0;

		linesBody?.querySelectorAll('tr').forEach((row, index) => {
			const lineNumber = index + 1;
			const pickerEl = row.querySelector('.ds-crm-product-picker');
			const nameInput = row.querySelector('.line-product-name');
			const quantity = parseInt(row.querySelector('.line-qty')?.value, 10);
			const weightKg = parseWeight(row.querySelector('.line-weight')?.value);
			const shippingRate = parseFloat(row.querySelector('.line-shipping-rate')?.value) || 0;
			const hasTypedName = nameInput && !nameInput.hidden && nameInput.value.trim();
			const isSelected = wireProductPicker.isValid(pickerEl);

			if (!isSelected && !hasTypedName) {
				return;
			}

			if (!isSelected) {
				invalidProductLine = lineNumber;
				return;
			}

			if (!quantity) {
				return;
			}

			if (weightKg <= 0) {
				invalidWeightLine = lineNumber;
				return;
			}

			if (shippingRate <= 0) {
				invalidRateLine = lineNumber;
				return;
			}

			items.push({
				product_id: parseInt(row.querySelector('.line-product-id')?.value, 10) || 0,
				product_name: nameInput?.value?.trim() || '',
				color: row.querySelector('.line-color')?.value.trim() || '',
				size: row.querySelector('.line-size')?.value.trim() || '',
				quantity,
				missing_quantity: 0,
				weight_kg: weightKg,
				shipping_rate_per_kg: shippingRate,
			});
		});

		if (invalidProductLine) {
			return { error: `Line ${invalidProductLine}: pick a product from the catalog list.` };
		}
		if (invalidWeightLine) {
			return { error: `Line ${invalidWeightLine}: enter weight in kg.` };
		}
		if (invalidRateLine) {
			return { error: `Line ${invalidRateLine}: enter shipping rate (BDT / kg).` };
		}

		return { items };
	};

	const loadShipment = async () => {
		setFormLoading(true);
		const result = await postAjax('crm_warehouse_shipment_for_receive', { shipment_id: shipmentId });
		setFormLoading(false);

		if (!result.success) {
			setFormError(result.data?.message || 'Failed to load shipment.');
			return;
		}

		const shipment = result.data.shipment || {};
		const progress = result.data.progress || {};

		root.querySelector('.ds-crm-shipment-number').value = shipment.shipment_number || '';
		root.querySelector('.ds-crm-shipment-client').value = shipment.client_name || '';
		root.querySelector('.ds-crm-shipment-order').value = shipment.order_number || '';
		root.querySelector('.ds-crm-shipment-company').value = shipment.company_name || '';
		if (companyHidden) {
			companyHidden.value = String(shipment.company_id || '');
		}

		const progressEl = root.querySelector('.ds-crm-shipment-progress');
		if (progressEl) {
			progressEl.hidden = false;
			progressEl.textContent = `Progress: ${progress.qty_received || 0} received, ${progress.qty_missing || 0} missing, ${progress.qty_remaining || 0} remaining of ${progress.qty_shipped || 0} shipped.`;
		}

		linesBody.innerHTML = '';
		(result.data.items || []).forEach((item) => {
			linesBody.appendChild(createShipmentLineRow(item));
		});
		updateTotals();
	};

	const saveReceive = async (event) => {
		event.preventDefault();
		setFormError('');

		const companyId = receiveForm.querySelector('[name="company_id"]')?.value;
		const receiveDate = receiveForm.querySelector('[name="receive_date"]')?.value;
		if (!companyId) {
			setFormError('Please select a cargo / supplier company.');
			return;
		}
		if (!receiveDate) {
			setFormError('Receive date is required.');
			return;
		}

		const collected = isShipmentMode ? collectShipmentLineItems() : collectManualLineItems();
		if (collected.error) {
			setFormError(collected.error);
			return;
		}

		const items = collected.items;
		if (!items.length) {
			setFormError(
				isShipmentMode
					? 'Enter received and/or missing quantity for at least one product.'
					: 'Add at least one line item with a catalog product, quantity, weight, and shipping rate.'
			);
			return;
		}

		const { shippingBill } = getLineTotals();
		const stockQty = items.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0);
		if (stockQty > 0 && shippingBill <= 0) {
			setFormError('Shipping bill must be greater than zero when stock is received. Check weight and shipping rates.');
			return;
		}

		setFormLoading(true);

		const result = await postAjax('crm_warehouse_save', {
			shipment_id: shipmentId,
			company_id: companyId,
			receive_date: receiveDate,
			shipping_bill: shippingBill.toFixed(2),
			notes: receiveForm.querySelector('[name="notes"]')?.value || '',
			items: JSON.stringify(items),
		});

		setFormLoading(false);

		if (!result.success) {
			setFormError(result.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(result.data.message || 'Receive saved.');
		window.location.href = listUrl;
	};

	defaultShippingRateInput?.addEventListener('input', () => {
		const defaultRate = parseFloat(defaultShippingRateInput.value) || 0;
		linesBody?.querySelectorAll('tr').forEach((row) => {
			const qty = parseInt(row.querySelector('.line-qty')?.value, 10) || 0;
			const input = row.querySelector('.line-shipping-rate');
			if (!input || input.disabled) {
				return;
			}
			if ((!isShipmentMode || qty > 0) && (parseFloat(input.value) || 0) <= 0 && defaultRate > 0) {
				input.value = defaultRate.toFixed(2);
			}
		});
		updateTotals();
	});

	root.querySelector('.ds-crm-add-receive-line')?.addEventListener('click', addLineRow);
	receiveForm?.addEventListener('submit', saveReceive);

	const dateInput = receiveForm?.querySelector('[name="receive_date"]');
	if (dateInput && !dateInput.value) {
		dateInput.value = new Date().toISOString().slice(0, 10);
	}

	if (isShipmentMode) {
		loadShipment();
	} else {
		loadFormData().then(() => {
			addLineRow();
		});
	}
})();
