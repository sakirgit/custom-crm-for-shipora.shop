/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="shipments"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, formatAmount, formatWeight, DsCrmUI, buildModuleUrl } =
		DsCrm;
	const viewModal = document.getElementById('ds-crm-shipment-view-modal');
	const readyPanel = root.querySelector('[data-panel="ready"]');
	const historyPanel = root.querySelector('[data-panel="history"]');
	const amendmentsPanel = root.querySelector('[data-panel="amendments"]');
	const readyTbody = root.querySelector('.ds-crm-shipments-table--ready tbody');
	const historyTbody = root.querySelector('.ds-crm-shipments-table--history tbody');
	const amendmentsTbody = root.querySelector('.ds-crm-shipments-table--amendments tbody');
	const readyCountEl = root.querySelector('.ds-crm-shipments-ready-count');
	const amendCountEl = root.querySelector('.ds-crm-shipments-amend-count');
	const tabButtons = root.querySelectorAll('.ds-crm-shipments-tabs [data-tab]');
	const readyFilters = root.querySelector('[data-ready-filters]');
	const statusPills = root.querySelector('.ds-crm-filter-status-pills');
	const trackingFilter = root.querySelector('.ds-crm-filter-tracking');
	const colspan = 8;
	const amendColspan = 10;
	const paginationEl = historyPanel?.querySelector('.ds-crm-pagination');
	const pageInfo = historyPanel?.querySelector('.ds-crm-page-info');
	const prevBtn = historyPanel?.querySelector('.ds-crm-page-prev');
	const nextBtn = historyPanel?.querySelector('.ds-crm-page-next');
	const amendPaginationEl = amendmentsPanel?.querySelector('.ds-crm-amend-pagination');
	const amendPageInfo = amendmentsPanel?.querySelector('.ds-crm-amend-page-info');
	const amendPrevBtn = amendmentsPanel?.querySelector('.ds-crm-amend-page-prev');
	const amendNextBtn = amendmentsPanel?.querySelector('.ds-crm-amend-page-next');
	const viewToolbar = viewModal?.querySelector('.ds-crm-shipment-view-toolbar');
	const viewMeta = viewModal?.querySelector('.ds-crm-shipment-view-meta');
	const viewItemsBody = viewModal?.querySelector('.ds-crm-shipment-view-items tbody');
	const amendBanner = viewModal?.querySelector('.ds-crm-shipment-amend-banner');
	const amendForm = viewModal?.querySelector('.ds-crm-shipment-amend-form');
	const amendReview = viewModal?.querySelector('.ds-crm-shipment-amend-review');
	const amendReason = viewModal?.querySelector('.ds-crm-shipment-amend-reason');
	const amendReviewNotes = viewModal?.querySelector('.ds-crm-shipment-amend-review-notes');
	const amendColHeader = viewModal?.querySelector('.ds-crm-shipment-amend-col');

	const hasReadyPanel = Boolean(readyPanel);
	const supervisorFocus = root.dataset.supervisorFocus === '1';
	const canReviewAmendments = root.dataset.canReview === '1';
	const urlParams = new URLSearchParams(window.location.search);
	const tabParam = urlParams.get('shipments_tab');
	const initialTab = (() => {
		if (tabParam === 'amendments' || tabParam === 'history' || tabParam === 'ready') {
			if (tabParam === 'ready' && !hasReadyPanel) {
				return supervisorFocus ? 'amendments' : 'history';
			}
			return tabParam;
		}
		if (supervisorFocus) {
			return 'amendments';
		}
		if (!hasReadyPanel) {
			return 'history';
		}
		return 'ready';
	})();

	const state = {
		activeTab: initialTab,
		readySearch: '',
		status: '',
		tracking: '',
		historySearch: '',
		page: 1,
		perPage: 10,
		dateFrom: '',
		dateTo: '',
		totalPages: 1,
		amendSearch: '',
		amendStatus: 'pending',
		amendPage: 1,
		amendPerPage: 25,
		amendTotalPages: 1,
		canReviewList: canReviewAmendments,
		viewShipmentId: 0,
		amendMode: false,
		pendingAmendmentId: 0,
		viewItems: [],
	};

	let filtersReady = false;
	let statusMap = {};
	let trackingMap = {};

	DsCrmUI.wireModal(viewModal);

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const orderWorkspaceUrl = (id) =>
		buildModuleUrl('shipments', { shipment_action: 'new', order_id: id });

	const statusLabel = (status) => {
		if (statusMap[status]?.label) {
			return statusMap[status].label;
		}
		const fallback = {
			in_transit: 'In transit',
			partially_received: 'Partially received',
			received: 'Received in BD',
			void: 'Void',
			pending: 'Pending',
			awaiting_acceptance: 'Awaiting acceptance',
			partial_delivered: 'Partial delivered',
			completed: 'Completed',
			cancelled: 'Cancelled',
			approved: 'Approved',
			declined: 'Declined',
		};
		return fallback[status] || status || '—';
	};

	const amendStatusLabel = (status) => {
		const map = {
			pending: 'Pending review',
			approved: 'Approved',
			declined: 'Declined',
		};
		return map[status] || status || '—';
	};

	const resetAmendUi = () => {
		state.amendMode = false;
		state.pendingAmendmentId = 0;
		state.viewItems = [];
		if (amendBanner) {
			amendBanner.hidden = true;
			amendBanner.innerHTML = '';
		}
		if (amendForm) {
			amendForm.hidden = true;
		}
		if (amendReview) {
			amendReview.hidden = true;
		}
		if (amendReason) {
			amendReason.value = '';
		}
		if (amendReviewNotes) {
			amendReviewNotes.value = '';
		}
		if (amendColHeader) {
			amendColHeader.hidden = true;
		}
	};

	const populateReadyFilters = (data = {}) => {
		if (filtersReady) {
			return;
		}

		(data.statuses || []).forEach((s) => {
			statusMap[s.slug] = s;
		});
		(data.tracking_steps || []).forEach((step) => {
			trackingMap[step.slug] = step;
		});

		if (statusPills) {
			const allBtn = statusPills.querySelector('[data-status=""]');
			statusPills.innerHTML = '';
			const all = document.createElement('button');
			all.type = 'button';
			all.className = `ds-crm-filter-pill${state.status ? '' : ' is-active'}`;
			all.dataset.status = '';
			all.setAttribute('aria-pressed', state.status ? 'false' : 'true');
			all.textContent = allBtn?.textContent?.trim() || 'All';
			statusPills.appendChild(all);

			(data.statuses || []).forEach((s) => {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = `ds-crm-filter-pill${state.status === s.slug ? ' is-active' : ''}`;
				btn.dataset.status = s.slug;
				btn.setAttribute('aria-pressed', state.status === s.slug ? 'true' : 'false');
				btn.textContent = s.label;
				statusPills.appendChild(btn);
			});
		}

		if (trackingFilter) {
			const current = state.tracking || trackingFilter.value;
			const allLabel = trackingFilter.options[0]?.textContent || 'All tracking';
			trackingFilter.innerHTML = '';
			const allOpt = document.createElement('option');
			allOpt.value = '';
			allOpt.textContent = allLabel;
			trackingFilter.appendChild(allOpt);
			(data.tracking_steps || []).forEach((step) => {
				const opt = document.createElement('option');
				opt.value = step.slug;
				opt.textContent = step.label;
				trackingFilter.appendChild(opt);
			});
			trackingFilter.value = [...trackingFilter.options].some((opt) => opt.value === current) ? current : '';
			state.tracking = trackingFilter.value;
		}

		filtersReady = true;
	};

	const syncStatusPills = () => {
		if (!statusPills) {
			return;
		}
		statusPills.querySelectorAll('.ds-crm-filter-pill').forEach((btn) => {
			const active = (btn.dataset.status || '') === state.status;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	const renderTrackingCell = (order) => {
		const tracking = order.tracking || {};
		const tone = tracking.tone || 'info';
		return `<td class="ds-crm-order-tracking-cell">
			<span class="ds-crm-order-tracking-label ds-crm-order-tracking--${escapeHtml(tone)}">${escapeHtml(tracking.short_label || statusLabel(order.status))}</span>
			${tracking.short_detail ? `<span class="ds-crm-cell-muted ds-crm-order-tracking-detail">${escapeHtml(tracking.short_detail)}</span>` : ''}
		</td>`;
	};

	const orderActionLabel = (order) => {
		if (order.workflow_blocked) {
			return 'Review order';
		}
		if (order.can_record_export) {
			return 'Open order & export';
		}
		return 'Open order';
	};

	const orderActionIcon = (order) => (order.workflow_blocked ? 'view' : order.can_record_export ? 'export' : 'view');

	const countActionNeeded = (orders) =>
		orders.filter((order) => order.workflow_blocked || order.needs_pricing).length;

	const updateReadyCountBadge = (orders) => {
		const count = countActionNeeded(orders);
		if (!readyCountEl) {
			return;
		}
		if (count > 0) {
			readyCountEl.hidden = false;
			readyCountEl.textContent = String(count);
		} else {
			readyCountEl.hidden = true;
			readyCountEl.textContent = '';
		}
	};

	const updateAmendCountBadge = (count) => {
		if (!amendCountEl) {
			return;
		}
		const n = parseInt(count, 10) || 0;
		if (n > 0) {
			amendCountEl.hidden = false;
			amendCountEl.textContent = String(n);
		} else {
			amendCountEl.hidden = true;
			amendCountEl.textContent = '';
		}
	};

	const setActiveTab = (tab) => {
		const allowed = ['ready', 'history', 'amendments'];
		let next = allowed.includes(tab) ? tab : supervisorFocus ? 'amendments' : 'history';
		if (next === 'ready' && !hasReadyPanel) {
			next = supervisorFocus ? 'amendments' : 'history';
		}
		state.activeTab = next;

		tabButtons.forEach((btn) => {
			const isActive = btn.dataset.tab === state.activeTab;
			btn.classList.toggle('is-active', isActive);
			btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		if (readyPanel) {
			readyPanel.hidden = state.activeTab !== 'ready';
		}
		if (historyPanel) {
			historyPanel.hidden = state.activeTab !== 'history';
		}
		if (amendmentsPanel) {
			amendmentsPanel.hidden = state.activeTab !== 'amendments';
		}
		if (readyFilters) {
			readyFilters.hidden = state.activeTab !== 'ready';
		}

		const summaryEl = root.querySelector('.ds-crm-module-summary');
		if (summaryEl) {
			summaryEl.hidden = state.activeTab !== 'ready' || !summaryEl.querySelector('.ds-crm-module-summary-card');
		}
	};

	const renderReadyRow = (order) => {
		const urgentCount = parseInt(order.urgent_count, 10) || 0;
		const rowClass = `ds-crm-shipments-row--ready${order.workflow_blocked ? ' ds-crm-order-row--blocked' : ''}${urgentCount > 0 ? ' ds-crm-order-row--has-urgent' : ''}`;
		const workspaceUrl = order.shipment_form_url || orderWorkspaceUrl(order.id);
		const actionBtn = DsCrmUI.actionButton(orderActionLabel(order), orderActionIcon(order), {
			tag: 'a',
			attrs: `href="${workspaceUrl}"`,
			iconOnly: true,
		});

		return `
			<tr class="${rowClass}" data-order-id="${order.id}">
				<td class="ds-crm-order-number-cell">
					${DsCrmUI.orderNumberWithLink(order.order_number, workspaceUrl, {
						beaconHtml: urgentCount > 0 ? DsCrmUI.deliveryPriorityBeaconHtml({ variant: 'urgent' }) : '',
						linkTitle: orderActionLabel(order),
					})}
				</td>
				<td class="ds-crm-client-cell">
					<span class="ds-crm-cell-primary">${escapeHtml(order.client_name || '—')}</span>
					${order.client_phone ? `<span class="ds-crm-cell-muted">${escapeHtml(order.client_phone)}</span>` : ''}
				</td>
				<td class="ds-crm-products-cell">${DsCrmUI.renderProductPreview(order.product_preview, order.item_count)}</td>
				<td class="ds-crm-datetime">${formatListDateTime(order, 'order_date')}</td>
				<td class="ds-crm-amount-cell">${order.needs_pricing ? '<span class="ds-crm-cell-muted">Pending</span>' : formatAmount(order.total_amount)}</td>
				${renderTrackingCell(order)}
				<td><span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(order.status_label || statusLabel(order.status))}</span></td>
				<td class="ds-crm-actions">${actionBtn}</td>
			</tr>`;
	};

	const renderShipmentRow = (item) => {
		const orderViewHref = item.order_id
			? buildModuleUrl('orders', { order_action: 'view', order_id: item.order_id })
			: '';
		const pendingBadge = item.has_pending_amendment
			? '<span class="ds-crm-badge ds-crm-badge-amend-pending">Qty change pending</span>'
			: '';
		return `
			<tr class="ds-crm-shipments-row--history${item.has_pending_amendment ? ' ds-crm-shipment-row--amend-pending' : ''}">
				<td class="ds-crm-order-number-cell">
					${DsCrmUI.orderNumberWithLink(item.order_number || '—', orderViewHref, { linkTitle: 'View order' })}
				</td>
				<td class="ds-crm-client-cell">
					<span class="ds-crm-cell-primary">${escapeHtml(item.client_name || '—')}</span>
				</td>
				<td class="ds-crm-products-cell">${DsCrmUI.renderProductPreview(item.product_preview, item.item_count)}</td>
				<td>${escapeHtml(item.company_name || '—')}</td>
				<td class="ds-crm-datetime">${formatListDateTime(item, 'ship_date')}</td>
				<td>
					<span class="ds-crm-cell-primary">${escapeHtml(item.shipment_number)}</span>
					<span class="ds-crm-cell-muted">${item.item_count || 0} items · ${formatWeight(item.total_kg, { withUnit: true })}</span>
					${pendingBadge}
				</td>
				<td><span class="ds-crm-badge ds-crm-badge-shipment-${escapeHtml(item.status)}">${escapeHtml(statusLabel(item.status))}</span></td>
				<td class="ds-crm-actions">${DsCrmUI.actionButton('View shipment', 'view', { className: 'ds-crm-view-shipment', attrs: `data-id="${item.id}"`, iconOnly: true })}</td>
			</tr>`;
	};

	const renderAmendShipmentGroupRow = (item) => {
		const shipDate = item.ship_date ? formatDate(item.ship_date) : '';
		return `
			<tr class="ds-crm-amend-group-row" data-shipment-id="${item.shipment_id || ''}">
				<td colspan="${amendColspan}">
					<span class="ds-crm-amend-group-title">${escapeHtml(item.shipment_number || 'Shipment')}</span>
					${shipDate ? `<span class="ds-crm-cell-muted"> · Ship ${escapeHtml(shipDate)}</span>` : ''}
					${item.client_name ? `<span class="ds-crm-cell-muted"> · ${escapeHtml(item.client_name)}</span>` : ''}
				</td>
			</tr>`;
	};

	const renderAmendmentActions = (item) => {
		const viewBtn = DsCrmUI.actionButton('Open shipment', 'view', {
			className: 'ds-crm-view-shipment',
			attrs: `data-id="${item.shipment_id}"`,
			iconOnly: true,
		});

		if (item.status === 'pending' && state.canReviewList) {
			return `
				<div class="ds-crm-amend-review-actions">
					<button type="button" class="button button-primary button-small ds-crm-btn-text ds-crm-amend-board-approve" data-amendment-id="${item.amendment_id}">Accept</button>
					<button type="button" class="button button-small ds-crm-btn-text ds-crm-amend-board-decline" data-amendment-id="${item.amendment_id}">Decline</button>
					${viewBtn}
				</div>`;
		}

		return viewBtn;
	};

	const renderAmendmentRow = (item) => {
		const colorSize = [item.color, item.size].filter(Boolean).join(' / ') || '—';
		const qtyReleased = parseInt(item.qty_released, 10) || Math.max(0, (parseInt(item.old_quantity, 10) || 0) - (parseInt(item.new_quantity, 10) || 0));
		const weightChanged =
			Math.abs((parseFloat(item.old_weight_kg) || 0) - (parseFloat(item.new_weight_kg) || 0)) > 0.0001;
		const weightHtml = weightChanged
			? `<span class="ds-crm-cell-primary">${formatWeight(item.old_weight_kg)} → ${formatWeight(item.new_weight_kg)}</span>`
			: `<span class="ds-crm-cell-muted">${formatWeight(item.new_weight_kg ?? item.old_weight_kg)}</span>`;

		return `
			<tr class="ds-crm-shipments-row--amendment${item.status === 'pending' ? ' is-pending-review' : ''}" data-amendment-id="${item.amendment_id}" data-shipment-id="${item.shipment_id || ''}">
				<td>
					<span class="ds-crm-cell-primary">${escapeHtml(item.product_name || '—')}</span>
					<span class="ds-crm-cell-muted">${escapeHtml(colorSize)}</span>
				</td>
				<td>
					<span class="ds-crm-cell-primary">${parseInt(item.new_quantity, 10) === 0 ? `Remove (was ${item.old_quantity})` : `${item.old_quantity} → ${item.new_quantity}`}</span>
					${qtyReleased > 0 ? `<span class="ds-crm-cell-muted">−${qtyReleased} pcs freed</span>` : ''}
				</td>
				<td>${weightHtml}</td>
				<td>
					<span class="ds-crm-cell-primary">${escapeHtml(item.shipment_number || '—')}</span>
				</td>
				<td class="ds-crm-order-number-cell">
					${
						item.order_id
							? DsCrmUI.orderNumberWithLink(
									item.order_number || '—',
									buildModuleUrl('orders', { order_action: 'view', order_id: item.order_id }),
									{ linkTitle: 'View order' }
								)
							: escapeHtml(item.order_number || '—')
					}
				</td>
				<td>${escapeHtml(item.company_name || '—')}</td>
				<td>
					<span class="ds-crm-amend-reason-preview" title="${escapeHtml(item.reason || '')}">${escapeHtml(item.reason || '—')}</span>
					${item.requested_by_name ? `<span class="ds-crm-cell-muted">by ${escapeHtml(item.requested_by_name)}</span>` : ''}
				</td>
				<td class="ds-crm-datetime">${formatListDateTime(item.time || item, 'requested_at')}</td>
				<td><span class="ds-crm-badge ds-crm-badge-amend-${escapeHtml(item.status)}">${escapeHtml(amendStatusLabel(item.status))}</span></td>
				<td class="ds-crm-actions ds-crm-amend-board-actions">${renderAmendmentActions(item)}</td>
			</tr>`;
	};

	const renderAmendmentRows = (items) => {
		let lastShipmentId = null;
		const parts = [];
		items.forEach((item) => {
			const shipId = item.shipment_id || 0;
			if (shipId && shipId !== lastShipmentId) {
				parts.push(renderAmendShipmentGroupRow(item));
				lastShipmentId = shipId;
			}
			parts.push(renderAmendmentRow(item));
		});
		return parts.join('');
	};

	const loadReadyOrders = async () => {
		if (!readyTbody) {
			return;
		}

		readyTbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${colspan}">Loading…</td></tr>`;

		const res = await postAjax('crm_shipments_orders_ready', {
			search: state.readySearch,
			status: state.status,
			tracking: state.tracking,
		});

		if (!res.success) {
			readyTbody.innerHTML = `<tr><td colspan="${colspan}">${escapeHtml(res.data?.message || 'Failed to load orders.')}</td></tr>`;
			updateReadyCountBadge([]);
			return;
		}

		populateReadyFilters(res.data);

		const orders = res.data.orders || [];
		updateReadyCountBadge(orders);

		DsCrmUI.renderModuleSummary(root, res.data.summary, {
			onCardClick: (payload) => {
				const nextStatus = typeof payload === 'string' ? payload : payload?.status || '';
				const nextTracking = typeof payload === 'string' ? '' : payload?.tracking || '';

				if (nextTracking) {
					state.status = '';
					state.tracking = nextTracking;
				} else {
					state.status = nextStatus === 'all' ? '' : nextStatus || '';
					state.tracking = '';
				}

				syncStatusPills();
				if (trackingFilter) {
					trackingFilter.value = state.tracking;
				}
				DsCrmUI.syncModuleSummaryFilter(root, state.status, state.tracking);
				loadReadyOrders();
			},
		});
		DsCrmUI.syncModuleSummaryFilter(root, state.status, state.tracking);

		if (!orders.length) {
			const emptyMessage =
				state.readySearch || state.status || state.tracking
					? 'No orders match these filters.'
					: 'No orders need China office attention right now.';
			readyTbody.innerHTML = `<tr><td colspan="${colspan}" class="ds-crm-empty">${escapeHtml(emptyMessage)}</td></tr>`;
			return;
		}

		readyTbody.innerHTML = orders.map(renderReadyRow).join('');
	};

	const loadHistory = async () => {
		if (!historyTbody) {
			return;
		}

		historyTbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${colspan}">Loading…</td></tr>`;

		const res = await postAjax('crm_shipments_list', {
			search: state.historySearch,
			page: state.page,
			per_page: state.perPage,
			date_from: state.dateFrom,
			date_to: state.dateTo,
		});

		if (!res.success) {
			historyTbody.innerHTML = `<tr><td colspan="${colspan}">${escapeHtml(res.data?.message || 'Failed to load shipments.')}</td></tr>`;
			return;
		}

		const shipments = res.data.items || [];
		if (!shipments.length) {
			historyTbody.innerHTML = `<tr><td colspan="${colspan}" class="ds-crm-empty">No export shipments yet.</td></tr>`;
		} else {
			historyTbody.innerHTML = shipments.map(renderShipmentRow).join('');
		}

		state.totalPages = res.data.total_pages || 1;
		const historyTotal = res.data.total || 0;
		if (paginationEl) {
			paginationEl.hidden = state.totalPages <= 1 && !historyTotal;
		}
		if (pageInfo) {
			pageInfo.textContent = `Page ${res.data.page} of ${state.totalPages} (${historyTotal} shipment${historyTotal === 1 ? '' : 's'})`;
		}
		if (prevBtn) {
			prevBtn.disabled = state.page <= 1;
		}
		if (nextBtn) {
			nextBtn.disabled = state.page >= state.totalPages;
		}
	};

	const loadAmendments = async () => {
		if (!amendmentsTbody) {
			return;
		}

		amendmentsTbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${amendColspan}">Loading…</td></tr>`;

		const res = await postAjax('crm_shipments_amendments_list', {
			search: state.amendSearch,
			status: state.amendStatus,
			page: state.amendPage,
			per_page: state.amendPerPage,
		});

		if (!res.success) {
			amendmentsTbody.innerHTML = `<tr><td colspan="${amendColspan}">${escapeHtml(res.data?.message || 'Failed to load change requests.')}</td></tr>`;
			updateAmendCountBadge(0);
			return;
		}

		if (typeof res.data.can_review === 'boolean') {
			state.canReviewList = res.data.can_review;
		}

		updateAmendCountBadge(res.data.pending_total || 0);

		const items = res.data.items || [];
		if (!items.length) {
			const empty =
				state.amendStatus === 'pending'
					? supervisorFocus
						? 'No pending product quantity changes to review.'
						: 'No pending quantity change requests.'
					: 'No quantity change requests match these filters.';
			amendmentsTbody.innerHTML = `<tr><td colspan="${amendColspan}" class="ds-crm-empty">${escapeHtml(empty)}</td></tr>`;
		} else {
			amendmentsTbody.innerHTML = renderAmendmentRows(items);
		}

		state.amendTotalPages = res.data.total_pages || 1;
		const total = res.data.total || 0;
		if (amendPaginationEl) {
			amendPaginationEl.hidden = state.amendTotalPages <= 1 && !total;
		}
		if (amendPageInfo) {
			const pageLabel = total === 1 ? 'product change' : 'product changes';
			amendPageInfo.textContent = `Page ${res.data.page} of ${state.amendTotalPages} (${total} ${pageLabel})`;
		}
		if (amendPrevBtn) {
			amendPrevBtn.disabled = state.amendPage <= 1;
		}
		if (amendNextBtn) {
			amendNextBtn.disabled = state.amendPage >= state.amendTotalPages;
		}
	};

	const loadActiveTab = () => {
		if (state.activeTab === 'history') {
			loadHistory();
		} else if (state.activeTab === 'amendments') {
			loadAmendments();
		} else {
			loadReadyOrders();
		}
	};

	const refreshAll = () => {
		if (hasReadyPanel) {
			loadReadyOrders();
		}
		if (state.activeTab === 'history') {
			loadHistory();
		}
		if (state.activeTab === 'amendments' || amendCountEl) {
			loadAmendments();
		}
	};

	const voidShipment = async (id) => {
		if (!window.confirm('Void this export shipment? Quantities will be available to export again.')) {
			return;
		}
		const res = await postAjax('crm_shipments_void', { id }); 
		if (res.success) {
			DsCrmUI.toast(res.data?.message || 'Shipment voided.');
			DsCrmUI.closeModal(viewModal);
			DsCrm.clearAjaxGetCache('crm_shipments_');
			state.page = 1;
			refreshAll();
		} else {
			DsCrmUI.toast(res.data?.message || 'Void failed.', 'error');
		}
	};

	const renderViewItems = (items, { amendMode = false, pendingAmendment = null } = {}) => {
		const sortedItems = DsCrmUI.sortByDeliveryPriority(items || []);
		const proposedByItem = {};
		(pendingAmendment?.items || []).forEach((line) => {
			proposedByItem[String(line.shipment_item_id)] = line;
		});

		if (amendColHeader) {
			amendColHeader.hidden = !(amendMode || pendingAmendment);
			amendColHeader.textContent = amendMode ? 'New qty' : 'Requested qty';
		}

		if (!sortedItems.length) {
			viewItemsBody.innerHTML = `<tr><td colspan="${amendMode || pendingAmendment ? 6 : 5}" class="ds-crm-empty">No items.</td></tr>`;
			return;
		}

		viewItemsBody.innerHTML = sortedItems
			.map((item) => {
				const proposed = proposedByItem[String(item.id)];
				const qtyCell = amendMode
					? `<td>
						<input type="number" class="ds-crm-shipment-amend-qty" min="0" max="${parseInt(item.quantity, 10) || 0}" step="1" value="${parseInt(item.quantity, 10) || 0}" data-item-id="${item.id}" data-current-qty="${item.quantity}" aria-label="New quantity for ${escapeHtml(item.product_name || 'product')}" />
						<span class="ds-crm-cell-muted">of ${item.quantity} · 0 removes</span>
					</td>`
					: proposed
						? `<td>
							<span class="ds-crm-cell-primary">${parseInt(proposed.new_quantity, 10) === 0 ? 'Remove' : proposed.new_quantity}</span>
							<span class="ds-crm-cell-muted">was ${proposed.old_quantity} (−${proposed.qty_released || proposed.old_quantity - proposed.new_quantity})</span>
						</td>`
						: amendMode || pendingAmendment
							? '<td class="ds-crm-cell-muted">—</td>'
							: '';

				return `
				<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}${proposed ? ' ds-crm-shipment-line--amend' : ''}">
					<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
					<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority)}</td>
					<td>${escapeHtml([item.color, item.size].filter(Boolean).join(' / ') || '—')}</td>
					<td>${item.quantity}</td>
					${qtyCell}
					<td>${formatWeight(item.weight_kg, { withUnit: true })}</td>
				</tr>`;
			})
			.join('');
	};

	const enterAmendMode = () => {
		state.amendMode = true;
		if (amendForm) {
			amendForm.hidden = false;
		}
		if (amendReview) {
			amendReview.hidden = true;
		}
		if (amendBanner) {
			amendBanner.hidden = false;
			amendBanner.innerHTML =
				'<div class="ds-crm-notice ds-crm-notice-info">Reduce quantity on products that need another shipper, or set qty to 0 to remove a product from this shipment. Unchanged lines stay. After approval, freed qty can be exported again. If every product is set to 0, the shipment will be voided.</div>';
		}
		renderViewItems(state.viewItems, { amendMode: true });
	};

	const exitAmendMode = () => {
		state.amendMode = false;
		if (amendForm) {
			amendForm.hidden = true;
		}
		if (amendReason) {
			amendReason.value = '';
		}
		if (amendBanner && !state.pendingAmendmentId) {
			amendBanner.hidden = true;
			amendBanner.innerHTML = '';
		}
		renderViewItems(state.viewItems, { amendMode: false });
	};

	const submitAmendRequest = async () => {
		const reason = (amendReason?.value || '').trim();
		if (!reason) {
			DsCrmUI.toast('Please enter a reason for the quantity change.', 'error');
			return;
		}

		const changes = [];
		viewModal.querySelectorAll('.ds-crm-shipment-amend-qty').forEach((input) => {
			const current = parseInt(input.dataset.currentQty, 10) || 0;
			const next = parseInt(input.value, 10);
			if (Number.isNaN(next) || next < 0 || next >= current) {
				return;
			}
			changes.push({
				shipment_item_id: parseInt(input.dataset.itemId, 10),
				new_quantity: next,
			});
		});

		if (!changes.length) {
			DsCrmUI.toast('Reduce at least one product quantity, or set a product to 0 to remove it from this shipment.', 'error');
			return;
		}

		const removesAll = viewModal.querySelectorAll('.ds-crm-shipment-amend-qty').length > 0
			&& [...viewModal.querySelectorAll('.ds-crm-shipment-amend-qty')].every((input) => {
				const next = parseInt(input.value, 10);
				return !Number.isNaN(next) && next === 0;
			});
		const removesAny = changes.some((change) => change.new_quantity === 0);
		if (removesAll) {
			if (!window.confirm('Every product would be set to 0. After supervisor approval this shipment will be voided and the qty can be exported again.')) {
				return;
			}
		} else if (removesAny && !window.confirm('One or more products will be removed from this shipment after supervisor approval. Continue?')) {
			return;
		}

		const submitBtn = viewModal.querySelector('.ds-crm-shipment-amend-submit');
		if (submitBtn) {
			submitBtn.disabled = true;
		}

		const res = await postAjax('crm_shipments_amend_request', {
			shipment_id: state.viewShipmentId,
			reason,
			items: JSON.stringify(changes),
		});

		if (submitBtn) {
			submitBtn.disabled = false;
		}

		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Failed to submit request.', 'error');
			return;
		}

		DsCrmUI.toast(res.data?.message || 'Request submitted.');
		DsCrm.clearAjaxGetCache('crm_shipments_');
		await openView(state.viewShipmentId);
		refreshAll();
	};

	const reviewAmendment = async (decision) => {
		if (!state.pendingAmendmentId) {
			return;
		}

		const label = decision === 'approved' ? 'accept' : 'decline';
		if (!window.confirm(`Are you sure you want to ${label} this quantity change request?`)) {
			return;
		}

		const res = await postAjax('crm_shipments_amend_review', {
			amendment_id: state.pendingAmendmentId,
			decision,
			review_notes: (amendReviewNotes?.value || '').trim(),
		});

		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Review failed.', 'error');
			return;
		}

		DsCrmUI.toast(res.data?.message || 'Review saved.');
		DsCrm.clearAjaxGetCache('crm_shipments_');
		await openView(state.viewShipmentId);
		refreshAll();
	};

	const reviewAmendmentFromBoard = async (amendmentId, decision, trigger = null) => {
		const id = parseInt(amendmentId, 10) || 0;
		if (!id) {
			return;
		}

		const label = decision === 'approved' ? 'accept' : 'decline';
		if (!window.confirm(`Are you sure you want to ${label} this product quantity change?`)) {
			return;
		}

		const buttons = amendmentsPanel?.querySelectorAll(
			`.ds-crm-amend-board-approve[data-amendment-id="${id}"], .ds-crm-amend-board-decline[data-amendment-id="${id}"]`
		);
		buttons?.forEach((btn) => {
			btn.disabled = true;
		});
		if (trigger) {
			trigger.disabled = true;
		}

		const res = await postAjax('crm_shipments_amend_review', {
			amendment_id: id,
			decision,
			review_notes: '',
		});

		if (!res.success) {
			buttons?.forEach((btn) => {
				btn.disabled = false;
			});
			DsCrmUI.toast(res.data?.message || 'Review failed.', 'error');
			return;
		}

		DsCrmUI.toast(res.data?.message || (decision === 'approved' ? 'Change accepted.' : 'Change declined.'));
		DsCrm.clearAjaxGetCache('crm_shipments_');
		refreshAll();
	};

	const openView = async (id, trigger = null) => {
		if (viewToolbar) {
			viewToolbar.innerHTML = '';
		}
		resetAmendUi();
		state.viewShipmentId = parseInt(id, 10) || 0;

		await DsCrmUI.runModalAction({
			modal: viewModal,
			trigger,
			label: 'Loading shipment…',
			task: async () => {
				const res = await postAjax('crm_shipments_get', { id });
				if (!res.success) {
					DsCrmUI.toast(res.data?.message || 'Failed to load shipment.', 'error');
					return false;
				}

				const {
					shipment,
					items,
					created_by_name,
					can_void,
					can_change_company,
					can_amend,
					can_review,
					pending_amendment,
					companies,
				} = res.data;

				state.viewItems = items || [];
				state.pendingAmendmentId = pending_amendment?.id || 0;

				viewModal.querySelector('#ds-crm-shipment-view-title').textContent = `Export ${shipment.shipment_number}`;

				if (viewToolbar) {
					const actions = [];
					if (can_amend) {
						actions.push(
							`<button type="button" class="button button-small ds-crm-btn-request-amend" data-id="${shipment.id}">Request qty change</button>`
						);
					}
					if (can_change_company) {
						actions.push(
							`<button type="button" class="button button-small ds-crm-btn-change-shipper" data-id="${shipment.id}">Change shipper</button>`
						);
					}
					if (can_void) {
						actions.push(
							`<button type="button" class="button button-small ds-crm-btn-void ds-crm-btn-void-shipment" data-id="${shipment.id}">Void shipment</button>`
						);
					}
					viewToolbar.innerHTML = actions.join(' ');
				}

				if (pending_amendment && amendBanner) {
					amendBanner.hidden = false;
					const lines = (pending_amendment.items || [])
						.map(
							(line) =>
								`<li><strong>${escapeHtml(line.product_name)}</strong>: ${parseInt(line.new_quantity, 10) === 0 ? `${line.old_quantity} → remove` : `${line.old_quantity} → ${line.new_quantity}`} (−${line.qty_released || line.old_quantity - line.new_quantity})</li>`
						)
						.join('');
					amendBanner.innerHTML = `
						<div class="ds-crm-notice ds-crm-notice-warning">
							<strong>Pending quantity change</strong> requested by ${escapeHtml(pending_amendment.requested_by_name || '—')}
							${pending_amendment.reason ? `<p>${escapeHtml(pending_amendment.reason)}</p>` : ''}
							<ul class="ds-crm-shipment-amend-summary">${lines}</ul>
						</div>`;
				}

				if (can_review && pending_amendment && amendReview) {
					amendReview.hidden = false;
				}

				const companyOptions = (companies || [])
					.map(
						(c) =>
							`<option value="${c.id}"${String(c.id) === String(shipment.company_id) ? ' selected' : ''}>${escapeHtml(c.name)}</option>`
					)
					.join('');

				const companyBlock = can_change_company
					? `<div class="ds-crm-meta-item ds-crm-shipment-change-company-wrap" data-shipment-id="${shipment.id}">
							<span class="ds-crm-meta-label">Shipping company</span>
							<span class="ds-crm-meta-value ds-crm-shipment-company-display">${escapeHtml(shipment.company_name || '—')}</span>
							<div class="ds-crm-shipment-change-company-editor" hidden>
								<select class="ds-crm-shipment-change-company-select" aria-label="Shipping company">
									<option value="">— Select company —</option>
									${companyOptions}
								</select>
								<div class="ds-crm-shipment-change-company-actions">
									<button type="button" class="button button-small button-primary ds-crm-shipment-save-company">Save shipper</button>
									<button type="button" class="button button-small ds-crm-shipment-cancel-company">Cancel</button>
								</div>
							</div>
						</div>`
					: `<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Shipping company</span>
							<span class="ds-crm-meta-value">${escapeHtml(shipment.company_name || '—')}</span>
						</div>`;

				viewMeta.innerHTML = `
					<div class="ds-crm-order-meta-grid ds-crm-shipment-view-meta-grid">
						${companyBlock}
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Order</span>
							<span class="ds-crm-meta-value">${
								shipment.order_id
									? DsCrmUI.orderNumberWithLink(
											shipment.order_number || '—',
											buildModuleUrl('orders', { order_action: 'view', order_id: shipment.order_id }),
											{ linkTitle: 'View order' }
										)
									: escapeHtml(shipment.order_number || '—')
							}</span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Ship date</span>
							<span class="ds-crm-meta-value">${formatDate(shipment.ship_date)}</span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Status</span>
							<span class="ds-crm-meta-value"><span class="ds-crm-badge ds-crm-badge-shipment-${escapeHtml(shipment.status)}">${escapeHtml(statusLabel(shipment.status))}</span></span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Client</span>
							<span class="ds-crm-meta-value">${escapeHtml(shipment.client_name || '—')}</span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Total KG</span>
							<span class="ds-crm-meta-value">${formatWeight(shipment.total_kg, { withUnit: true })}</span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Recorded by</span>
							<span class="ds-crm-meta-value">${escapeHtml(created_by_name || '—')}</span>
						</div>
					</div>
					${shipment.notes ? `<div class="ds-crm-order-notes"><span class="ds-crm-meta-label">Notes</span><p>${escapeHtml(shipment.notes)}</p></div>` : ''}
				`;

				renderViewItems(state.viewItems, {
					amendMode: false,
					pendingAmendment: pending_amendment || null,
				});

				return true;
			},
		});
	};

	root.querySelector('.ds-crm-shipments-tabs')?.addEventListener('click', (e) => {
		const tabBtn = e.target.closest('[data-tab]');
		if (!tabBtn || tabBtn.classList.contains('is-active')) {
			return;
		}
		setActiveTab(tabBtn.dataset.tab);
		loadActiveTab();
	});

	historyPanel?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-view-shipment');
		if (btn) {
			openView(btn.dataset.id, btn);
		}
	});

	amendmentsPanel?.addEventListener('click', (e) => {
		const approveBtn = e.target.closest('.ds-crm-amend-board-approve');
		if (approveBtn) {
			reviewAmendmentFromBoard(approveBtn.dataset.amendmentId, 'approved', approveBtn);
			return;
		}

		const declineBtn = e.target.closest('.ds-crm-amend-board-decline');
		if (declineBtn) {
			reviewAmendmentFromBoard(declineBtn.dataset.amendmentId, 'declined', declineBtn);
			return;
		}

		const btn = e.target.closest('.ds-crm-view-shipment');
		if (btn) {
			openView(btn.dataset.id, btn);
		}
	});

	const updateShipmentCompany = async (id, companyId) => {
		const res = await postAjax('crm_shipments_update_company', {
			id,
			company_id: companyId,
		});
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Failed to update shipping company.', 'error');
			return null;
		}
		DsCrmUI.toast(res.data?.message || 'Shipping company updated.');
		DsCrm.clearAjaxGetCache('crm_shipments_');
		return res.data;
	};

	viewModal?.addEventListener('click', async (e) => {
		const voidBtn = e.target.closest('.ds-crm-btn-void-shipment');
		if (voidBtn) {
			voidShipment(parseInt(voidBtn.dataset.id, 10));
			return;
		}

		const amendBtn = e.target.closest('.ds-crm-btn-request-amend');
		if (amendBtn) {
			enterAmendMode();
			return;
		}

		const amendCancel = e.target.closest('.ds-crm-shipment-amend-cancel');
		if (amendCancel) {
			exitAmendMode();
			return;
		}

		const amendSubmit = e.target.closest('.ds-crm-shipment-amend-submit');
		if (amendSubmit) {
			submitAmendRequest();
			return;
		}

		const approveBtn = e.target.closest('.ds-crm-shipment-amend-approve');
		if (approveBtn) {
			reviewAmendment('approved');
			return;
		}

		const declineBtn = e.target.closest('.ds-crm-shipment-amend-decline');
		if (declineBtn) {
			reviewAmendment('declined');
			return;
		}

		const changeBtn = e.target.closest('.ds-crm-btn-change-shipper');
		if (changeBtn) {
			const wrap = viewMeta?.querySelector('.ds-crm-shipment-change-company-wrap');
			const editor = wrap?.querySelector('.ds-crm-shipment-change-company-editor');
			const display = wrap?.querySelector('.ds-crm-shipment-company-display');
			if (editor) {
				editor.hidden = false;
			}
			if (display) {
				display.hidden = true;
			}
			return;
		}

		const cancelBtn = e.target.closest('.ds-crm-shipment-cancel-company');
		if (cancelBtn) {
			const wrap = cancelBtn.closest('.ds-crm-shipment-change-company-wrap');
			const editor = wrap?.querySelector('.ds-crm-shipment-change-company-editor');
			const display = wrap?.querySelector('.ds-crm-shipment-company-display');
			if (editor) {
				editor.hidden = true;
			}
			if (display) {
				display.hidden = false;
			}
			return;
		}

		const saveBtn = e.target.closest('.ds-crm-shipment-save-company');
		if (saveBtn) {
			const wrap = saveBtn.closest('.ds-crm-shipment-change-company-wrap');
			const select = wrap?.querySelector('.ds-crm-shipment-change-company-select');
			const companyId = parseInt(select?.value, 10) || 0;
			const shipmentId = parseInt(wrap?.dataset.shipmentId, 10) || 0;
			if (!companyId || !shipmentId) {
				DsCrmUI.toast('Select a shipping company.', 'error');
				return;
			}
			saveBtn.disabled = true;
			const data = await updateShipmentCompany(shipmentId, companyId);
			saveBtn.disabled = false;
			if (!data) {
				return;
			}
			const display = wrap.querySelector('.ds-crm-shipment-company-display');
			const editor = wrap.querySelector('.ds-crm-shipment-change-company-editor');
			if (display) {
				display.textContent = data.company_name || '—';
				display.hidden = false;
			}
			if (editor) {
				editor.hidden = true;
			}
			refreshAll();
		}
	});

	root.querySelector('.ds-crm-shipments-search-ready')?.addEventListener(
		'input',
		debounce((ev) => {
			state.readySearch = ev.target.value.trim();
			loadReadyOrders();
		})
	);

	statusPills?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-filter-pill');
		if (!btn || !statusPills.contains(btn)) {
			return;
		}
		state.status = btn.dataset.status || '';
		syncStatusPills();
		DsCrmUI.syncModuleSummaryFilter(root, state.status, state.tracking);
		loadReadyOrders();
	});

	trackingFilter?.addEventListener('change', (e) => {
		state.tracking = e.target.value;
		DsCrmUI.syncModuleSummaryFilter(root, state.status, state.tracking);
		loadReadyOrders();
	});

	root.querySelector('.ds-crm-shipments-search-history')?.addEventListener(
		'input',
		debounce((ev) => {
			state.historySearch = ev.target.value.trim();
			state.page = 1;
			loadHistory();
		})
	);

	historyPanel?.querySelector('.ds-crm-date-from')?.addEventListener('change', (ev) => {
		state.dateFrom = ev.target.value;
		state.page = 1;
		loadHistory();
	});

	historyPanel?.querySelector('.ds-crm-date-to')?.addEventListener('change', (ev) => {
		state.dateTo = ev.target.value;
		state.page = 1;
		loadHistory();
	});

	historyPanel?.querySelector('.ds-crm-per-page')?.addEventListener('change', (ev) => {
		state.perPage = parseInt(ev.target.value, 10) || 10;
		state.page = 1;
		loadHistory();
	});

	prevBtn?.addEventListener('click', () => {
		if (state.page > 1) {
			state.page -= 1;
			loadHistory();
		}
	});

	nextBtn?.addEventListener('click', () => {
		if (state.page < state.totalPages) {
			state.page += 1;
			loadHistory();
		}
	});

	root.querySelector('.ds-crm-shipments-search-amendments')?.addEventListener(
		'input',
		debounce((ev) => {
			state.amendSearch = ev.target.value.trim();
			state.amendPage = 1;
			loadAmendments();
		})
	);

	amendmentsPanel?.querySelector('.ds-crm-amend-status-filter')?.addEventListener('change', (ev) => {
		state.amendStatus = ev.target.value || 'pending';
		state.amendPage = 1;
		loadAmendments();
	});

	amendmentsPanel?.querySelector('.ds-crm-amend-per-page')?.addEventListener('change', (ev) => {
		state.amendPerPage = parseInt(ev.target.value, 10) || 10;
		state.amendPage = 1;
		loadAmendments();
	});

	amendPrevBtn?.addEventListener('click', () => {
		if (state.amendPage > 1) {
			state.amendPage -= 1;
			loadAmendments();
		}
	});

	amendNextBtn?.addEventListener('click', () => {
		if (state.amendPage < state.amendTotalPages) {
			state.amendPage += 1;
			loadAmendments();
		}
	});

	setActiveTab(state.activeTab);
	if (hasReadyPanel) {
		loadReadyOrders();
	}
	loadAmendments();
	if (state.activeTab === 'history') {
		loadHistory();
	} else if (state.activeTab === 'amendments') {
		loadAmendments();
	}
})();
