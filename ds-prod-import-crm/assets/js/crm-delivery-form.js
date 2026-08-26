/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="delivery-form"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatAmount, formatWeight, parseWeight, sumWeights, DsCrmUI, buildModuleUrl } = DsCrm;
	const form = root.querySelector('.ds-crm-delivery-form');
	const orderIdInput = form?.querySelector('[name="order_id"]');
	const orderSearch = form?.querySelector('.ds-crm-delivery-order-search');
	const orderSuggestions = form?.querySelector('.ds-crm-delivery-order-suggestions');
	const selectedOrderEl = form?.querySelector('.ds-crm-selected-delivery-order');
	const linesBody = form?.querySelector('.ds-crm-delivery-lines tbody');
	const orderPreviewWrap = form?.querySelector('.ds-crm-delivery-order-preview');
	const orderPreviewInner = form?.querySelector('.ds-crm-delivery-order-preview-inner');
	const errorBox = form?.querySelector('.ds-crm-form-error');
	const listUrl = buildModuleUrl('delivery');
	const presetOrderId = root.dataset.presetOrderId || '';

	let currentLines = [];
	let selectedOrderMeta = null;

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
		form?.querySelectorAll('input, select, textarea, button').forEach((el) => {
			if (el.type === 'hidden') {
				return;
			}
			el.disabled = loading;
		});
	};

	const renderOrderPreview = (order) => {
		if (!orderPreviewWrap || !orderPreviewInner) {
			return;
		}
		if (!order) {
			orderPreviewWrap.hidden = true;
			orderPreviewInner.innerHTML = '';
			return;
		}
		orderPreviewInner.innerHTML = `
			<div class="ds-crm-delivery-order-preview-head">
				<strong>${escapeHtml(order.order_number)}</strong>
				<span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(order.status)}</span>
			</div>
			<p><strong>${escapeHtml(order.client_name || '—')}</strong>${order.client_phone ? ` · ${escapeHtml(order.client_phone)}` : ''}</p>
			<p class="description">${formatDate(order.order_date)}</p>
		`;
		orderPreviewWrap.hidden = false;
	};

	const fillReceiverFromOrder = (order) => {
		if (!order) {
			return;
		}
		const nameInput = form.querySelector('[name="receiver_name"]');
		const phoneInput = form.querySelector('[name="receiver_phone"]');
		const addrInput = form.querySelector('[name="receiver_address"]');
		if (nameInput && !nameInput.value) {
			nameInput.value = order.client_name || '';
		}
		if (phoneInput && !phoneInput.value) {
			phoneInput.value = order.client_phone || '';
		}
		if (addrInput && !addrInput.value) {
			addrInput.value = order.client_address || '';
		}
	};

	const calcLineShipping = (row) => {
		const weight = parseWeight(row.querySelector('.line-weight')?.value);
		const rate = parseFloat(row.querySelector('.line-shipping-rate')?.value) || 0;
		return Math.round(weight * rate * 100) / 100;
	};

	const syncLineWeightFromQty = (row) => {
		const qty = parseInt(row.querySelector('.line-deliver-qty')?.value, 10) || 0;
		const perUnit = parseFloat(row.dataset.weightPerUnit) || 0;
		const weightInput = row.querySelector('.line-weight');
		if (!weightInput || perUnit <= 0) {
			return;
		}
		if (qty > 0) {
			weightInput.value = (Math.round(perUnit * qty * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
		} else {
			weightInput.value = '0';
		}
	};

	const getLineTotals = () => {
		let shippingBill = 0;
		const weights = [];

		linesBody?.querySelectorAll('tr[data-order-item-id]').forEach((row) => {
			const qty = parseInt(row.querySelector('.line-deliver-qty')?.value, 10) || 0;
			if (qty < 1) {
				return;
			}
			const weight = parseWeight(row.querySelector('.line-weight')?.value);
			weights.push(weight);
			shippingBill += calcLineShipping(row);
		});

		return { totalKg: sumWeights(weights), shippingBill };
	};

	const updateTotals = () => {
		const { totalKg, shippingBill } = getLineTotals();

		root.querySelector('.ds-crm-delivery-total-kg').textContent = formatWeight(totalKg, { withUnit: true });
		root.querySelector('.ds-crm-delivery-total-shipping').textContent = formatAmount(shippingBill);

		linesBody?.querySelectorAll('tr[data-order-item-id]').forEach((row) => {
			const lineShippingEl = row.querySelector('.line-shipping-bill');
			if (lineShippingEl) {
				lineShippingEl.textContent = formatAmount(calcLineShipping(row));
			}
		});
	};

	const wireLineRow = (row) => {
		const qtyInput = row.querySelector('.line-deliver-qty');
		const weightInput = row.querySelector('.line-weight');
		const rateInput = row.querySelector('.line-shipping-rate');

		qtyInput?.addEventListener('input', () => {
			syncLineWeightFromQty(row);
			updateTotals();
		});
		weightInput?.addEventListener('input', updateTotals);
		rateInput?.addEventListener('input', updateTotals);
	};

	const renderDeliveryLines = (lines) => {
		currentLines = lines || [];
		if (!linesBody) {
			return;
		}

		if (!currentLines.length) {
			linesBody.innerHTML = '<tr><td colspan="11" class="ds-crm-empty">No line items on this order.</td></tr>';
			updateTotals();
			return;
		}

		const hasDue = currentLines.some((l) => l.qty_due > 0);
		if (!hasDue) {
			linesBody.innerHTML = '<tr><td colspan="11" class="ds-crm-empty">This order is fully delivered.</td></tr>';
			updateTotals();
			return;
		}

		const sortedLines = DsCrmUI.sortByDeliveryPriority(currentLines);

		linesBody.innerHTML = sortedLines
			.map((line) => {
				const variant = escapeHtml([line.color, line.size].filter(Boolean).join(' / ') || '—');
				const stockTotal = line.stock_product_total ?? line.stock_qty ?? 0;
				const stockVariant = line.stock_qty ?? 0;
				const stockLabel =
					stockVariant > 0
						? String(stockVariant)
						: stockTotal > 0
							? `${stockTotal} total`
							: '0';
				const stockWarn = stockTotal < line.qty_due ? ' ds-crm-stock-low' : '';
				const stockTitle =
					stockVariant > 0 && stockTotal > stockVariant
						? `${stockVariant} this variant, ${stockTotal} total in warehouse`
						: stockTotal > 0 && stockVariant < 1
							? `${stockTotal} in stock (other color/size) — delivery will use available stock`
							: '';
				const disabled = line.qty_due < 1 ? ' disabled' : '';
				const max = line.qty_due;
				const rate = parseFloat(line.shipping_rate_per_kg) || 0;
				const perUnit = parseFloat(line.weight_per_unit) || 0;

				return `
			<tr data-order-item-id="${line.id}" data-weight-per-unit="${perUnit}" class="${line.qty_due < 1 ? 'ds-crm-line-complete' : ''} ds-crm-order-line--${escapeHtml(line.delivery_priority || 'normal')}">
				<td>${DsCrmUI.productCell(line.product_name, line.product_image_url, { size: 'sm' })}</td>
				<td>${DsCrmUI.deliveryPrioritySignalHtml(line.delivery_priority) || '—'}</td>
				<td>${variant}</td>
				<td>${line.quantity}</td>
				<td>${line.qty_delivered}</td>
				<td><strong>${line.qty_due}</strong></td>
				<td class="${stockWarn}" title="${escapeHtml(stockTitle)}">${stockLabel}${stockTotal < line.qty_due ? ' ⚠' : ''}</td>
				<td>
					<input type="number" class="line-deliver-qty ds-crm-qty-input" min="0" max="${max}" step="1" value="0"${disabled} data-max="${max}" />
				</td>
				<td><input type="number" class="line-weight ds-crm-money-input" min="0" step="0.001" value="0"${disabled} /></td>
				<td><input type="number" class="line-shipping-rate ds-crm-money-input" min="0" step="0.01" value="${rate > 0 ? rate.toFixed(2) : '0'}"${disabled} /></td>
				<td class="ds-crm-line-shipping-bill"><span class="line-shipping-bill">${formatAmount(0)}</span></td>
			</tr>`;
			})
			.join('');

		linesBody.querySelectorAll('tr[data-order-item-id]').forEach(wireLineRow);
		updateTotals();
	};

	const applySelectedOrderUi = (order) => {
		selectedOrderMeta = order;
		if (orderIdInput) {
			orderIdInput.value = order?.id ? String(order.id) : '';
		}
		if (!order?.id) {
			if (orderSearch) {
				orderSearch.hidden = false;
			}
			if (selectedOrderEl) {
				selectedOrderEl.hidden = true;
				selectedOrderEl.innerHTML = '';
			}
			return;
		}
		if (orderSearch) {
			orderSearch.hidden = true;
			orderSearch.value = '';
		}
		if (selectedOrderEl) {
			const dueHint =
				order.lines_due > 0
					? `${order.lines_due} line${Number(order.lines_due) === 1 ? '' : 's'} due`
					: '';
			selectedOrderEl.hidden = false;
			selectedOrderEl.innerHTML = `<strong>${escapeHtml(order.order_number)}</strong> — ${escapeHtml(order.client_name || '—')}${order.client_phone ? ` (${escapeHtml(order.client_phone)})` : ''}${dueHint ? ` · ${escapeHtml(dueHint)}` : ''} <button type="button" class="button-link ds-crm-clear-delivery-order">Change</button>`;
			selectedOrderEl.querySelector('.ds-crm-clear-delivery-order')?.addEventListener('click', clearOrderSelection);
		}
		DsCrmUI.resetAutocompleteList(orderSuggestions);
	};

	const clearOrderSelection = () => {
		applySelectedOrderUi(null);
		if (orderSearch) {
			orderSearch.focus();
		}
		renderOrderPreview(null);
		renderDeliveryLines([]);
		setFormError('');
	};

	const renderOrderSuggestion = (order) => {
		const dueHint =
			order.lines_due > 0
				? `${order.lines_due} line${Number(order.lines_due) === 1 ? '' : 's'} due`
				: 'Items due';
		return `<button type="button" class="ds-crm-suggestion" data-id="${order.id}" data-order-number="${escapeHtml(order.order_number || '')}" data-client-name="${escapeHtml(order.client_name || '')}" data-client-phone="${escapeHtml(order.client_phone || '')}" data-lines-due="${parseInt(order.lines_due, 10) || 0}">
			<span class="ds-crm-suggestion-title">${escapeHtml(order.order_number)}</span>
			<span class="ds-crm-suggestion-meta">${escapeHtml(order.client_name || '—')}${order.client_phone ? ` · ${escapeHtml(order.client_phone)}` : ''} · ${formatDate(order.order_date)} · ${escapeHtml(dueHint)}</span>
		</button>`;
	};

	const loadOrderLines = async (orderId) => {
		if (!orderId) {
			renderOrderPreview(null);
			renderDeliveryLines([]);
			return;
		}

		if (linesBody) {
			linesBody.innerHTML = '<tr><td colspan="11" class="ds-crm-loading-row">Loading items…</td></tr>';
		}

		const res = await postAjax('crm_deliveries_order_lines', { order_id: orderId });
		if (!res.success) {
			setFormError(res.data?.message || 'Failed to load order lines.');
			renderDeliveryLines([]);
			return;
		}

		setFormError('');
		const order = res.data.order || {};
		const linesDue = (res.data.lines || []).filter((l) => (parseInt(l.qty_due, 10) || 0) > 0).length;
		applySelectedOrderUi({
			id: order.id,
			order_number: order.order_number,
			client_name: order.client_name,
			client_phone: order.client_phone,
			lines_due: linesDue,
		});
		renderOrderPreview(order);
		fillReceiverFromOrder(order);
		renderDeliveryLines(res.data.lines || []);
	};

	const selectOrder = (order) => {
		if (!order?.id) {
			return;
		}
		applySelectedOrderUi(order);
		setFormError('');
		loadOrderLines(parseInt(order.id, 10));
	};

	const collectLines = () => {
		const items = [];
		let invalidWeightLine = '';
		let invalidRateLine = '';

		linesBody?.querySelectorAll('tr[data-order-item-id]').forEach((tr) => {
			const qty = parseInt(tr.querySelector('.line-deliver-qty')?.value, 10) || 0;
			if (qty < 1) {
				return;
			}

			const weightKg = parseWeight(tr.querySelector('.line-weight')?.value);
			const shippingRate = parseFloat(tr.querySelector('.line-shipping-rate')?.value) || 0;
			const productName = tr.querySelector('.ds-crm-product-cell-name')?.textContent?.trim() || '';

			if (weightKg <= 0) {
				invalidWeightLine = productName || 'line';
				return;
			}

			if (shippingRate <= 0) {
				invalidRateLine = productName || 'line';
				return;
			}

			items.push({
				order_item_id: tr.dataset.orderItemId,
				quantity: qty,
				weight_kg: weightKg,
				shipping_rate_per_kg: shippingRate,
			});
		});

		if (invalidWeightLine) {
			return { error: `${invalidWeightLine}: enter weight in kg.` };
		}
		if (invalidRateLine) {
			return { error: `${invalidRateLine}: enter shipping rate (BDT / kg).` };
		}

		return { items };
	};

	const boot = async () => {
		const today = new Date().toISOString().slice(0, 10);
		form.querySelector('[name="delivery_date"]').value = today;

		if (presetOrderId) {
			await loadOrderLines(parseInt(presetOrderId, 10));
		}
	};

	orderSearch?.addEventListener(
		'input',
		debounce(async () => {
			const term = orderSearch.value.trim();
			if (term.length < 2) {
				if (term.length === 0) {
					DsCrmUI.resetAutocompleteList(orderSuggestions);
				} else {
					orderSuggestions.innerHTML =
						'<div class="ds-crm-autocomplete-hint">Type at least 2 characters to search orders…</div>';
					DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				}
				return;
			}

			orderSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Searching…</div>';
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);

			const result = await postAjax('crm_deliveries_orders', { search: term });
			if (!result.success) {
				orderSuggestions.innerHTML =
					'<div class="ds-crm-autocomplete-hint">Could not load orders. Try again.</div>';
				DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				return;
			}
			if (result.data?.hint && !result.data.orders?.length) {
				orderSuggestions.innerHTML = `<div class="ds-crm-autocomplete-hint">${escapeHtml(result.data.hint)}</div>`;
				DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				return;
			}
			if (!result.data.orders?.length) {
				orderSuggestions.innerHTML =
					'<div class="ds-crm-autocomplete-hint">No matching orders with items due.</div>';
				DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				return;
			}

			const moreHint = result.data?.truncated || result.data?.hint
				? `<div class="ds-crm-autocomplete-hint ds-crm-autocomplete-more">${escapeHtml(result.data.hint || 'Showing the first matches. Keep typing to narrow the list.')}</div>`
				: '';
			orderSuggestions.innerHTML = result.data.orders.map((o) => renderOrderSuggestion(o)).join('') + moreHint;
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
		}, 300)
	);

	orderSearch?.addEventListener('focus', () => {
		const term = orderSearch.value.trim();
		if (term.length >= 2) {
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
		} else {
			orderSuggestions.innerHTML =
				'<div class="ds-crm-autocomplete-hint">Type at least 2 characters to search orders…</div>';
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
		}
	});

	orderSuggestions?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-suggestion[data-id]');
		if (!btn) {
			return;
		}
		selectOrder({
			id: btn.dataset.id,
			order_number: btn.dataset.orderNumber,
			client_name: btn.dataset.clientName,
			client_phone: btn.dataset.clientPhone,
			lines_due: parseInt(btn.dataset.linesDue, 10) || 0,
		});
	});

	form?.addEventListener('submit', async (e) => {
		e.preventDefault();
		setFormError('');

		const orderId = parseInt(orderIdInput?.value, 10) || 0;
		const deliveryDate = form.querySelector('[name="delivery_date"]')?.value;

		if (!orderId || !deliveryDate) {
			setFormError('Order and delivery date are required.');
			return;
		}

		const collected = collectLines();
		if (collected.error) {
			setFormError(collected.error);
			return;
		}

		const items = collected.items;
		if (!items.length) {
			setFormError('Enter a deliver quantity for at least one line.');
			return;
		}

		const { shippingBill } = getLineTotals();
		if (shippingBill <= 0) {
			setFormError('Shipping bill must be greater than zero. Check weight and shipping rates on each line.');
			return;
		}

		setFormLoading(true);

		const res = await postAjax('crm_deliveries_save', {
			order_id: orderId,
			delivery_date: deliveryDate,
			receiver_name: form.querySelector('[name="receiver_name"]')?.value || '',
			receiver_phone: form.querySelector('[name="receiver_phone"]')?.value || '',
			receiver_address: form.querySelector('[name="receiver_address"]')?.value || '',
			notes: form.querySelector('[name="notes"]')?.value || '',
			items: JSON.stringify(items),
		});

		setFormLoading(false);

		if (!res.success) {
			setFormError(res.data?.message || 'Save failed.');
			return;
		}

		DsCrm.clearAjaxGetCache('crm_deliveries_');
		DsCrm.clearAjaxGetCache('crm_orders_');
		DsCrmUI.toast(res.data?.message || 'Delivery saved.');
		window.location.href = listUrl;
	});

	boot();
})();
