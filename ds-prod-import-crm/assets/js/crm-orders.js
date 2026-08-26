/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="orders"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatListDateTime, formatAmount, DsCrmUI, buildModuleUrl } = DsCrm;
	const tbody = root.querySelector('.ds-crm-orders-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');
	const filterHint = root.querySelector('.ds-crm-list-filter-hint');

	const state = {
		page: 1,
		perPage: 10,
		sortBy: 'order_date',
		sortDir: 'DESC',
		search: '',
		tableQuery: '',
		status: '',
		tracking: '',
		clientId: '',
		dateFrom: '',
		dateTo: '',
		totalPages: 1,
	};

	let cachedItems = [];
	let listMeta = { total: 0, page: 1, totalPages: 1 };
	let statusMap = {};
	let trackingMap = {};

	const urlParams = new URLSearchParams(window.location.search);
	const presetClientId = urlParams.get('client_id') || '';
	const colCount = 8;

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const orderFormUrl = (id = 0) =>
		buildModuleUrl('orders', id ? { order_action: 'edit', order_id: id } : { order_action: 'new' });
	const orderViewUrl = (id) => buildModuleUrl('orders', { order_action: 'view', order_id: id });

	const statusLabel = (slug) => statusMap[slug]?.label || slug;
	const trackingLabel = (slug) => trackingMap[slug]?.label || slug;

	const renderTrackingCell = (item) => {
		const tracking = item.tracking || {};
		const tone = tracking.tone || 'info';
		return `<td class="ds-crm-order-tracking-cell">
			<span class="ds-crm-order-tracking-label ds-crm-order-tracking--${escapeHtml(tone)}">${escapeHtml(tracking.short_label || statusLabel(item.status))}</span>
			${tracking.short_detail ? `<span class="ds-crm-cell-muted ds-crm-order-tracking-detail">${escapeHtml(tracking.short_detail)}</span>` : ''}
		</td>`;
	};

	const loadFilters = async () => {
		const res = await postAjax('crm_orders_list_filters');
		if (!res.success) {
			return;
		}

		(res.data.statuses || []).forEach((s) => {
			statusMap[s.slug] = s;
		});

		const filter = root.querySelector('.ds-crm-filter-status');
		if (filter) {
			const current = filter.value;
			const allLabel = filter.options[0]?.textContent || 'All statuses';
			filter.innerHTML = '';
			const allOpt = document.createElement('option');
			allOpt.value = '';
			allOpt.textContent = allLabel;
			filter.appendChild(allOpt);
			(res.data.statuses || []).forEach((s) => {
				const opt = document.createElement('option');
				opt.value = s.slug;
				opt.textContent = s.label;
				filter.appendChild(opt);
			});
			filter.value = [...filter.options].some((opt) => opt.value === current) ? current : '';
		}

		(res.data.tracking_steps || []).forEach((step) => {
			trackingMap[step.slug] = step;
		});

		const trackingFilter = root.querySelector('.ds-crm-filter-tracking');
		if (trackingFilter) {
			const currentTracking = trackingFilter.value;
			const allTrackingLabel = trackingFilter.options[0]?.textContent || 'All tracking';
			trackingFilter.innerHTML = '';
			const allTrackingOpt = document.createElement('option');
			allTrackingOpt.value = '';
			allTrackingOpt.textContent = allTrackingLabel;
			trackingFilter.appendChild(allTrackingOpt);
			(res.data.tracking_steps || []).forEach((step) => {
				const opt = document.createElement('option');
				opt.value = step.slug;
				opt.textContent = step.label;
				trackingFilter.appendChild(opt);
			});
			trackingFilter.value = [...trackingFilter.options].some((opt) => opt.value === currentTracking)
				? currentTracking
				: '';
		}

		const clientFilter = root.querySelector('.ds-crm-filter-client');
		if (clientFilter) {
			const currentClient = state.clientId || clientFilter.value;
			clientFilter.innerHTML = '<option value="">All clients</option>';
			(res.data.clients || []).forEach((client) => {
				const opt = document.createElement('option');
				opt.value = String(client.id);
				opt.textContent = client.phone ? `${client.name} (${client.phone})` : client.name;
				clientFilter.appendChild(opt);
			});
			const nextClient = presetClientId || currentClient;
			clientFilter.value = [...clientFilter.options].some((opt) => opt.value === nextClient) ? nextClient : '';
			state.clientId = clientFilter.value;
		}
	};

	const printOrderById = async (id, trigger = null) => {
		if (trigger) {
			trigger.disabled = true;
		}
		const result = await postAjax('crm_orders_get', { id });
		if (trigger) {
			trigger.disabled = false;
		}
		if (!result.success) {
			DsCrmUI.toast(result.data?.message || 'Failed to load order for print.', 'error');
			return;
		}
		const data = result.data;
		DsCrmUI.printOrderDocument({
			order: data.order,
			items: data.items || [],
			summary: data.summary || {},
			payments: data.payments || [],
			deliveries: data.deliveries || [],
			creator: data.creator || null,
			branding: DsCrmApp?.branding || {},
			statusLabel: statusLabel(data.order.status),
		});
	};

	const renderProductPreview = (preview, itemCount) =>
		DsCrmUI.renderProductPreview(preview, itemCount, { showDelivery: true });

	const orderItemSearchText = (item) => {
		const products = (item.product_preview || [])
			.map((p) => [p.name, p.sku].filter(Boolean).join(' '))
			.join(' ');
		const tracking = item.tracking || {};
		return [
			item.order_number,
			item.client_name,
			item.client_phone,
			item.status,
			statusLabel(item.status),
			tracking.short_label,
			tracking.short_detail,
			products,
			String(item.total_amount ?? ''),
		]
			.filter(Boolean)
			.join(' ');
	};

	const updateFilterHint = () => {
		if (!filterHint) {
			return;
		}

		const parts = [];

		if (state.clientId) {
			const clientFilter = root.querySelector('.ds-crm-filter-client');
			const clientLabel = clientFilter?.selectedOptions?.[0]?.textContent?.trim() || 'Selected client';
			parts.push(`Database: ${clientLabel}`);
		}
		if (state.status) {
			parts.push(`Status: ${statusLabel(state.status)}`);
		}
		if (state.tracking) {
			parts.push(`Tracking: ${trackingLabel(state.tracking)}`);
		}
		if (state.dateFrom || state.dateTo) {
			const range = [state.dateFrom, state.dateTo].filter(Boolean).join(' → ');
			parts.push(`Database: ${range}`);
		}
		if (state.search) {
			parts.push(`Database: “${state.search}”`);
		}
		if (state.tableQuery) {
			parts.push(`This page: “${state.tableQuery}”`);
		}

		if (!parts.length) {
			filterHint.hidden = true;
			filterHint.textContent = '';
			return;
		}

		filterHint.hidden = false;
		filterHint.textContent = parts.join(' · ');
	};

	const updatePaginationInfo = (shownOnPage) => {
		const { total, page, totalPages } = listMeta;
		let text = `Page ${page} of ${totalPages} (${total} in database)`;
		if (shownOnPage < cachedItems.length) {
			text += ` · ${shownOnPage} shown on this page`;
		}
		pageInfo.textContent = text;
		paginationEl.hidden = totalPages <= 1 && !total;
		prevBtn.disabled = page <= 1;
		nextBtn.disabled = page >= totalPages;
	};

	const refreshTableView = () => {
		const displayItems = DsCrmUI.filterListItems(cachedItems, {
			clientId: state.clientId,
			query: state.tableQuery,
			getSearchText: orderItemSearchText,
		});

		if (!displayItems.length) {
			const emptyMsg = cachedItems.length
				? 'No matches on this page. Adjust filters or wait for the database search.'
				: DsCrmApp.i18n?.noRecords || 'No orders found.';
			tbody.innerHTML = `<tr><td colspan="${colCount}" class="ds-crm-empty">${escapeHtml(emptyMsg)}</td></tr>`;
		} else {
			renderRows(displayItems);
		}

		updatePaginationInfo(displayItems.length);
		updateFilterHint();
	};

	const renderRows = (items) => {
		if (!items.length) {
			return;
		}

		tbody.innerHTML = items
			.map(
				(item) => `
			<tr data-order-id="${item.id}" data-client-id="${item.client_id || ''}" class="${item.workflow_blocked ? 'ds-crm-order-row--blocked' : ''}${parseInt(item.urgent_count, 10) > 0 ? ' ds-crm-order-row--has-urgent' : ''}">
				<td class="ds-crm-order-number-cell">
					${DsCrmUI.orderNumberWithLink(item.order_number, orderViewUrl(item.id), {
						beaconHtml: parseInt(item.urgent_count, 10) > 0 ? DsCrmUI.deliveryPriorityBeaconHtml({ variant: 'urgent' }) : '',
						linkTitle: 'View order',
					})}
				</td>
				<td class="ds-crm-datetime">${formatListDateTime(item, 'order_date')}</td>
				<td class="ds-crm-client-cell">
					<span class="ds-crm-cell-primary">${escapeHtml(item.client_name || '—')}</span>
					${item.client_phone ? `<span class="ds-crm-cell-muted">${escapeHtml(item.client_phone)}</span>` : ''}
				</td>
				<td class="ds-crm-products-cell">${renderProductPreview(item.product_preview, item.item_count)}</td>
				<td class="ds-crm-amount-cell">${item.needs_pricing ? '<span class="ds-crm-cell-muted">Pending</span>' : formatAmount(item.total_amount)}</td>
				<td><span class="ds-crm-badge ds-crm-badge-${escapeHtml(item.status)}">${escapeHtml(statusLabel(item.status))}</span></td>
				${renderTrackingCell(item)}
				<td class="ds-crm-actions">
					${DsCrmUI.wrapActions(
						DsCrmUI.actionButton('View', 'view', { tag: 'a', attrs: `href="${orderViewUrl(item.id)}"`, iconOnly: true }),
						DsCrmUI.actionButton('Print', 'print', { className: 'ds-crm-print-order-row', attrs: `data-id="${item.id}"`, iconOnly: true }),
						item.can_edit ? DsCrmUI.actionButton(item.can_edit_own_only ? 'Edit your order' : 'Edit order', 'edit', { tag: 'a', attrs: `href="${orderFormUrl(item.id)}"`, iconOnly: true }) : ''
					)}
				</td>
			</tr>`
			)
			.join('');
	};

	const loadList = async () => {
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${colCount}">Loading…</td></tr>`;

		const result = await postAjax('crm_orders_list', {
			page: state.page,
			per_page: state.perPage,
			sort_by: state.sortBy,
			sort_dir: state.sortDir,
			search: state.search,
			status: state.status,
			tracking: state.tracking,
			client_id: state.clientId,
			date_from: state.dateFrom,
			date_to: state.dateTo,
		});

		if (!result.success) {
			tbody.innerHTML = `<tr><td colspan="${colCount}">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		cachedItems = result.data.items || [];
		listMeta = {
			total: result.data.total || 0,
			page: result.data.page || 1,
			totalPages: result.data.total_pages || 1,
		};
		state.totalPages = listMeta.totalPages;

		refreshTableView();
		DsCrmUI.renderModuleSummary(root, result.data.summary, {
			onCardClick: (payload) => {
				const status = typeof payload === 'string' ? payload : payload?.status || '';
				const filter = root.querySelector('.ds-crm-filter-status');
				state.status = status === 'all' ? '' : status || '';
				state.page = 1;
				if (filter) filter.value = state.status;
				loadList();
			},
		});
		DsCrmUI.syncModuleSummaryFilter(root, state.status);
	};

	tbody?.addEventListener('click', (e) => {
		const printRowBtn = e.target.closest('.ds-crm-print-order-row');
		if (printRowBtn) {
			printOrderById(parseInt(printRowBtn.dataset.id, 10), printRowBtn);
		}
	});

	root.querySelector('.ds-crm-search')?.addEventListener('input', (e) => {
		state.tableQuery = e.target.value.trim();
		refreshTableView();
	});

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

	root.querySelector('.ds-crm-filter-tracking')?.addEventListener('change', (e) => {
		state.tracking = e.target.value;
		state.page = 1;
		loadList();
	});

	root.querySelector('.ds-crm-filter-client')?.addEventListener('change', (e) => {
		state.clientId = e.target.value;
		state.page = 1;
		refreshTableView();
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
		state.perPage = parseInt(e.target.value, 10) || 10;
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

	root.querySelectorAll('th[data-sort]').forEach((th) => {
		th.addEventListener('click', () => {
			const col = th.dataset.sort;
			if (state.sortBy === col) {
				state.sortDir = state.sortDir === 'ASC' ? 'DESC' : 'ASC';
			} else {
				state.sortBy = col;
				state.sortDir = 'DESC';
			}
			loadList();
		});
	});

	const savedToast = sessionStorage.getItem('ds_crm_toast');
	if (savedToast) {
		sessionStorage.removeItem('ds_crm_toast');
		DsCrmUI.toast(savedToast);
	}

	DsCrmUI.wireDismissibleNotice(root.querySelector('[data-notice-key="orders-help"]'));

	loadFilters().then(loadList);
})();
