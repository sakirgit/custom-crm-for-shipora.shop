/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="delivery"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, formatAmount, formatWeight, DsCrmUI, buildModuleUrl } = DsCrm;
	const viewModal = document.getElementById('ds-crm-delivery-view-modal');
	const tbody = root.querySelector('.ds-crm-deliveries-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');
	const viewActions = viewModal?.querySelector('.ds-crm-delivery-view-actions');
	const viewMeta = viewModal?.querySelector('.ds-crm-delivery-view-meta');
	const viewItemsBody = viewModal?.querySelector('.ds-crm-delivery-view-items tbody');
	const periodPills = root.querySelector('.ds-crm-delivery-period-pills');
	const orderFilter = root.querySelector('.ds-crm-filter-order');
	const dateFromEl = root.querySelector('.ds-crm-date-from');
	const dateToEl = root.querySelector('.ds-crm-date-to');
	const filterHint = root.querySelector('.ds-crm-list-filter-hint');
	const isClient = root.dataset.isClient === '1';
	const colCount = isClient ? 8 : 9;

	const state = {
		page: 1,
		perPage: 10,
		search: '',
		dateFrom: '',
		dateTo: '',
		period: '',
		orderId: '',
		totalPages: 1,
	};

	DsCrmUI.wireModal(viewModal);

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const orderViewUrl = (id) => buildModuleUrl('orders', { order_action: 'view', order_id: id });

	const syncPeriodPills = () => {
		periodPills?.querySelectorAll('.ds-crm-filter-pill').forEach((btn) => {
			const period = btn.dataset.period || '';
			const active = period === state.period;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	const updateFilterHint = () => {
		if (!filterHint) {
			return;
		}
		const parts = [];
		if (state.period === 'today') {
			parts.push('Showing today’s deliveries');
		} else if (state.period === 'week') {
			parts.push('Showing this week’s deliveries');
		} else if (state.period === 'month') {
			parts.push('Showing this month’s deliveries');
		}
		if (state.orderId && orderFilter?.selectedOptions?.[0]?.text) {
			parts.push(`Order: ${orderFilter.selectedOptions[0].text}`);
		}
		if (state.search) {
			parts.push(`Search: “${state.search}”`);
		}
		if (!state.period && (state.dateFrom || state.dateTo)) {
			parts.push(`Dates: ${state.dateFrom || '…'} → ${state.dateTo || '…'}`);
		}
		filterHint.hidden = !parts.length;
		filterHint.textContent = parts.join(' · ');
	};

	const populateOrderOptions = (options) => {
		if (!orderFilter) {
			return;
		}
		const current = state.orderId;
		const rows = Array.isArray(options) ? options : [];
		orderFilter.innerHTML = `<option value="">All orders</option>${rows
			.map((opt) => `<option value="${opt.id}">${escapeHtml(opt.label)}</option>`)
			.join('')}`;
		orderFilter.value = current && rows.some((o) => String(o.id) === String(current)) ? String(current) : '';
		if (orderFilter.value !== String(current || '')) {
			state.orderId = orderFilter.value;
		}
	};

	const setPeriod = (period, { clearDates = true } = {}) => {
		state.period = period === 'all' ? '' : period || '';
		if (clearDates && state.period) {
			state.dateFrom = '';
			state.dateTo = '';
			if (dateFromEl) {
				dateFromEl.value = '';
			}
			if (dateToEl) {
				dateToEl.value = '';
			}
		}
		syncPeriodPills();
	};

	const renderRows = (items) => {
		if (!items.length) {
			tbody.innerHTML = `<tr><td colspan="${colCount}" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No deliveries found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map((item) => {
				const orderCell = item.order_id
					? DsCrmUI.orderNumberWithLink(item.order_number || '—', orderViewUrl(item.order_id), { linkTitle: 'View order' })
					: escapeHtml(item.order_number || '—');
				const clientCell = isClient ? '' : `<td>${escapeHtml(item.client_name || '—')}</td>`;
				return `
			<tr>
				<td>${escapeHtml(item.delivery_number)}</td>
				<td class="ds-crm-datetime">${formatListDateTime(item, 'delivery_date')}</td>
				<td>${orderCell}</td>
				${clientCell}
				<td>${DsCrmUI.renderProductPreview(item.product_preview, item.item_count)}</td>
				<td>${item.item_count || 0}</td>
				<td>${formatWeight(item.total_kg)}</td>
				<td>${formatAmount(item.shipping_bill)}</td>
				<td class="ds-crm-actions">
					${DsCrmUI.wrapActions(
						DsCrmUI.actionButton('View', 'view', { className: 'ds-crm-view-delivery', attrs: `data-id="${item.id}"`, iconOnly: true })
					)}
				</td>
			</tr>`;
			})
			.join('');
	};

	const loadList = async () => {
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="${colCount}">Loading…</td></tr>`;
		updateFilterHint();

		const res = await postAjax('crm_deliveries_list', {
			page: state.page,
			per_page: state.perPage,
			search: state.search,
			date_from: state.period ? '' : state.dateFrom,
			date_to: state.period ? '' : state.dateTo,
			period: state.period || 'all',
			order_id: state.orderId || 0,
		});

		if (!res.success) {
			tbody.innerHTML = `<tr><td colspan="${colCount}">${escapeHtml(res.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		populateOrderOptions(res.data.order_options || []);
		renderRows(res.data.items || []);
		DsCrmUI.renderModuleSummary(root, res.data.summary, {
			onCardClick: (payload) => {
				const nextPeriod = payload?.period || '';
				if (!nextPeriod) {
					return;
				}
				setPeriod(nextPeriod);
				state.page = 1;
				loadList();
			},
		});
		DsCrmUI.syncModuleSummaryFilter(root, '', '', state.period || 'all');
		state.totalPages = res.data.total_pages || 1;
		paginationEl.hidden = state.totalPages <= 1 && !res.data.total;
		pageInfo.textContent = `Page ${res.data.page} of ${state.totalPages} (${res.data.total} total)`;
		prevBtn.disabled = state.page <= 1;
		nextBtn.disabled = state.page >= state.totalPages;
	};

	const voidDelivery = async (id) => {
		if (!window.confirm('Void this delivery? Stock will be restored and the record will be removed.')) {
			return;
		}
		const res = await postAjax('crm_deliveries_void', { id });
		if (res.success) {
			DsCrmUI.toast(res.data?.message || 'Delivery voided.');
			DsCrmUI.closeModal(viewModal);
			DsCrm.clearAjaxGetCache('crm_deliveries_');
			state.page = 1;
			loadList();
		} else {
			DsCrmUI.toast(res.data?.message || 'Void failed.', 'error');
		}
	};

	const openView = async (id, trigger = null) => {
		await DsCrmUI.runModalAction({
			modal: viewModal,
			trigger,
			label: 'Loading delivery…',
			task: async () => {
				const res = await postAjax('crm_deliveries_get', { id });
				if (!res.success) {
					DsCrmUI.toast(res.data?.message || 'Failed to load delivery.', 'error');
					return false;
				}

				const { delivery, items, delivered_by_name, can_void, view_ui: viewUi = {} } = res.data;
				const showClient = viewUi.show_client !== false && !isClient;
				const showDeliveredBy = viewUi.show_delivered_by !== false && !isClient;
				const showNotes = viewUi.show_notes !== false && !isClient;
				const showRate = viewUi.show_shipping_rate !== false && !isClient;
				const itemCols = showRate ? 6 : 5;

				viewModal.querySelector('#ds-crm-delivery-view-title').textContent = `Delivery ${delivery.delivery_number}`;

				if (viewActions) {
					viewActions.innerHTML = can_void
						? `<p class="ds-crm-view-actions"><button type="button" class="button ds-crm-btn-void ds-crm-btn-void-delivery" data-id="${delivery.id}">Void delivery</button></p>`
						: '';
				}

				const orderMeta = delivery.order_id
					? DsCrmUI.orderNumberWithLink(delivery.order_number || '—', orderViewUrl(delivery.order_id), { linkTitle: 'View order' })
					: escapeHtml(delivery.order_number || '—');

				viewMeta.innerHTML = `
					<div class="ds-crm-order-meta-grid">
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Order</span>
							<span class="ds-crm-meta-value">${orderMeta}</span>
						</div>
						${
							showClient
								? `<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Client</span>
							<span class="ds-crm-meta-value">${escapeHtml(delivery.client_name || '—')}</span>
						</div>`
								: ''
						}
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Date</span>
							<span class="ds-crm-meta-value">${formatDate(delivery.delivery_date)}</span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Total KG</span>
							<span class="ds-crm-meta-value">${formatWeight(delivery.total_kg, { withUnit: true })}</span>
						</div>
						<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Shipping bill</span>
							<span class="ds-crm-meta-value">${formatAmount(delivery.shipping_bill)}</span>
						</div>
						${
							showDeliveredBy
								? `<div class="ds-crm-meta-item">
							<span class="ds-crm-meta-label">Delivered by</span>
							<span class="ds-crm-meta-value">${escapeHtml(delivered_by_name || '—')}</span>
						</div>`
								: ''
						}
					</div>
					${
						delivery.receiver_name
							? `<div class="ds-crm-order-notes"><span class="ds-crm-meta-label">Receiver</span><p>${escapeHtml(delivery.receiver_name)}${delivery.receiver_phone ? ` · ${escapeHtml(delivery.receiver_phone)}` : ''}${delivery.receiver_address ? `<br>${escapeHtml(delivery.receiver_address)}` : ''}</p></div>`
							: ''
					}
					${showNotes && delivery.notes ? `<div class="ds-crm-order-notes"><span class="ds-crm-meta-label">Notes</span><p>${escapeHtml(delivery.notes)}</p></div>` : ''}
				`;

				if (!items.length) {
					viewItemsBody.innerHTML = `<tr><td colspan="${itemCols}" class="ds-crm-empty">No items.</td></tr>`;
				} else {
					viewItemsBody.innerHTML = items
						.map((item) => {
							const rate = parseFloat(item.shipping_rate_per_kg) || 0;
							const share = parseFloat(item.shipping_share) || 0;
							const weight = parseFloat(item.weight_kg) || 0;
							return `
						<tr>
							<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm' })}</td>
							<td>${escapeHtml([item.color, item.size].filter(Boolean).join(' / ') || '—')}</td>
							<td>${item.quantity}</td>
							<td>${formatWeight(weight, { withUnit: true })}</td>
							${showRate ? `<td>${rate > 0 ? formatAmount(rate) : '—'}</td>` : ''}
							<td>${share > 0 ? formatAmount(share) : '—'}</td>
						</tr>`;
						})
						.join('');
				}

				return true;
			},
		});
	};

	tbody?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-view-delivery');
		if (btn) {
			openView(btn.dataset.id, btn);
		}
	});

	viewModal?.addEventListener('click', (e) => {
		const voidBtn = e.target.closest('.ds-crm-btn-void-delivery');
		if (voidBtn) {
			voidDelivery(parseInt(voidBtn.dataset.id, 10));
		}
	});

	periodPills?.addEventListener('click', (e) => {
		const btn = e.target.closest('.ds-crm-filter-pill');
		if (!btn) {
			return;
		}
		setPeriod(btn.dataset.period || '');
		state.page = 1;
		loadList();
	});

	orderFilter?.addEventListener('change', (ev) => {
		state.orderId = ev.target.value || '';
		state.page = 1;
		loadList();
	});

	root.querySelector('.ds-crm-search')?.addEventListener(
		'input',
		debounce((ev) => {
			state.search = ev.target.value.trim();
			state.page = 1;
			loadList();
		})
	);

	dateFromEl?.addEventListener('change', (ev) => {
		state.dateFrom = ev.target.value;
		if (state.dateFrom || state.dateTo) {
			setPeriod('', { clearDates: false });
		}
		state.page = 1;
		loadList();
	});

	dateToEl?.addEventListener('change', (ev) => {
		state.dateTo = ev.target.value;
		if (state.dateFrom || state.dateTo) {
			setPeriod('', { clearDates: false });
		}
		state.page = 1;
		loadList();
	});

	root.querySelector('.ds-crm-per-page')?.addEventListener('change', (ev) => {
		state.perPage = parseInt(ev.target.value, 10) || 10;
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

	syncPeriodPills();
	loadList();
})();
