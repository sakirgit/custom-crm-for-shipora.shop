/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="shipment-form"]');
	if (!root) {
		return;
	}

	const {
		postAjax,
		formatDate,
		formatAmount,
		formatWeight,
		parseWeight,
		sumWeights,
		debounce,
		formatTrackingTime,
		DsCrmUI,
		buildModuleUrl,
	} = DsCrm;
	const form = root.querySelector('.ds-crm-shipment-form');
	const companySelects = () => Array.from(form?.querySelectorAll('select[name="company_id"]') || []);
	const companySelect = form?.querySelector('[name="company_id"]');
	const orderIdInput = form?.querySelector('[name="order_id"]');
	const orderSearch = form?.querySelector('.ds-crm-shipment-order-search');
	const orderSuggestions = form?.querySelector('.ds-crm-shipment-order-suggestions');
	const selectedOrderEl = form?.querySelector('.ds-crm-selected-shipment-order');
	const linesBody = form?.querySelector('.ds-crm-shipment-lines tbody');
	const orderPreviewWrap = root.querySelector('.ds-crm-shipment-order-preview');
	const orderPreviewInner = root.querySelector('.ds-crm-shipment-order-preview-inner');
	const errorBox = form?.querySelector('.ds-crm-form-error');
	const listUrl = buildModuleUrl('shipments');
	const presetOrderId = root.dataset.presetOrderId || '';
	const lockOrder = root.dataset.lockOrder === '1';
	const canAccept = root.dataset.canAccept === '1';
	const canEditPrices = root.dataset.canEditPrices === '1';
	const canRecordExport = root.dataset.canRecordExport === '1';
	const canAmend = root.dataset.canAmend === '1';
	const canReview = root.dataset.canReview === '1';
	let amendingItemKey = ''; // `${shipmentId}:${itemId}`
	const pageTitleEl = root.querySelector('#ds-crm-shipment-page-title');
	const pageLoadingEl = root.querySelector('.ds-crm-shipment-page-loading');
	const exportWrap = root.querySelector('.ds-crm-shipment-export-wrap');
	const exportInfoNotice = root.querySelector('.ds-crm-shipment-export-notice');
	const exportFormBody = root.querySelector('.ds-crm-shipment-export-form-body');
	const exportLockedCard = root.querySelector('.ds-crm-shipment-export-locked-card');
	const nextStepsHint = root.querySelector('.ds-crm-shipment-next-steps-hint');
	const workspaceHeader = root.querySelector('.ds-crm-shipment-workspace-header');
	const workspaceHeaderInner = root.querySelector('.ds-crm-shipment-workspace-header-inner');
	const workspaceSteps = root.querySelectorAll('.ds-crm-shipment-workspace-step');
	const reviewCard = root.querySelector('.ds-crm-shipment-order-review-card');
	const reviewMeta = root.querySelector('.ds-crm-shipment-order-review-meta');
	const reviewHint = root.querySelector('.ds-crm-shipment-order-review-hint');
	const reviewPricingHint = root.querySelector('.ds-crm-shipment-review-pricing-hint');
	const supplyCard = root.querySelector('.ds-crm-shipment-supply-card');
	const supplyActive = root.querySelector('.ds-crm-shipment-supply-active');
	const supplyComplete = root.querySelector('.ds-crm-shipment-supply-complete');
	const progressCard = root.querySelector('.ds-crm-shipment-progress-card');
	const progressMetrics = root.querySelector('.ds-crm-shipment-progress-metrics');
	const progressTrack = root.querySelector('.ds-crm-shipment-progress-track');
	const progressBar = root.querySelector('.ds-crm-shipment-progress-bar');
	const progressCaption = root.querySelector('.ds-crm-shipment-progress-caption');
	const shipperCard = root.querySelector('.ds-crm-shipment-shipper-card');
	const confirmSupplyBtn = root.querySelector('.ds-crm-shipment-confirm-supply');
	const editSupplyBtn = root.querySelector('.ds-crm-shipment-edit-supply');
	const supplyConfirmedNotice = root.querySelector('.ds-crm-shipment-supply-confirmed-notice');
	const historyCard = root.querySelector('.ds-crm-shipment-history-card');
	const historyList = root.querySelector('.ds-crm-shipment-history-list');
	const supplyNowQtyEl = root.querySelector('.ds-crm-shipment-supply-now-qty');
	const submitShipBtn = root.querySelector('.ds-crm-shipment-supply-actions .ds-crm-shipment-submit-ship')
		|| root.querySelector('.ds-crm-shipment-submit-ship');
	const formActionsBar = root.querySelector('.ds-crm-receive-form-actions-bar');
	const LINE_COL_COUNT = lockOrder ? 9 : 8;
	const reviewItemsBody = root.querySelector('.ds-crm-shipment-order-review-items tbody');
	const reviewItemsFoot = root.querySelector('.ds-crm-shipment-order-review-items tfoot');
	const reviewActions = root.querySelector('.ds-crm-shipment-order-review-actions');
	const savePricesBtn = root.querySelector('.ds-crm-shipment-save-prices');
	const acceptOrderBtn = root.querySelector('.ds-crm-shipment-accept-order');
	const saveAcceptBtn = root.querySelector('.ds-crm-shipment-save-accept-order');
	const supplyLinesFoot = root.querySelector('.ds-crm-shipment-supply-foot');
	const supplyGrandTotalEl = root.querySelector('.ds-crm-shipment-supply-grand-total');
	const supplyValueEl = root.querySelector('.ds-crm-shipment-supply-value');
	const REVIEW_COL_COUNT = lockOrder ? 7 : 6;
	const REVIEW_TOTAL_COLSPAN = lockOrder ? 6 : 5;

	let workspaceOrderId = presetOrderId ? parseInt(presetOrderId, 10) : 0;
	let reviewWorkflowBlocked = false;
	let supplyConfirmed = false;
	let lastRemaining = null;
	let lastShipments = [];
	let lastWorkspaceOrder = null;

	const supplyStorageKey = (orderId) => `ds_crm_supply_confirmed_${orderId}`;

	const readStoredSupplyConfirmed = (orderId) => {
		if (!orderId || !lockOrder) {
			return false;
		}
		try {
			return sessionStorage.getItem(supplyStorageKey(orderId)) === '1';
		} catch (err) {
			return false;
		}
	};

	const writeStoredSupplyConfirmed = (orderId, confirmed) => {
		if (!orderId || !lockOrder) {
			return;
		}
		try {
			if (confirmed) {
				sessionStorage.setItem(supplyStorageKey(orderId), '1');
			} else {
				sessionStorage.removeItem(supplyStorageKey(orderId));
			}
		} catch (err) {
			/* ignore */
		}
	};

	const orderWorkspaceUrl = (id) =>
		buildModuleUrl('shipments', { shipment_action: 'new', order_id: id });

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const statusLabel = (status) => {
		const labels = {
			in_transit: 'In transit',
			void: 'Void',
			pending: 'Pending',
			awaiting_acceptance: 'Awaiting acceptance',
			processing: 'Processing',
			completed: 'Completed',
			cancelled: 'Cancelled',
		};
		return labels[status] || status || '—';
	};

	const formatVariant = (item) => {
		const parts = [item.color, item.size].filter(Boolean);
		return parts.length ? parts.join(' / ') : '—';
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
		const exportLocked = exportWrap?.classList.contains('ds-crm-shipment-export--locked');
		form?.querySelectorAll('input, select, textarea, button').forEach((el) => {
			if (el.type === 'hidden') {
				return;
			}
			if (exportLocked && el.closest('.ds-crm-shipment-export-wrap')) {
				el.disabled = true;
				return;
			}
			el.disabled = loading;
		});
	};

	const setPageLoading = (loading) => {
		if (pageLoadingEl) {
			pageLoadingEl.hidden = !loading;
		}
	};

	const clearOrderSelection = () => {
		if (lockOrder) {
			return;
		}
		if (orderIdInput) {
			orderIdInput.value = '';
		}
		if (orderSearch) {
			orderSearch.hidden = false;
			orderSearch.value = '';
			orderSearch.disabled = false;
		}
		if (selectedOrderEl) {
			selectedOrderEl.hidden = true;
			selectedOrderEl.innerHTML = '';
		}
		if (orderSuggestions) {
			DsCrmUI.resetAutocompleteList(orderSuggestions);
		}
		renderOrderPreview(null);
		renderShipmentLines([]);
	};

	const lockOrderSelection = (order) => {
		if (!order || !lockOrder) {
			return;
		}
		if (orderIdInput) {
			orderIdInput.value = order.id;
		}
		if (orderSearch) {
			orderSearch.hidden = true;
			orderSearch.disabled = true;
		}
		if (selectedOrderEl) {
			selectedOrderEl.hidden = false;
			selectedOrderEl.innerHTML = `<strong>${escapeHtml(order.order_number)}</strong> — ${escapeHtml(order.client_name || '—')}${order.client_phone ? ` (${escapeHtml(order.client_phone)})` : ''}`;
		}
	};

	const recalcReviewTotals = () => {
		const rows = reviewItemsBody?.querySelectorAll('tr[data-item-id]') || [];
		let grandTotal = 0;
		rows.forEach((row) => {
			const acceptInput = row.querySelector('.ds-crm-review-accept-qty');
			const qty = acceptInput
				? parseInt(acceptInput.value, 10) || 0
				: parseInt(row.dataset.accepted || row.dataset.qty, 10) || 0;
			const priceInput = row.querySelector('.ds-crm-review-unit-price');
			const price = priceInput
				? parseFloat(priceInput.value) || 0
				: parseFloat(row.dataset.unitPrice) || 0;
			const lineTotal = qty * price;
			grandTotal += lineTotal;
			const lineTotalEl = row.querySelector('.ds-crm-review-line-total');
			if (lineTotalEl) {
				lineTotalEl.textContent = formatAmount(lineTotal);
			}
		});

		const totalRow = reviewItemsFoot?.querySelector('.ds-crm-order-lines-total');
		if (totalRow && reviewItemsFoot) {
			reviewItemsFoot.hidden = !rows.length;
			totalRow.innerHTML = `
				<td colspan="${REVIEW_TOTAL_COLSPAN}" class="ds-crm-order-total-label">Grand total</td>
				<td class="ds-crm-order-total-value ds-crm-review-grand-total">${formatAmount(grandTotal)}</td>`;
		}
	};

	const collectApproveLines = () => {
		const items = [];
		reviewItemsBody?.querySelectorAll('tr[data-item-id]').forEach((row) => {
			const ordered = parseInt(row.dataset.qty, 10) || 0;
			const acceptInput = row.querySelector('.ds-crm-review-accept-qty');
			const accepted = acceptInput
				? Math.max(0, parseInt(acceptInput.value, 10) || 0)
				: ordered;
			items.push({
				id: parseInt(row.dataset.itemId, 10),
				accepted_quantity: Math.min(accepted, ordered),
				unit_price: row.querySelector('.ds-crm-review-unit-price')?.value || 0,
			});
		});
		return items;
	};

	const collectReviewPrices = () => {
		if (lockOrder) {
			return collectApproveLines();
		}
		const items = [];
		reviewItemsBody?.querySelectorAll('tr[data-item-id]').forEach((row) => {
			items.push({
				id: parseInt(row.dataset.itemId, 10),
				unit_price: row.querySelector('.ds-crm-review-unit-price')?.value || 0,
			});
		});
		return items;
	};

	const validateApproveLines = () => {
		const items = collectApproveLines();
		let totalAccepted = 0;
		for (const item of items) {
			if (item.accepted_quantity > 0 && (parseFloat(item.unit_price) || 0) <= 0) {
				return 'Set a unit price for every product you accept.';
			}
			totalAccepted += item.accepted_quantity;
		}
		if (totalAccepted < 1) {
			return 'Accept at least one piece across the order.';
		}
		return '';
	};

	const orderNeedsPricingFromDom = () => {
		if (lockOrder) {
			return Boolean(validateApproveLines());
		}
		let needs = false;
		reviewItemsBody?.querySelectorAll('.ds-crm-review-unit-price').forEach((input) => {
			if ((parseFloat(input.value) || 0) <= 0) {
				needs = true;
			}
		});
		return needs;
	};

	const syncReviewPricingHints = () => {
		if (lockOrder) {
			if (reviewPricingHint) {
				reviewPricingHint.hidden = true;
				reviewPricingHint.textContent = '';
			}
			return;
		}

		const needsPricing = orderNeedsPricingFromDom();
		const missingCount =
			reviewItemsBody?.querySelectorAll('.ds-crm-review-unit-price').length
				? Array.from(reviewItemsBody.querySelectorAll('.ds-crm-review-unit-price')).filter(
						(input) => (parseFloat(input.value) || 0) <= 0
				  ).length
				: 0;

		reviewItemsBody?.querySelectorAll('tr[data-item-id]').forEach((row) => {
			const priceInput = row.querySelector('.ds-crm-review-unit-price');
			if (!priceInput) {
				row.classList.remove('ds-crm-review-line--needs-price');
				return;
			}
			row.classList.toggle('ds-crm-review-line--needs-price', (parseFloat(priceInput.value) || 0) <= 0);
		});

		if (reviewPricingHint) {
			if (reviewWorkflowBlocked && needsPricing && missingCount > 0) {
				reviewPricingHint.hidden = false;
				reviewPricingHint.textContent =
					missingCount === 1
						? '1 line still needs a unit price before you can approve.'
						: `${missingCount} lines still need a unit price before you can approve.`;
			} else {
				reviewPricingHint.hidden = true;
				reviewPricingHint.textContent = '';
			}
		}
	};

	const syncReviewActionButtons = () => {
		if (!reviewActions) {
			return;
		}

		if (lockOrder) {
			const showAccept = canAccept && reviewWorkflowBlocked;
			reviewActions.hidden = !showAccept;
			if (savePricesBtn) {
				savePricesBtn.hidden = true;
			}
			if (saveAcceptBtn) {
				saveAcceptBtn.hidden = true;
			}
			if (acceptOrderBtn) {
				acceptOrderBtn.hidden = !showAccept;
				acceptOrderBtn.disabled = false;
				acceptOrderBtn.title = '';
			}
			syncReviewPricingHints();
			return;
		}

		const showStaffActions = canEditPrices || canAccept;
		const showAccept = canAccept && reviewWorkflowBlocked;
		const showCombined = canEditPrices && showAccept;
		const needsPricing = orderNeedsPricingFromDom();

		reviewActions.hidden = !showStaffActions;
		if (savePricesBtn) {
			savePricesBtn.hidden = !canEditPrices;
		}
		if (acceptOrderBtn) {
			acceptOrderBtn.hidden = !showAccept;
			acceptOrderBtn.disabled = needsPricing;
			acceptOrderBtn.title = needsPricing ? 'Set a unit price on every line first' : '';
		}
		if (saveAcceptBtn) {
			saveAcceptBtn.hidden = !showCombined;
			saveAcceptBtn.disabled = needsPricing;
			saveAcceptBtn.title = needsPricing ? 'Set a unit price on every line first' : '';
		}

		syncReviewPricingHints();
	};

	const renderWorkspaceHeader = (data) => {
		if (!lockOrder || !workspaceHeaderInner) {
			return;
		}

		const { order, tracking } = data;
		lastWorkspaceOrder = order || null;
		const trackingLabel = tracking?.short_label || statusLabel(order.status);
		const metrics = tracking?.metrics || {};
		const ordered = metrics.qty_ordered ?? 0;
		const accepted = metrics.qty_accepted ?? 0;
		const exported = metrics.qty_exported ?? 0;

		workspaceHeaderInner.innerHTML = `
			<div class="ds-crm-shipment-workspace-header-top">
				<div class="ds-crm-order-meta-grid ds-crm-order-meta-grid--compact">
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Client</span>
						<span class="ds-crm-meta-value">${escapeHtml(order.client_name || '—')}${order.client_phone ? `<span class="ds-crm-meta-muted"> (${escapeHtml(order.client_phone)})</span>` : ''}</span>
					</div>
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Date</span>
						<span class="ds-crm-meta-value">${formatDate(order.order_date)}</span>
					</div>
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Status</span>
						<span class="ds-crm-meta-value"><span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(statusLabel(order.status))}</span></span>
					</div>
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Tracking</span>
						<span class="ds-crm-meta-value">${escapeHtml(trackingLabel)}</span>
					</div>
				</div>
			</div>
			<div class="ds-crm-shipment-workspace-header-stats">
				<div class="ds-crm-shipment-stat">
					<span class="ds-crm-shipment-stat-label">Ordered</span>
					<strong class="ds-crm-shipment-stat-value">${ordered} pcs</strong>
				</div>
				<div class="ds-crm-shipment-stat ds-crm-shipment-stat--accent">
					<span class="ds-crm-shipment-stat-label">Confirmed to supply</span>
					<strong class="ds-crm-shipment-stat-value">${accepted} pcs</strong>
				</div>
				<div class="ds-crm-shipment-stat">
					<span class="ds-crm-shipment-stat-label">On the way</span>
					<strong class="ds-crm-shipment-stat-value">${exported} pcs</strong>
				</div>
			</div>`;

		if (workspaceHeader) {
			workspaceHeader.hidden = false;
		}
	};

	const syncWorkspaceSteps = (workflowBlocked, remaining = lastRemaining) => {
		if (!lockOrder) {
			return;
		}

		workspaceSteps.forEach((step) => {
			step.classList.remove('is-active', 'is-done', 'is-pending');
		});

		const approveStep = root.querySelector('.ds-crm-shipment-workspace-step--approve');
		const supplyStep = root.querySelector('.ds-crm-shipment-workspace-step--supply');
		const fullySupplied = Boolean(remaining?.fully_supplied);

		if (workflowBlocked) {
			approveStep?.classList.add('is-active');
			supplyStep?.classList.add('is-pending');
		} else if (fullySupplied) {
			approveStep?.classList.add('is-done');
			supplyStep?.classList.add('is-done');
			const desc = supplyStep?.querySelector('.ds-crm-shipment-workspace-step-desc');
			if (desc) {
				desc.textContent = 'All accepted qty shipped';
			}
		} else {
			approveStep?.classList.add('is-done');
			supplyStep?.classList.add('is-active');
			const desc = supplyStep?.querySelector('.ds-crm-shipment-workspace-step-desc');
			if (desc) {
				const rem = remaining?.qty_remaining;
				desc.textContent =
					typeof rem === 'number' && rem > 0 ? `${rem} pcs left to ship` : 'Ship now & company';
			}
		}

		if (reviewCard) {
			reviewCard.classList.toggle('ds-crm-shipment-order-review-card--approved', !workflowBlocked);
			reviewCard.classList.toggle('is-collapsed', !workflowBlocked);
		}
		supplyCard?.classList.toggle('is-step-active', !workflowBlocked && !fullySupplied);
		supplyCard?.classList.toggle('is-supply-complete', fullySupplied);
	};

	const setSupplyRequiredFields = (required) => {
		const shipDate = form?.querySelector('[name="ship_date"]');
		if (shipDate) {
			shipDate.required = required;
		}
		companySelects().forEach((select) => {
			select.required = required;
		});
	};

	const renderSupplyProgress = (remaining = null, shipments = []) => {
		if (!lockOrder || !progressCard) {
			return;
		}

		if (reviewWorkflowBlocked || !remaining || !(remaining.qty_accepted > 0)) {
			progressCard.hidden = true;
			return;
		}

		const ordered = remaining.qty_ordered ?? 0;
		const accepted = remaining.qty_accepted ?? 0;
		const exported = remaining.qty_exported ?? 0;
		const rem = remaining.qty_remaining ?? 0;
		const batches = remaining.shipment_count ?? shipments.length;
		const weight = remaining.total_kg != null ? remaining.total_kg : sumWeights(shipments.map((s) => s.total_kg));
		const pct = remaining.pct_supplied ?? (accepted > 0 ? Math.min(100, Math.round((exported / accepted) * 100)) : 0);

		progressCard.hidden = false;
		progressCard.classList.toggle('is-complete', Boolean(remaining.fully_supplied));

		if (progressMetrics) {
			progressMetrics.innerHTML = `
				<div class="ds-crm-shipment-progress-metric">
					<span class="ds-crm-shipment-progress-metric-label">Ordered</span>
					<strong class="ds-crm-shipment-progress-metric-value">${ordered}</strong>
					<span class="ds-crm-shipment-progress-metric-unit">pcs</span>
				</div>
				<div class="ds-crm-shipment-progress-metric ds-crm-shipment-progress-metric--accent">
					<span class="ds-crm-shipment-progress-metric-label">Confirmed to supply</span>
					<strong class="ds-crm-shipment-progress-metric-value">${accepted}</strong>
					<span class="ds-crm-shipment-progress-metric-unit">pcs</span>
				</div>
				<div class="ds-crm-shipment-progress-metric">
					<span class="ds-crm-shipment-progress-metric-label">On the way</span>
					<strong class="ds-crm-shipment-progress-metric-value">${exported}</strong>
					<span class="ds-crm-shipment-progress-metric-unit">pcs</span>
				</div>
				<div class="ds-crm-shipment-progress-metric${remaining.fully_supplied ? ' is-done' : ''}">
					<span class="ds-crm-shipment-progress-metric-label">${remaining.fully_supplied ? 'Status' : 'Still to ship'}</span>
					<strong class="ds-crm-shipment-progress-metric-value">${remaining.fully_supplied ? 'Done' : rem}</strong>
					<span class="ds-crm-shipment-progress-metric-unit">${remaining.fully_supplied ? '' : 'pcs'}</span>
				</div>
				<div class="ds-crm-shipment-progress-metric">
					<span class="ds-crm-shipment-progress-metric-label">Batches</span>
					<strong class="ds-crm-shipment-progress-metric-value">${batches}</strong>
					<span class="ds-crm-shipment-progress-metric-unit">${batches === 1 ? 'shipment' : 'shipments'}</span>
				</div>
				<div class="ds-crm-shipment-progress-metric">
					<span class="ds-crm-shipment-progress-metric-label">Weight shipped</span>
					<strong class="ds-crm-shipment-progress-metric-value">${formatWeight(weight)}</strong>
					<span class="ds-crm-shipment-progress-metric-unit">kg</span>
				</div>`;
		}

		if (progressTrack && progressBar) {
			progressTrack.hidden = false;
			progressBar.style.width = `${pct}%`;
			progressTrack.setAttribute('aria-valuenow', String(pct));
		}

		if (progressCaption) {
			progressCaption.hidden = false;
			progressCaption.textContent = remaining.fully_supplied
				? `All ${accepted} confirmed pcs are on the way (${batches} ${batches === 1 ? 'shipment' : 'shipments'}).`
				: `${exported} of ${accepted} confirmed pcs shipped (${pct}%). ${rem} pcs still to ship.`;
		}
	};

	const renderSupplyCompleteState = (remaining = null) => {
		if (!lockOrder || !supplyComplete) {
			return;
		}

		const fully = Boolean(remaining?.fully_supplied);
		if (!fully) {
			supplyComplete.hidden = true;
			supplyComplete.innerHTML = '';
			return;
		}

		const accepted = remaining.qty_accepted ?? 0;
		const exported = remaining.qty_exported ?? 0;
		const batches = remaining.shipment_count ?? lastShipments.length;
		const weight = remaining.total_kg != null ? remaining.total_kg : sumWeights(lastShipments.map((s) => s.total_kg));
		const orderViewUrl = workspaceOrderId
			? buildModuleUrl('orders', { order_action: 'view', order_id: workspaceOrderId })
			: listUrl;

		supplyComplete.hidden = false;
		supplyComplete.innerHTML = `
			<div class="ds-crm-shipment-supply-complete-inner">
				<div class="ds-crm-shipment-supply-complete-badge" aria-hidden="true">✓</div>
				<div class="ds-crm-shipment-supply-complete-copy">
					<h2 class="ds-crm-receive-form-section-title">Supply complete</h2>
					<p class="description">All confirmed quantity is on the way. No further Confirm supply is needed for this order.</p>
					<ul class="ds-crm-shipment-supply-complete-stats">
						<li><strong>${accepted}</strong> confirmed to supply</li>
						<li><strong>${exported}</strong> pcs on the way</li>
						<li><strong>${batches}</strong> ${batches === 1 ? 'batch' : 'batches'} · ${escapeHtml(formatWeight(weight, { withUnit: true }))}</li>
					</ul>
					<p class="ds-crm-shipment-supply-complete-actions">
						<a class="button" href="${escapeHtml(orderViewUrl)}">View order</a>
						<a class="button" href="${escapeHtml(listUrl)}">Back to ${escapeHtml(DsCrmApp?.moduleLabels?.shipments || 'shipments')}</a>
					</p>
				</div>
			</div>`;
	};

	const setSupplyInputsLocked = () => {};

	const syncSupplyShipperVisibility = () => {
		if (!lockOrder) {
			return;
		}

		const approved = !reviewWorkflowBlocked;
		const fully = Boolean(lastRemaining?.fully_supplied);

		if (nextStepsHint) {
			nextStepsHint.hidden = approved;
		}
		if (progressCard && (!approved || !(lastRemaining?.qty_accepted > 0))) {
			progressCard.hidden = true;
		}
		if (supplyCard) {
			supplyCard.hidden = !approved;
		}
		if (supplyActive) {
			supplyActive.hidden = !approved || fully;
		}
		if (fully) {
			renderSupplyCompleteState(lastRemaining);
		} else if (supplyComplete) {
			supplyComplete.hidden = true;
		}
		setSupplyRequiredFields(approved && !fully);
		if (shipperCard) {
			shipperCard.hidden = true;
		}
		if (formActionsBar) {
			formActionsBar.hidden = true;
		}
		if (submitShipBtn) {
			submitShipBtn.hidden = !approved || fully;
		}
		if (confirmSupplyBtn) {
			confirmSupplyBtn.hidden = true;
		}
		if (editSupplyBtn) {
			editSupplyBtn.hidden = true;
		}
		if (supplyConfirmedNotice) {
			supplyConfirmedNotice.hidden = true;
		}

		syncWorkspaceSteps(reviewWorkflowBlocked, lastRemaining);
	};

	const getSupplyLineState = () => {
		const rows = [];
		let hasQty = false;
		let missingPrice = false;

		linesBody?.querySelectorAll('tr[data-order-item-id]').forEach((row) => {
			const qty = parseInt(row.querySelector('.line-ship-qty')?.value, 10) || 0;
			const priceInput = row.querySelector('.line-unit-price');
			const price = priceInput
				? parseFloat(priceInput.value) || 0
				: parseFloat(row.dataset.unitPrice) || 0;

			if (qty > 0) {
				hasQty = true;
				if (price <= 0) {
					missingPrice = true;
					row.classList.add('ds-crm-supply-line--needs-price');
				} else {
					row.classList.remove('ds-crm-supply-line--needs-price');
				}
			} else {
				row.classList.remove('ds-crm-supply-line--needs-price');
			}

			rows.push({
				id: parseInt(row.dataset.orderItemId, 10),
				qty,
				unit_price: price,
			});
		});

		return {
			rows,
			hasQty,
			missingPrice,
			supplyReady: hasQty && !missingPrice,
		};
	};

	const hasSupplyQtySelected = () => getSupplyLineState().hasQty;

	const renderReviewSection = (data, { editable = false } = {}) => {
		const { order, items, workflow_blocked, needs_pricing, tracking } = data;
		reviewWorkflowBlocked = Boolean(workflow_blocked);

		if (pageTitleEl) {
			pageTitleEl.textContent = `Order ${order.order_number}`;
		}

		renderWorkspaceHeader(data);
		syncWorkspaceSteps(workflow_blocked);

		if (lockOrder) {
			if (reviewMeta) {
				reviewMeta.hidden = true;
				reviewMeta.innerHTML = '';
			}
			if (reviewHint) {
				if (workflow_blocked) {
					reviewHint.hidden = false;
					reviewHint.textContent =
						'Set how many pieces you accept (can be less than ordered) and the unit price. Accepted quantity becomes the final order commitment.';
				} else {
					reviewHint.hidden = false;
					reviewHint.textContent = lastRemaining?.fully_supplied
						? 'Order approved and fully supplied. Confirmed quantity and prices stay locked.'
						: 'Order approved. Accepted quantity and prices are locked. Continue to Confirm supply to ship now or later.';
				}
			}
		} else if (reviewMeta) {
			const trackingLabel = tracking?.short_label || statusLabel(order.status);
			const workflowNotice = workflow_blocked
				? '<div class="ds-crm-notice ds-crm-notice-warning ds-crm-order-accept-notice">Set final unit prices on each line, then accept this order. The export form below unlocks after approval.</div>'
				: '<div class="ds-crm-notice ds-crm-notice-info">This order is approved. Final prices are locked. Record export shipments in the section below.</div>';

			reviewMeta.hidden = false;
			reviewMeta.innerHTML = `
				<div class="ds-crm-order-meta-grid">
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Client</span>
						<span class="ds-crm-meta-value">${escapeHtml(order.client_name || '—')}${order.client_phone ? `<span class="ds-crm-meta-muted"> (${escapeHtml(order.client_phone)})</span>` : ''}</span>
					</div>
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Date</span>
						<span class="ds-crm-meta-value">${formatDate(order.order_date)}</span>
					</div>
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Status</span>
						<span class="ds-crm-meta-value"><span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(statusLabel(order.status))}</span></span>
					</div>
					<div class="ds-crm-meta-item">
						<span class="ds-crm-meta-label">Tracking</span>
						<span class="ds-crm-meta-value">${escapeHtml(trackingLabel)}</span>
					</div>
				</div>
				${workflowNotice}
				${needs_pricing && workflow_blocked ? '<div class="ds-crm-notice ds-crm-notice-info">Some lines still need a unit price.</div>' : ''}
			`;
			if (reviewHint) {
				reviewHint.hidden = true;
				reviewHint.textContent = '';
			}
		}

		const sortedItems = DsCrmUI.sortByDeliveryPriority(items || []);
		const priceEditable = !lockOrder && editable && canEditPrices;

		if (!sortedItems.length) {
			reviewItemsBody.innerHTML = `<tr><td colspan="${REVIEW_COL_COUNT}" class="ds-crm-empty">No line items.</td></tr>`;
			if (reviewItemsFoot) {
				reviewItemsFoot.hidden = true;
			}
		} else if (lockOrder) {
			const canEditApprove = editable || (workflow_blocked && (canAccept || canEditPrices));
			reviewItemsBody.innerHTML = sortedItems
				.map((item) => {
					const ordered = parseInt(item.quantity, 10) || 0;
					const accepted =
						item.accepted_quantity != null && item.accepted_quantity !== ''
							? parseInt(item.accepted_quantity, 10)
							: ordered;
					const price = parseFloat(item.unit_price || 0);
					const acceptQty = Math.min(accepted, ordered);
					if (canEditApprove && workflow_blocked) {
						return `
				<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}" data-item-id="${item.id}" data-qty="${ordered}" data-accepted="${acceptQty}" data-unit-price="${price}">
					<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
					<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority) || '—'}</td>
					<td class="ds-crm-review-variant-cell">${escapeHtml(formatVariant(item))}</td>
					<td class="ds-crm-review-qty-cell">${ordered}</td>
					<td><input type="number" class="ds-crm-review-accept-qty ds-crm-qty-input" min="0" max="${ordered}" step="1" value="${acceptQty}" aria-label="Accept quantity" /></td>
					<td class="ds-crm-shipment-price-col"><input type="number" class="ds-crm-review-unit-price" min="0" step="0.01" value="${price.toFixed(2)}" aria-label="Unit price" /></td>
					<td class="ds-crm-review-line-total">${formatAmount(acceptQty * price)}</td>
				</tr>`;
					}
					return `
				<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}" data-item-id="${item.id}" data-qty="${ordered}" data-accepted="${acceptQty}" data-unit-price="${price}">
					<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
					<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority) || '—'}</td>
					<td class="ds-crm-review-variant-cell">${escapeHtml(formatVariant(item))}</td>
					<td class="ds-crm-review-qty-cell">${ordered}</td>
					<td class="ds-crm-review-qty-cell"><strong>${accepted}</strong></td>
					<td class="ds-crm-shipment-price-col">${formatAmount(price)}</td>
					<td class="ds-crm-review-line-total">${formatAmount(acceptQty * price)}</td>
				</tr>`;
				})
				.join('');
			if (canEditApprove && workflow_blocked) {
				recalcReviewTotals();
				reviewItemsBody.querySelectorAll('.ds-crm-review-unit-price, .ds-crm-review-accept-qty').forEach((input) => {
					input.addEventListener('input', () => {
						const row = input.closest('tr[data-item-id]');
						if (row) {
							const acceptInput = row.querySelector('.ds-crm-review-accept-qty');
							const priceInput = row.querySelector('.ds-crm-review-unit-price');
							if (acceptInput) {
								row.dataset.accepted = String(parseInt(acceptInput.value, 10) || 0);
							}
							if (priceInput) {
								row.dataset.unitPrice = String(parseFloat(priceInput.value) || 0);
							}
						}
						recalcReviewTotals();
						syncReviewActionButtons();
					});
				});
			} else if (reviewItemsFoot) {
				recalcReviewTotals();
			}
		} else {
			reviewItemsBody.innerHTML = sortedItems
				.map((item) => {
					const qty = parseFloat(item.quantity) || 0;
					const price = parseFloat(item.unit_price || 0);
					return `
				<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}" data-item-id="${item.id}" data-qty="${qty}" data-accepted="${qty}" data-unit-price="${price}">
					<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
					<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority) || '—'}</td>
					<td class="ds-crm-review-variant-cell">${escapeHtml(formatVariant(item))}</td>
					<td class="ds-crm-review-qty-cell">${item.quantity}</td>
					<td class="ds-crm-shipment-price-col">
						${priceEditable
							? `<input type="number" class="ds-crm-review-unit-price" min="0" step="0.01" value="${price.toFixed(2)}" aria-label="Unit price" />`
							: formatAmount(item.unit_price)}
					</td>
					<td class="ds-crm-review-line-total">${formatAmount(qty * price)}</td>
				</tr>`;
				})
				.join('');
			recalcReviewTotals();
			reviewItemsBody.querySelectorAll('.ds-crm-review-unit-price').forEach((input) => {
				input.addEventListener('input', () => {
					const row = input.closest('tr[data-item-id]');
					if (row) {
						row.dataset.unitPrice = String(parseFloat(input.value) || 0);
					}
					recalcReviewTotals();
					syncReviewActionButtons();
				});
			});
			syncReviewPricingHints();
		}

		syncReviewActionButtons();
		if (reviewCard) {
			reviewCard.hidden = false;
		}
		if (lockOrder) {
			syncSupplyShipperVisibility();
		}
	};

	const showExportSection = (show) => {
		if (exportWrap) {
			exportWrap.hidden = !show;
		}
	};

	const setExportSectionLocked = (locked) => {
		if (exportWrap) {
			exportWrap.classList.toggle('ds-crm-shipment-export--locked', locked);
		}

		if (lockOrder) {
			if (nextStepsHint) {
				nextStepsHint.hidden = !locked;
			}
			if (exportFormBody) {
				exportFormBody.hidden = locked;
			}
			return;
		}

		if (exportFormBody) {
			exportFormBody.hidden = false;
		}
		if (exportLockedCard) {
			exportLockedCard.hidden = !locked;
		}
		if (nextStepsHint) {
			nextStepsHint.hidden = true;
		}

		if (form) {
			form.querySelectorAll('input, select, textarea, button[type="submit"]').forEach((el) => {
				if (el.type === 'hidden' || el.classList.contains('ds-crm-shipment-order-search')) {
					return;
				}
				el.disabled = locked;
			});
		}
	};

	const prepareExportShell = async (order) => {
		if (!canRecordExport || !form) {
			return;
		}

		showExportSection(true);
		if (exportInfoNotice) {
			exportInfoNotice.hidden = true;
		}
		await loadFormData();

		const shipDateInput = form.querySelector('[name="ship_date"]');
		if (shipDateInput && !shipDateInput.value) {
			shipDateInput.value = new Date().toISOString().slice(0, 10);
		}

		if (order) {
			lockOrderSelection(order);
			if (orderIdInput) {
				orderIdInput.value = order.id;
			}
		}
	};

	const loadOrderWorkspace = async (orderId) => {
		if (!orderId) {
			return;
		}

		workspaceOrderId = orderId;
		setPageLoading(true);
		setFormError('');

		const res = await postAjax('crm_orders_get', { id: orderId });
		setPageLoading(false);

		if (!res.success) {
			setFormError(res.data?.message || 'Failed to load order.');
			return;
		}

		const { order, workflow_blocked } = res.data;
		const reviewEditable = workflow_blocked && (canEditPrices || canAccept);

		renderReviewSection(res.data, { editable: reviewEditable });

		if (!canRecordExport) {
			showExportSection(false);
			return;
		}

		await prepareExportShell(order);

		if (workflow_blocked) {
			setExportSectionLocked(true);
			lastRemaining = null;
			lastShipments = [];
			if (progressCard) {
				progressCard.hidden = true;
			}
			if (historyCard) {
				historyCard.hidden = true;
			}
			syncSupplyShipperVisibility();
			return;
		}

		setExportSectionLocked(false);
		await loadOrderLines(orderId);
		syncSupplyShipperVisibility();
	};

	const saveReviewPrices = async () => {
		if (!workspaceOrderId || !canEditPrices) {
			return;
		}
		const items = collectReviewPrices();
		if (!items.length) {
			return;
		}
		if (savePricesBtn) {
			savePricesBtn.disabled = true;
		}
		const res = await postAjax('crm_orders_save_prices', {
			order_id: workspaceOrderId,
			items: JSON.stringify(items),
		});
		if (savePricesBtn) {
			savePricesBtn.disabled = false;
		}
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Failed to save prices.', 'error');
			return;
		}
		DsCrmUI.toast(res.data?.message || 'Prices saved.');
		DsCrm.clearAjaxGetCache('crm_orders_');
		await loadOrderWorkspace(workspaceOrderId);
	};

	const acceptReviewOrder = async ({ skipConfirm = false, skipSave = false } = {}) => {
		if (!workspaceOrderId || !canAccept) {
			return;
		}

		if (lockOrder) {
			const err = validateApproveLines();
			if (err) {
				DsCrmUI.toast(err, 'error');
				return;
			}
		} else if (orderNeedsPricingFromDom()) {
			DsCrmUI.toast('Set a unit price on every line before approving.', 'error');
			return;
		}

		if (
			!skipConfirm &&
			!window.confirm(
				lockOrder
					? 'Approve with the accepted quantities and prices? Accepted quantity becomes the final order commitment. You can ship all or part of it next.'
					: 'Approve this order for China sourcing?'
			)
		) {
			return;
		}

		if (!lockOrder && !skipSave && canEditPrices) {
			const items = collectReviewPrices();
			const saveRes = await postAjax('crm_orders_save_prices', {
				order_id: workspaceOrderId,
				items: JSON.stringify(items),
			});
			if (!saveRes.success) {
				DsCrmUI.toast(saveRes.data?.message || 'Failed to save prices before approve.', 'error');
				return;
			}
		}

		const acceptPayload = { id: workspaceOrderId };
		if (lockOrder) {
			acceptPayload.items = JSON.stringify(collectApproveLines());
		} else {
			// Non-workspace: build acceptance from ordered qty + current prices.
			acceptPayload.items = JSON.stringify(
				collectReviewPrices().map((item) => {
					const row = reviewItemsBody?.querySelector(`tr[data-item-id="${item.id}"]`);
					return {
						id: item.id,
						accepted_quantity: parseInt(row?.dataset.qty, 10) || 0,
						unit_price: item.unit_price,
					};
				})
			);
		}

		const res = await postAjax('crm_orders_accept', acceptPayload);
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Approve failed.', 'error');
			return;
		}
		DsCrmUI.toast(res.data?.message || 'Order approved.');
		DsCrm.clearAjaxGetCache('crm_orders_');
		DsCrm.clearAjaxGetCache('crm_shipments_');
		const wasBlocked = reviewWorkflowBlocked;
		await loadOrderWorkspace(workspaceOrderId);
		if (wasBlocked && canRecordExport && supplyCard) {
			supplyCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	};

	const saveAndAcceptReviewOrder = async () => {
		if (!workspaceOrderId || !canEditPrices || !canAccept) {
			return;
		}
		if (orderNeedsPricingFromDom()) {
			DsCrmUI.toast('Set a unit price on every line first.', 'error');
			return;
		}
		if (
			!window.confirm(
				'Save prices and approve this order? Next you will confirm supply quantities and assign a shipper.'
			)
		) {
			return;
		}
		const items = collectReviewPrices();
		if (saveAcceptBtn) {
			saveAcceptBtn.disabled = true;
		}
		const saveRes = await postAjax('crm_orders_save_prices', {
			order_id: workspaceOrderId,
			items: JSON.stringify(items),
		});
		if (saveAcceptBtn) {
			saveAcceptBtn.disabled = false;
		}
		if (!saveRes.success) {
			DsCrmUI.toast(saveRes.data?.message || 'Failed to save prices.', 'error');
			return;
		}
		await acceptReviewOrder({ skipConfirm: true, skipSave: true });
	};

	const selectOrder = (order) => {
		if (!order?.id) {
			return;
		}
		const canExport = order.can_export === true || order.can_export === 1 || order.can_export === '1';
		if (!canExport) {
			window.location.href = orderWorkspaceUrl(order.id);
			return;
		}
		if (orderIdInput) {
			orderIdInput.value = order.id;
		}
		if (orderSearch) {
			orderSearch.hidden = true;
			orderSearch.value = '';
		}
		if (selectedOrderEl) {
			const linesHint =
				order.lines_to_export > 0
					? `${order.lines_to_export} line${order.lines_to_export === 1 ? '' : 's'} to export`
					: '';
			selectedOrderEl.hidden = false;
			selectedOrderEl.innerHTML = `<strong>${escapeHtml(order.order_number)}</strong> — ${escapeHtml(order.client_name || '—')}${order.client_phone ? ` (${escapeHtml(order.client_phone)})` : ''}${linesHint ? ` · ${escapeHtml(linesHint)}` : ''} <button type="button" class="button-link ds-crm-clear-shipment-order">Change</button>`;
			selectedOrderEl.querySelector('.ds-crm-clear-shipment-order')?.addEventListener('click', clearOrderSelection);
		}
		if (orderSuggestions) {
			DsCrmUI.resetAutocompleteList(orderSuggestions);
		}
		setFormError('');
		loadFormData();
		loadOrderLines(parseInt(order.id, 10));
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
				<span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(order.status_label || order.status)}</span>
			</div>
			<p><strong>${escapeHtml(order.client_name || '—')}</strong>${order.client_phone ? ` · ${escapeHtml(order.client_phone)}` : ''}</p>
			<p class="description">${formatDate(order.order_date)}</p>
		`;
		orderPreviewWrap.hidden = false;
	};

	const renderOrderSuggestion = (order) => {
		const canExport = order.can_export === true || order.can_export === 1 || order.can_export === '1';
		const meta = [
			escapeHtml(order.client_name || '—'),
			formatDate(order.order_date),
			order.lines_to_export ? `${order.lines_to_export} to export` : '',
		]
			.filter(Boolean)
			.join(' · ');
		const statusBadge = order.status_label
			? `<span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(order.status_label)}</span>`
			: '';

		if (!canExport) {
			return `<a class="ds-crm-suggestion ds-crm-suggestion--blocked" href="${orderWorkspaceUrl(order.id)}">
				<span class="ds-crm-suggestion-head"><strong>${escapeHtml(order.order_number)}</strong> ${statusBadge}</span>
				<span class="description">${meta}</span>
				<span class="ds-crm-suggestion-blocked-note">Open to review and approve</span>
			</a>`;
		}

		return `<button type="button" class="ds-crm-suggestion" data-can-export="1" data-id="${order.id}" data-order-number="${escapeHtml(order.order_number)}" data-client-name="${escapeHtml(order.client_name || '')}" data-client-phone="${escapeHtml(order.client_phone || '')}" data-lines-to-export="${order.lines_to_export || 0}" data-status-label="${escapeHtml(order.status_label || '')}">
			<span class="ds-crm-suggestion-head"><strong>${escapeHtml(order.order_number)}</strong></span>
			<span class="description">${meta}</span>
		</button>`;
	};

	const updateTotals = () => {
		const weights = [];
		let grandTotal = 0;
		let hasSupplyRows = false;
		let supplyNowQty = 0;

		linesBody?.querySelectorAll('tr[data-order-item-id]').forEach((row) => {
			const qty = parseInt(row.querySelector('.line-ship-qty')?.value, 10) || 0;
			const priceInput = row.querySelector('.line-unit-price');
			const price = priceInput
				? parseFloat(priceInput.value) || 0
				: parseFloat(row.dataset.unitPrice) || 0;
			const lineTotal = qty * price;
			const lineTotalEl = row.querySelector('.line-supply-total');

			if (lineTotalEl) {
				lineTotalEl.textContent = qty > 0 ? formatAmount(lineTotal) : '—';
			}

			if (qty > 0) {
				hasSupplyRows = true;
				supplyNowQty += qty;
				grandTotal += lineTotal;
				weights.push(parseWeight(row.querySelector('.line-weight')?.value));
			}
		});

		const totalKg = sumWeights(weights);
		const totalEl = root.querySelector('.ds-crm-shipment-total-kg');
		if (totalEl) {
			totalEl.textContent = formatWeight(totalKg, { withUnit: true });
		}
		if (supplyNowQtyEl) {
			supplyNowQtyEl.textContent = `${supplyNowQty} pcs`;
		}

		if (supplyGrandTotalEl) {
			supplyGrandTotalEl.textContent = hasSupplyRows ? formatAmount(grandTotal) : '—';
		}
		if (supplyValueEl) {
			supplyValueEl.textContent = hasSupplyRows ? formatAmount(grandTotal) : '—';
		}
		if (supplyLinesFoot) {
			supplyLinesFoot.hidden = !lockOrder || !hasSupplyRows;
		}
	};

	const wireLineRow = (row) => {
		const refreshSupplyStep = () => {
			updateTotals();
			if (lockOrder && !reviewWorkflowBlocked) {
				if (supplyConfirmed) {
					supplyConfirmed = false;
					writeStoredSupplyConfirmed(workspaceOrderId, false);
				}
				syncSupplyShipperVisibility();
			}
		};
		row.querySelector('.line-ship-qty')?.addEventListener('input', refreshSupplyStep);
		row.querySelector('.line-unit-price')?.addEventListener('input', refreshSupplyStep);
		row.querySelector('.line-weight')?.addEventListener('input', updateTotals);
	};

	const renderShipmentLines = (lines) => {
		if (!linesBody) {
			return;
		}

		if (lockOrder && lastRemaining?.fully_supplied) {
			linesBody.innerHTML = '';
			updateTotals();
			syncSupplyShipperVisibility();
			return;
		}

		if (!lines?.length) {
			linesBody.innerHTML = `<tr><td colspan="${LINE_COL_COUNT}" class="ds-crm-empty">No line items on this order.</td></tr>`;
			updateTotals();
			if (lockOrder && !reviewWorkflowBlocked) {
				syncSupplyShipperVisibility();
			}
			return;
		}

		const dueLines = lines.filter((l) => l.qty_to_export > 0);
		if (!dueLines.length) {
			linesBody.innerHTML = `<tr><td colspan="${LINE_COL_COUNT}" class="ds-crm-empty">All confirmed products have already been shipped.</td></tr>`;
			updateTotals();
			if (lockOrder && !reviewWorkflowBlocked) {
				syncSupplyShipperVisibility();
			}
			return;
		}

		const sortedLines = DsCrmUI.sortByDeliveryPriority(dueLines);

		linesBody.innerHTML = sortedLines
			.map((line) => {
				const variant = escapeHtml([line.color, line.size].filter(Boolean).join(' / ') || '—');
				const max = line.qty_to_export;
				const accepted =
					line.accepted_quantity != null && line.accepted_quantity !== ''
						? line.accepted_quantity
						: line.qty_accepted != null
							? line.qty_accepted
							: line.quantity;
				const acceptedCell = lockOrder ? `<td><strong>${accepted}</strong></td>` : '';

				return `
			<tr data-order-item-id="${line.id}" data-unit-price="${parseFloat(line.unit_price || 0)}" class="ds-crm-order-line--${escapeHtml(line.delivery_priority || 'normal')}">
				<td>${DsCrmUI.productCell(line.product_name, line.product_image_url, { size: 'sm', fullImageUrl: line.product_full_image_url || line.product_image_url })}</td>
				<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(line.delivery_priority)}</td>
				<td>${variant}</td>
				<td>${line.quantity}</td>
				${acceptedCell}
				<td>${line.qty_exported}</td>
				<td><strong>${line.qty_to_export}</strong></td>
				<td>
					<input type="number" class="line-ship-qty ds-crm-qty-input" min="0" max="${max}" step="1" value="0" data-max="${max}" />
				</td>
				<td><input type="number" class="line-weight ds-crm-money-input" min="0" step="0.001" value="0" /></td>
			</tr>`;
			})
			.join('');

		linesBody.querySelectorAll('tr[data-order-item-id]').forEach(wireLineRow);
		updateTotals();
		if (lockOrder && !reviewWorkflowBlocked) {
			syncSupplyShipperVisibility();
		}
	};

	const confirmSupplyStep = async () => {
		if (!lockOrder || !workspaceOrderId || reviewWorkflowBlocked) {
			return;
		}

		const supplyState = getSupplyLineState();
		if (!supplyState.hasQty) {
			setFormError('Enter a supply quantity for at least one product.');
			supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
			return;
		}
		if (supplyState.missingPrice) {
			setFormError('Set a unit price for every product you are supplying.');
			supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
			return;
		}

		setFormError('');

		if (canEditPrices) {
			const priceItems = supplyState.rows
				.filter((row) => row.qty > 0 || row.unit_price > 0)
				.map((row) => ({
					id: row.id,
					unit_price: row.unit_price,
				}));

			if (priceItems.length) {
				if (confirmSupplyBtn) {
					confirmSupplyBtn.disabled = true;
				}
				const priceRes = await postAjax('crm_orders_save_prices', {
					order_id: workspaceOrderId,
					items: JSON.stringify(priceItems),
				});
				if (confirmSupplyBtn) {
					confirmSupplyBtn.disabled = false;
				}
				if (!priceRes.success) {
					setFormError(priceRes.data?.message || 'Failed to save supply prices.');
					return;
				}
				DsCrm.clearAjaxGetCache('crm_orders_');
			}
		}

		supplyConfirmed = true;
		writeStoredSupplyConfirmed(workspaceOrderId, true);
		DsCrmUI.toast('Supply confirmed. Now assign a shipper.');
		syncSupplyShipperVisibility();
		shipperCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	const editSupplyStep = () => {
		if (!lockOrder) {
			return;
		}
		supplyConfirmed = false;
		writeStoredSupplyConfirmed(workspaceOrderId, false);
		syncSupplyShipperVisibility();
		supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	const fillCompanies = (companies) => {
		const selects = companySelects();
		if (!selects.length) {
			return;
		}
		const list = Array.isArray(companies) ? companies : [];
		const options = list
			.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
			.join('');
		const html = `<option value="">— Select company —</option>${options}`;
		selects.forEach((select) => {
			const prev = select.value;
			select.innerHTML = html;
			if (prev && Array.from(select.options).some((opt) => opt.value === prev)) {
				select.value = prev;
			}
		});
	};

	const loadFormData = async () => {
		if (!canRecordExport) {
			return;
		}
		try {
			const res = await postAjax('crm_shipments_form_data');
			if (res.success) {
				const companies = res.data?.companies || [];
				if (companies.length) {
					fillCompanies(companies);
				} else if (!companySelects().some((sel) => sel.options.length > 1)) {
					DsCrmUI.toast(
						'No active cargo companies found. Add one under Companies (type: Cargo).',
						'error'
					);
				}
				return;
			}
			if (!companySelects().some((sel) => sel.options.length > 1)) {
				DsCrmUI.toast(res.data?.message || 'Could not load export companies.', 'error');
			}
		} catch (err) {
			if (!companySelects().some((sel) => sel.options.length > 1)) {
				DsCrmUI.toast('Could not load export companies. Refresh and try again.', 'error');
			}
		}
	};

	const formatSupplyTime = (time) => formatTrackingTime(time);

	const itemAmendKey = (shipmentId, itemId) => `${shipmentId}:${itemId}`;

	const submitProductAmend = async (tr) => {
		const shipmentId = parseInt(tr?.dataset.shipmentId, 10) || 0;
		const itemId = parseInt(tr?.dataset.shipmentItemId, 10) || 0;
		const currentQty = parseInt(tr?.dataset.currentQty, 10) || 0;
		const currentWeight = parseFloat(tr?.dataset.currentWeight) || 0;
		const reasonRow = historyList?.querySelector(`tr.ds-crm-history-amend-reason-row[data-amend-key="${itemAmendKey(shipmentId, itemId)}"]`);
		const reason = (reasonRow?.querySelector('.ds-crm-history-amend-reason')?.value || tr?.querySelector('.ds-crm-history-amend-reason')?.value || '').trim();
		const qtyInput = tr?.querySelector('.ds-crm-history-amend-qty');
		const weightInput = tr?.querySelector('.ds-crm-history-amend-weight');

		if (!shipmentId || !itemId) {
			return;
		}
		if (!reason) {
			DsCrmUI.toast('Please enter a reason for this product change.', 'error');
			return;
		}

		const nextQty = parseInt(qtyInput?.value, 10);
		const nextWeight = parseWeight(weightInput?.value);
		if (Number.isNaN(nextQty) || nextQty < 0 || nextQty > currentQty) {
			DsCrmUI.toast('New quantity cannot be higher than the supplied quantity. Use 0 to remove this product from the shipment.', 'error');
			return;
		}
		const qtyChanged = nextQty !== currentQty;
		const weightChanged = Math.abs(nextWeight - currentWeight) > 0.0005;
		if (!qtyChanged && !weightChanged) {
			DsCrmUI.toast('Change this product quantity and/or weight before submitting.', 'error');
			return;
		}

		if (nextQty === 0) {
			const batch = tr.closest('.ds-crm-shipment-history-batch');
			const productRows = batch
				? [...batch.querySelectorAll('tr[data-shipment-item-id]')]
				: [tr];
			const lastProduct = productRows.length <= 1;
			const ok = window.confirm(
				lastProduct
					? 'Set supplied qty to 0? This is the last product on this shipment. After supervisor approval the shipment will be voided and the qty can be shipped again.'
					: 'Set supplied qty to 0? After supervisor approval this product will be removed from this shipment and the qty can be shipped again with another company.'
			);
			if (!ok) {
				return;
			}
		}

		const submitBtn = tr.querySelector('.ds-crm-history-amend-submit');
		if (submitBtn) {
			submitBtn.disabled = true;
		}

		const res = await postAjax('crm_shipments_amend_request', {
			shipment_id: shipmentId,
			reason,
			items: JSON.stringify([
				{
					shipment_item_id: itemId,
					new_quantity: nextQty,
					new_weight_kg: nextWeight,
				},
			]),
		});

		if (submitBtn) {
			submitBtn.disabled = false;
		}

		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Failed to submit request.', 'error');
			return;
		}

		DsCrmUI.toast(res.data?.message || 'Request submitted for supervisor review.');
		amendingItemKey = '';
		DsCrm.clearAjaxGetCache('crm_shipments_');
		if (workspaceOrderId) {
			await loadOrderLines(workspaceOrderId);
		}
	};

	const reviewProductAmend = async (tr, decision) => {
		const amendmentId = parseInt(tr?.dataset.amendmentId, 10) || 0;
		if (!amendmentId) {
			return;
		}
		const isRemove = tr?.querySelector('.ds-crm-badge-amend-pending')?.textContent === 'Pending remove';
		const label = decision === 'approved' ? 'accept' : 'decline';
		const prompt = isRemove
			? `Are you sure you want to ${label} removing this product from the shipment?`
			: `Are you sure you want to ${label} this product quantity/weight change?`;
		if (!window.confirm(prompt)) {
			return;
		}

		const res = await postAjax('crm_shipments_amend_review', {
			amendment_id: amendmentId,
			decision,
			review_notes: (tr.querySelector('.ds-crm-history-amend-review-notes')?.value || '').trim(),
		});

		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Review failed.', 'error');
			return;
		}

		DsCrmUI.toast(res.data?.message || 'Review saved.');
		DsCrm.clearAjaxGetCache('crm_shipments_');
		if (workspaceOrderId) {
			await loadOrderLines(workspaceOrderId);
		}
	};

	const renderSupplyHistory = (shipments = [], remaining = null) => {
		if (!lockOrder) {
			return;
		}

		lastShipments = Array.isArray(shipments) ? shipments : [];
		lastRemaining = remaining || null;

		if (reviewHint && !reviewWorkflowBlocked) {
			reviewHint.hidden = false;
			reviewHint.textContent = lastRemaining?.fully_supplied
				? 'Order approved and fully supplied. Confirmed quantity and prices stay locked.'
				: 'Order approved. Accepted quantity and prices are locked. Continue to Confirm supply to ship now or later.';
		}

		renderSupplyProgress(lastRemaining, lastShipments);
		syncSupplyShipperVisibility();

		if (!historyCard || !historyList) {
			return;
		}

		if (!lastShipments.length) {
			historyCard.hidden = true;
			historyList.innerHTML = '';
			amendingItemKey = '';
			return;
		}

		historyCard.hidden = false;
		historyList.innerHTML = lastShipments
			.map((s) => {
				const when = formatSupplyTime(s.time) || formatDate(s.ship_date);
				const items = s.items || [];
				const totalSupplied = items.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0);
				const totalWeight = sumWeights(items.map((item) => item.weight_kg));
				const shipmentId = parseInt(s.id, 10);

				const bodyRows = items
					.map((item) => {
						const variant = [item.color, item.size].filter(Boolean).join(' / ') || '—';
						const accepted =
							item.accepted_quantity != null && item.accepted_quantity !== ''
								? item.accepted_quantity
								: item.qty_accepted != null
									? item.qty_accepted
									: item.qty_ordered || 0;
						const itemId = item.shipment_item_id || item.id;
						const pending = item.pending_amendment || null;
						const key = itemAmendKey(shipmentId, itemId);
						const isEditing = amendingItemKey === key;
						const warehouseLocked = Boolean(s.warehouse_locked);
						const rowCanAmend =
							!warehouseLocked &&
							(Boolean(item.can_amend) || (canAmend && !pending && s.status !== 'void'));
						const rowCanReview = Boolean(item.can_review) || (canReview && pending);

						let qtyCell = `<td><strong>${item.quantity}</strong></td>`;
						let weightCell = `<td>${formatWeight(item.weight_kg)}</td>`;
						let actionsCell = '<td class="ds-crm-actions">—</td>';
						let reasonRow = '';

						if (isEditing) {
							qtyCell = `<td>
								<input type="number" class="ds-crm-history-amend-qty" min="0" max="${item.quantity}" step="1" value="${item.quantity}" aria-label="New supplied qty" />
								<span class="ds-crm-cell-muted">was ${item.quantity} · 0 removes this product</span>
							</td>`;
							weightCell = `<td>
								<input type="number" class="ds-crm-history-amend-weight" min="0" step="0.001" value="${Number(item.weight_kg) || 0}" aria-label="New weight kg" />
							</td>`;
							actionsCell = `<td class="ds-crm-actions ds-crm-history-product-actions">
								<button type="button" class="button button-small button-primary ds-crm-btn-text ds-crm-history-amend-submit">Submit</button>
								<button type="button" class="button button-small ds-crm-btn-text ds-crm-history-amend-remove">Remove</button>
								<button type="button" class="button button-small ds-crm-btn-text ds-crm-history-cancel-amend">Cancel</button>
							</td>`;
							reasonRow = `<tr class="ds-crm-history-amend-reason-row" data-amend-key="${escapeHtml(key)}">
								<td colspan="8">
									<label class="ds-crm-shipment-amend-reason-label">Reason for changing this product</label>
									<textarea class="ds-crm-history-amend-reason" rows="2" placeholder="e.g. Shipping company cannot take this product — remove it from this shipment."></textarea>
									<p class="description">Set qty to 0 (or tap Remove) to take this product off the shipment. After supervisor approval, freed qty can be shipped again below with another company. If this is the last product, the shipment will be voided.</p>
								</td>
							</tr>`;
						} else if (pending) {
							const pendingRemove = parseInt(pending.new_quantity, 10) === 0;
							qtyCell = `<td>
								<strong>${pendingRemove ? 'Remove' : pending.new_quantity}</strong>
								<span class="ds-crm-cell-muted">was ${pending.old_quantity}</span>
								<span class="ds-crm-badge ds-crm-badge-amend-pending">${pendingRemove ? 'Pending remove' : 'Pending'}</span>
							</td>`;
							weightCell = `<td>
								${formatWeight(pending.new_weight_kg)}
								<span class="ds-crm-cell-muted">was ${formatWeight(pending.old_weight_kg)}</span>
							</td>`;
							if (rowCanReview) {
								actionsCell = `<td class="ds-crm-actions ds-crm-history-product-actions">
									<input type="text" class="ds-crm-history-amend-review-notes" placeholder="Review note (optional)" aria-label="Review notes" />
									<button type="button" class="button button-small button-primary ds-crm-btn-text ds-crm-history-amend-approve">Accept</button>
									<button type="button" class="button button-small ds-crm-btn-text ds-crm-history-amend-decline">Decline</button>
								</td>`;
							} else {
								actionsCell = `<td class="ds-crm-actions">
									<span class="ds-crm-cell-muted">Awaiting supervisor</span>
									${pending.reason ? `<span class="ds-crm-cell-muted" title="${escapeHtml(pending.reason)}">· ${escapeHtml(pending.requested_by_name || 'Requested')}</span>` : ''}
								</td>`;
							}
						} else if (rowCanAmend) {
							actionsCell = `<td class="ds-crm-actions ds-crm-history-product-actions">
								<button type="button" class="button button-small ds-crm-btn-text ds-crm-history-product-change">Change</button>
							</td>`;
						} else if (warehouseLocked) {
							actionsCell = `<td class="ds-crm-actions"><span class="ds-crm-cell-muted">Locked (received in BD)</span></td>`;
						}

						return `<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}${pending ? ' ds-crm-shipment-line--amend' : ''}${isEditing ? ' is-amending-product' : ''}${warehouseLocked ? ' is-warehouse-locked' : ''}"
							data-shipment-id="${shipmentId}"
							data-shipment-item-id="${itemId}"
							data-amendment-id="${pending?.amendment_id || ''}"
							data-current-qty="${item.quantity}"
							data-current-weight="${Number(item.weight_kg) || 0}"
							data-amend-key="${escapeHtml(key)}">
							<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
							<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority) || '—'}</td>
							<td>${escapeHtml(variant)}</td>
							<td>${item.qty_ordered ?? '—'}</td>
							<td><strong>${accepted}</strong></td>
							${qtyCell}
							${weightCell}
							${actionsCell}
						</tr>${reasonRow}`;
					})
					.join('');

				return `
				<article class="ds-crm-shipment-history-batch${s.has_pending_amendment ? ' is-amend-pending' : ''}${s.warehouse_locked ? ' is-warehouse-locked' : ''}" data-shipment-id="${shipmentId}">
					<div class="ds-crm-shipment-history-batch-head">
						<div class="ds-crm-shipment-history-batch-title">
							<strong>${escapeHtml(s.shipment_number || '—')}</strong>
							<span class="ds-crm-badge ds-crm-badge-info">${escapeHtml(String(s.qty_total || 0))} pcs</span>
							${s.has_pending_amendment ? '<span class="ds-crm-badge ds-crm-badge-amend-pending">Has pending product changes</span>' : ''}
							${s.warehouse_locked ? '<span class="ds-crm-badge ds-crm-badge-shipment-received">Received in warehouse</span>' : ''}
						</div>
						<div class="ds-crm-shipment-history-batch-meta">
							<span><strong>Company:</strong> ${escapeHtml(s.company_name || '—')}</span>
							<span><strong>Ship date:</strong> ${escapeHtml(formatDate(s.ship_date))}</span>
							${when ? `<span><strong>Recorded:</strong> ${escapeHtml(when)}</span>` : ''}
							<span><strong>Weight:</strong> ${escapeHtml(formatWeight(s.total_kg, { withUnit: true }))}</span>
						</div>
						${s.notes ? `<p class="ds-crm-shipment-history-notes"><strong>Notes:</strong> ${escapeHtml(s.notes)}</p>` : ''}
					</div>
					<div class="ds-crm-table-wrap ds-crm-line-items-wrap">
						<table class="ds-crm-table ds-crm-shipment-history-lines-table">
							<thead>
								<tr>
									<th>Product</th>
									<th>Priority</th>
									<th>Color / Size</th>
									<th>Ordered</th>
									<th>Accepted</th>
									<th>Supplied</th>
									<th>Weight (kg)</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								${bodyRows || `<tr><td colspan="8" class="ds-crm-empty">No products in this supply.</td></tr>`}
							</tbody>
							${items.length ? `<tfoot>
								<tr class="ds-crm-order-lines-total ds-crm-shipment-history-lines-total">
									<td colspan="5" class="ds-crm-order-total-label">Total</td>
									<td class="ds-crm-order-total-value"><strong>${totalSupplied}</strong></td>
									<td class="ds-crm-order-total-value">${formatWeight(totalWeight)}</td>
									<td></td>
								</tr>
							</tfoot>` : ''}
						</table>
					</div>
				</article>`;
			})
			.join('');
	};

	const resetSupplyFormFields = () => {
		form?.querySelectorAll('.line-ship-qty').forEach((input) => {
			if (!input.disabled) {
				input.value = '0';
			}
		});
		form?.querySelectorAll('.line-weight').forEach((input) => {
			if (!input.disabled) {
				input.value = '0';
			}
		});
		const notes = form?.querySelector('[name="notes"]');
		if (notes) {
			notes.value = '';
		}
		companySelects().forEach((select) => {
			select.value = '';
		});
		updateTotals();
	};

	const loadOrderLines = async (orderId) => {
		if (!orderId) {
			renderOrderPreview(null);
			renderShipmentLines([]);
			return;
		}

		if (linesBody) {
			linesBody.innerHTML = `<tr><td colspan="${LINE_COL_COUNT}" class="ds-crm-loading-row">Loading items…</td></tr>`;
		}

		const res = await postAjax('crm_shipments_order_lines', { order_id: orderId });
		if (!res.success) {
			setFormError(res.data?.message || 'Failed to load order lines.');
			if (!lockOrder) {
				clearOrderSelection();
			}
			renderShipmentLines([]);
			return;
		}

		setFormError('');
		if (!lockOrder) {
			renderOrderPreview(res.data.order);
		} else {
			renderOrderPreview(null);
		}

		if (lockOrder) {
			lockOrderSelection(res.data.order);
		} else if (selectedOrderEl && res.data.order) {
			const order = res.data.order;
			const linesToExport = (res.data.lines || []).filter((l) => l.qty_to_export > 0).length;
			selectedOrderEl.hidden = false;
			selectedOrderEl.innerHTML = `<strong>${escapeHtml(order.order_number)}</strong> — ${escapeHtml(order.client_name || '—')}${order.client_phone ? ` (${escapeHtml(order.client_phone)})` : ''}${linesToExport ? ` · ${linesToExport} line${linesToExport === 1 ? '' : 's'} to export` : ''} <button type="button" class="button-link ds-crm-clear-shipment-order">Change</button>`;
			selectedOrderEl.querySelector('.ds-crm-clear-shipment-order')?.addEventListener('click', clearOrderSelection);
			if (orderSearch) {
				orderSearch.hidden = true;
			}
			if (orderIdInput) {
				orderIdInput.value = order.id;
			}
		}

		renderSupplyHistory(res.data.shipments || [], res.data.remaining || null);
		renderShipmentLines(res.data.lines || []);
	};

	const collectLines = () => {
		const items = [];
		linesBody?.querySelectorAll('tr[data-order-item-id]').forEach((tr) => {
			const qty = parseInt(tr.querySelector('.line-ship-qty')?.value, 10) || 0;
			if (qty < 1) {
				return;
			}
			items.push({
				order_item_id: tr.dataset.orderItemId,
				quantity: qty,
				weight_kg: parseWeight(tr.querySelector('.line-weight')?.value),
			});
		});
		return { items };
	};

	const boot = async () => {
		if (presetOrderId) {
			await loadOrderWorkspace(parseInt(presetOrderId, 10));
			return;
		}

		showExportSection(canRecordExport);
		if (!form) {
			return;
		}

		const today = new Date().toISOString().slice(0, 10);
		const shipDateInput = form.querySelector('[name="ship_date"]');
		if (shipDateInput) {
			shipDateInput.value = today;
		}
		await loadFormData();
	};

	root.querySelector('.ds-crm-shipment-save-prices')?.addEventListener('click', saveReviewPrices);
	root.querySelector('.ds-crm-shipment-accept-order')?.addEventListener('click', () => acceptReviewOrder());
	root.querySelector('.ds-crm-shipment-save-accept-order')?.addEventListener('click', saveAndAcceptReviewOrder);
	confirmSupplyBtn?.addEventListener('click', confirmSupplyStep);
	editSupplyBtn?.addEventListener('click', editSupplyStep);

	historyList?.addEventListener('click', async (e) => {
		const changeBtn = e.target.closest('.ds-crm-history-product-change');
		if (changeBtn) {
			const tr = changeBtn.closest('tr[data-amend-key]');
			amendingItemKey = tr?.dataset.amendKey || '';
			renderSupplyHistory(lastShipments, lastRemaining);
			return;
		}

		const cancelBtn = e.target.closest('.ds-crm-history-cancel-amend');
		if (cancelBtn) {
			amendingItemKey = '';
			renderSupplyHistory(lastShipments, lastRemaining);
			return;
		}

		const submitBtn = e.target.closest('.ds-crm-history-amend-submit');
		if (submitBtn) {
			await submitProductAmend(submitBtn.closest('tr[data-shipment-item-id]'));
			return;
		}

		const removeBtn = e.target.closest('.ds-crm-history-amend-remove');
		if (removeBtn) {
			const tr = removeBtn.closest('tr[data-shipment-item-id]');
			const qtyInput = tr?.querySelector('.ds-crm-history-amend-qty');
			const weightInput = tr?.querySelector('.ds-crm-history-amend-weight');
			if (qtyInput) {
				qtyInput.value = '0';
			}
			if (weightInput) {
				weightInput.value = '0';
			}
			await submitProductAmend(tr);
			return;
		}

		const approveBtn = e.target.closest('.ds-crm-history-amend-approve');
		if (approveBtn) {
			await reviewProductAmend(approveBtn.closest('tr[data-shipment-item-id]'), 'approved');
			return;
		}

		const declineBtn = e.target.closest('.ds-crm-history-amend-decline');
		if (declineBtn) {
			await reviewProductAmend(declineBtn.closest('tr[data-shipment-item-id]'), 'declined');
		}
	});

	historyList?.addEventListener('input', (e) => {
		const qtyInput = e.target.closest('.ds-crm-history-amend-qty');
		if (!qtyInput) {
			return;
		}
		const tr = qtyInput.closest('tr[data-shipment-item-id]');
		const weightInput = tr?.querySelector('.ds-crm-history-amend-weight');
		if (!tr || !weightInput) {
			return;
		}
		const currentQty = parseInt(tr.dataset.currentQty, 10) || 0;
		const currentWeight = parseFloat(tr.dataset.currentWeight) || 0;
		const nextQty = parseInt(qtyInput.value, 10);
		if (Number.isNaN(nextQty) || currentQty < 1) {
			return;
		}
		weightInput.value = String(Math.round((currentWeight * (Math.max(0, nextQty) / currentQty)) * 1000) / 1000);
	});

	orderSearch?.addEventListener(
		'input',
		debounce(async () => {
			const term = orderSearch.value.trim();
			if (term.length < 2) {
				if (term.length === 0) {
					DsCrmUI.resetAutocompleteList(orderSuggestions);
				} else {
					orderSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Type at least 2 characters to search orders…</div>';
					DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				}
				return;
			}

			orderSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Searching…</div>';
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);

			const result = await postAjax('crm_shipments_orders_search', { search: term });
			if (!result.success) {
				orderSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Could not load orders. Try again.</div>';
				DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				return;
			}
			if (result.data?.hint && !result.data.items?.length) {
				orderSuggestions.innerHTML = `<div class="ds-crm-autocomplete-hint">${escapeHtml(result.data.hint)}</div>`;
				DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				return;
			}
			if (!result.data.items?.length) {
				orderSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">No matching orders found.</div>';
				DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
				return;
			}

			const moreHint = result.data?.truncated || result.data?.hint
				? `<div class="ds-crm-autocomplete-hint ds-crm-autocomplete-more">${escapeHtml(result.data.hint || 'Showing the first matches. Keep typing to narrow the list.')}</div>`
				: '';
			orderSuggestions.innerHTML = result.data.items.map((o) => renderOrderSuggestion(o)).join('') + moreHint;
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
		}, 300)
	);

	orderSearch?.addEventListener('focus', () => {
		const term = orderSearch.value.trim();
		if (term.length >= 2) {
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
		} else {
			orderSuggestions.innerHTML = '<div class="ds-crm-autocomplete-hint">Type at least 2 characters to search orders…</div>';
			DsCrmUI.positionAutocompleteList(orderSuggestions, orderSearch);
		}
	});

	orderSuggestions?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-suggestion[data-can-export="1"]');
		if (!btn) {
			return;
		}
		selectOrder({
			id: btn.dataset.id,
			order_number: btn.dataset.orderNumber,
			client_name: btn.dataset.clientName,
			client_phone: btn.dataset.clientPhone,
			lines_to_export: parseInt(btn.dataset.linesToExport, 10) || 0,
			can_export: true,
		});
	});

	companySelect?.addEventListener('change', () => setFormError(''));

	form?.addEventListener('submit', async (e) => {
		e.preventDefault();
		setFormError('');

		const companyId = parseInt(companySelect?.value, 10) || 0;
		const orderId = parseInt(orderIdInput?.value, 10) || 0;
		const shipDate = form.querySelector('[name="ship_date"]')?.value;

		if (!orderId || !shipDate || !companyId) {
			setFormError(
				lockOrder
					? 'Ship date and shipping company are required.'
					: 'Order, ship date, and export company are required.'
			);
			if (lockOrder) {
				supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			return;
		}

		const collected = collectLines();
		const items = collected.items;
		if (!items.length) {
			setFormError(
				lockOrder
					? 'Enter a supply quantity for at least one product.'
					: 'Enter a ship quantity for at least one line.'
			);
			if (lockOrder) {
				supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			return;
		}

		if (lockOrder) {
			const supplyState = getSupplyLineState();
			if (supplyState.missingPrice) {
				setFormError('Accepted unit prices are missing for products you are supplying. Re-approve or set prices first.');
				supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
				return;
			}
		}

		setFormLoading(true);

		const res = await postAjax('crm_shipments_save', {
			company_id: companyId,
			order_id: orderId,
			ship_date: shipDate,
			notes: form.querySelector('[name="notes"]')?.value || '',
			items: JSON.stringify(items),
		});

		setFormLoading(false);

		if (!res.success) {
			setFormError(res.data?.message || 'Save failed.');
			return;
		}

		writeStoredSupplyConfirmed(orderId, false);
		DsCrm.clearAjaxGetCache('crm_shipments_');
		DsCrm.clearAjaxGetCache('crm_orders_');
		DsCrmUI.toast(res.data?.message || 'Shipment confirmed. Goods are on the way.');

		if (lockOrder) {
			const remaining = res.data?.remaining;
			resetSupplyFormFields();
			await loadOrderLines(orderId);
			if (remaining?.fully_supplied) {
				historyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
				supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			} else {
				historyCard?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				supplyCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			return;
		}

		window.location.href = listUrl;
	});

	boot();
})();
