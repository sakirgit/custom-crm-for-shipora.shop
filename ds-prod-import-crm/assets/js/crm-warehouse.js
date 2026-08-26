/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="warehouse"]');
	if (!root) {
		return;
	}

	const { postAjax, debounce, formatDate, formatListDateTime, formatAmount, formatWeight, DsCrmUI } = DsCrm;
	const viewModal = document.getElementById('ds-crm-receive-view-modal');
	const filterCompany = root.querySelector('.ds-crm-filter-company');
	const filterClient = root.querySelector('.ds-crm-filter-client');
	const dateFromEl = root.querySelector('.ds-crm-date-from');
	const dateToEl = root.querySelector('.ds-crm-date-to');
	const awaitingBody = root.querySelector('.ds-crm-warehouse-awaiting-table tbody');
	const historyBody = root.querySelector('.ds-crm-warehouse-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');

	const state = {
		tab: 'awaiting',
		page: 1,
		perPage: 10,
		sortBy: 'ship_date',
		sortDir: 'DESC',
		search: '',
		companyId: '',
		clientId: '',
		dateFrom: '',
		dateTo: '',
		totalPages: 1,
	};

	DsCrmUI.wireModal(viewModal);

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const statusLabel = (status) => {
		const map = {
			in_transit: 'In transit',
			partially_received: 'Partially received',
			received: 'Received in BD',
			void: 'Void',
		};
		return map[status] || status || '—';
	};

	const fillSelect = (el, items, emptyLabel, valueKey = 'id', labelKey = 'name') => {
		if (!el) {
			return;
		}
		const options = (items || [])
			.map((c) => `<option value="${c[valueKey]}">${escapeHtml(c[labelKey])}</option>`)
			.join('');
		el.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>${options}`;
	};

	const setActiveTab = (tab) => {
		state.tab = tab;
		state.page = 1;
		if (tab === 'awaiting') {
			state.sortBy = 'ship_date';
			state.sortDir = 'DESC';
		} else {
			state.sortBy = 'receive_date';
			state.sortDir = 'DESC';
		}

		root.querySelectorAll('.ds-crm-warehouse-tabs .ds-crm-subnav-tab').forEach((btn) => {
			const active = btn.dataset.warehouseTab === tab;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-selected', active ? 'true' : 'false');
		});

		root.querySelectorAll('.ds-crm-warehouse-panel').forEach((panel) => {
			panel.hidden = panel.dataset.warehousePanel !== tab;
		});

		if (dateFromEl) {
			dateFromEl.hidden = tab !== 'history';
		}
		if (dateToEl) {
			dateToEl.hidden = tab !== 'history';
		}

		loadList();
	};

	const loadFormData = async () => {
		const result = await postAjax('crm_warehouse_form_data');
		if (result.success) {
			fillSelect(filterCompany, result.data.companies || [], 'All companies');
			fillSelect(filterClient, result.data.clients || [], 'All clients');
		}
	};

	const renderAwaitingRows = (items) => {
		if (!items.length) {
			awaitingBody.innerHTML = `<tr><td colspan="9" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No shipments awaiting receive.'}</td></tr>`;
			return;
		}

		awaitingBody.innerHTML = items
			.map((item) => {
				const receiveUrl = item.receive_form_url || '';
				const receiveBtn = receiveUrl
					? `<a class="button button-small button-primary ds-crm-btn-text" href="${escapeHtml(receiveUrl)}">Receive</a>`
					: '';
				const remaining = `${item.qty_remaining || 0} / ${item.qty_shipped || 0}`;
				const missingHint =
					parseInt(item.qty_missing || 0, 10) > 0
						? `<div class="description">${escapeHtml(String(item.qty_missing))} missing so far</div>`
						: '';
				return `
			<tr>
				<td>${escapeHtml(item.shipment_number)}</td>
				<td>${formatDate(item.ship_date)}</td>
				<td>${escapeHtml(item.client_name || '—')}</td>
				<td>${escapeHtml(item.company_name || '—')}</td>
				<td>${escapeHtml(item.order_number || '—')}</td>
				<td>${DsCrmUI.renderProductPreview(item.product_preview, item.item_count)}</td>
				<td>${escapeHtml(remaining)}${missingHint}</td>
				<td><span class="ds-crm-badge ds-crm-badge-shipment-${escapeHtml(item.status)}">${escapeHtml(statusLabel(item.status))}</span></td>
				<td class="ds-crm-actions">${receiveBtn}</td>
			</tr>`;
			})
			.join('');
	};

	const renderHistoryRows = (items) => {
		if (!items.length) {
			historyBody.innerHTML = `<tr><td colspan="9" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No receives found.'}</td></tr>`;
			return;
		}

		historyBody.innerHTML = items
			.map((item) => {
				const missing =
					parseInt(item.missing_qty || 0, 10) > 0
						? `<div class="description">${escapeHtml(String(item.missing_qty))} missing</div>`
						: '';
				return `
			<tr>
				<td>${escapeHtml(item.receive_number)}</td>
				<td class="ds-crm-datetime">${formatListDateTime(item, 'receive_date')}</td>
				<td>${escapeHtml(item.client_name || '—')}</td>
				<td>${escapeHtml(item.company_name || '—')}</td>
				<td>${escapeHtml(item.shipment_number || '—')}${missing}</td>
				<td>${DsCrmUI.renderProductPreview(item.product_preview, item.item_count)}</td>
				<td>${formatWeight(item.total_kg)}</td>
				<td>${formatAmount(item.shipping_bill)}</td>
				<td class="ds-crm-actions">
					<button type="button" class="button button-small ds-crm-view" data-id="${item.id}">View</button>
				</td>
			</tr>`;
			})
			.join('');
	};

	const loadList = async () => {
		const isAwaiting = state.tab === 'awaiting';
		const tbody = isAwaiting ? awaitingBody : historyBody;
		tbody.innerHTML = `<tr class="ds-crm-loading-row"><td colspan="9">Loading…</td></tr>`;

		const payload = {
			page: state.page,
			per_page: state.perPage,
			sort_by: state.sortBy,
			sort_dir: state.sortDir,
			search: state.search,
			company_id: state.companyId,
			client_id: state.clientId,
		};

		if (!isAwaiting) {
			payload.date_from = state.dateFrom;
			payload.date_to = state.dateTo;
		}

		const result = await postAjax(isAwaiting ? 'crm_warehouse_awaiting' : 'crm_warehouse_list', payload);

		if (!result.success) {
			tbody.innerHTML = `<tr><td colspan="9">${escapeHtml(result.data?.message || 'Failed to load.')}</td></tr>`;
			return;
		}

		if (isAwaiting) {
			renderAwaitingRows(result.data.items || []);
			DsCrmUI.renderModuleSummary?.(root, null);
			const summaryHost = root.querySelector('.ds-crm-module-summary');
			if (summaryHost) {
				summaryHost.innerHTML = '';
			}
		} else {
			renderHistoryRows(result.data.items || []);
			DsCrmUI.renderModuleSummary(root, result.data.summary);
		}

		state.totalPages = result.data.total_pages || 1;
		paginationEl.hidden = state.totalPages <= 1 && !result.data.total;
		pageInfo.textContent = `Page ${result.data.page} of ${state.totalPages} (${result.data.total} total)`;
		prevBtn.disabled = state.page <= 1;
		nextBtn.disabled = state.page >= state.totalPages;
	};

	const voidReceive = async (id) => {
		if (!window.confirm('Void this receive? Stock will be reduced and the record will be removed. Linked shipment remaining qty will reopen.')) {
			return;
		}
		const res = await postAjax('crm_warehouse_void', { id });
		if (res.success) {
			DsCrmUI.toast(res.data?.message || 'Receive voided.');
			DsCrmUI.closeModal(viewModal);
			DsCrm.clearAjaxGetCache('crm_warehouse_');
			loadList();
		} else {
			DsCrmUI.toast(res.data?.message || 'Void failed.', 'error');
		}
	};

	const viewReceive = async (id, trigger = null) => {
		await DsCrmUI.runModalAction({
			modal: viewModal,
			trigger,
			label: 'Loading receive record…',
			task: async () => {
				const result = await postAjax('crm_warehouse_get', { id });
				if (!result.success) {
					DsCrmUI.toast(result.data?.message || 'Failed to load.', 'error');
					return false;
				}

				const { receive, items, can_void, void_message } = result.data;
				const shippingBill =
					parseFloat(receive.shipping_bill || 0) ||
					items.reduce((sum, i) => sum + parseFloat(i.shipping_share || 0), 0);
				const hasLegacyProductBill = items.some((item) => parseFloat(item.bill_amount || 0) > 0);
				const hasMissing = items.some((item) => parseInt(item.missing_quantity || 0, 10) > 0);

				viewModal.querySelector('.ds-crm-receive-view-title').textContent = receive.receive_number;
				const voidBtn = can_void
					? `<p class="ds-crm-view-actions"><button type="button" class="button ds-crm-btn-void ds-crm-btn-void-receive" data-id="${receive.id}">Void receive</button></p>`
					: void_message
						? `<p class="ds-crm-view-actions ds-crm-void-hint description">${escapeHtml(void_message)}</p>`
						: '';
				viewModal.querySelector('.ds-crm-receive-view-body').innerHTML = `
			${voidBtn}
			<p><strong>Company:</strong> ${escapeHtml(receive.company_name || '—')}</p>
			${receive.client_name ? `<p><strong>Client:</strong> ${escapeHtml(receive.client_name)}</p>` : ''}
			${receive.shipment_number ? `<p><strong>Shipment:</strong> ${escapeHtml(receive.shipment_number)}</p>` : ''}
			${receive.order_number ? `<p><strong>Order:</strong> ${escapeHtml(receive.order_number)}</p>` : ''}
			<p><strong>Date:</strong> ${formatDate(receive.receive_date)}</p>
			<p><strong>Total KG:</strong> ${formatWeight(receive.total_kg, { withUnit: true })}</p>
			<p><strong>Shipping bill:</strong> ${formatAmount(shippingBill)}</p>
			${receive.notes ? `<p><strong>Notes:</strong> ${escapeHtml(receive.notes)}</p>` : ''}
			<table class="ds-crm-table">
				<thead>
					<tr>
						<th>Product</th><th>Color</th><th>Size</th><th>Received</th>
						${hasMissing ? '<th>Missing</th>' : ''}
						<th>KG</th><th>Ship. rate / kg</th><th>Line shipping</th>
						${hasLegacyProductBill ? '<th>Legacy product bill</th>' : ''}
					</tr>
				</thead>
				<tbody>
					${items
						.map((item) => {
							const lineRate = parseFloat(item.shipping_rate_per_kg || 0);
							const displayRate =
								lineRate > 0
									? lineRate
									: parseFloat(item.weight_kg || 0) > 0
										? parseFloat(item.shipping_share || 0) / parseFloat(item.weight_kg)
										: 0;
							return `
						<tr>
							<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm' })}</td>
							<td>${escapeHtml(item.color || '—')}</td>
							<td>${escapeHtml(item.size || '—')}</td>
							<td>${escapeHtml(String(item.quantity))}</td>
							${hasMissing ? `<td>${escapeHtml(String(item.missing_quantity || 0))}</td>` : ''}
							<td>${formatWeight(item.weight_kg)}</td>
							<td>${formatAmount(displayRate)}</td>
							<td>${formatAmount(item.shipping_share || 0)}</td>
							${hasLegacyProductBill ? `<td>${formatAmount(item.bill_amount || 0)}</td>` : ''}
						</tr>`;
						})
						.join('')}
				</tbody>
			</table>
		`;
				return true;
			},
		});
	};

	root.querySelectorAll('.ds-crm-warehouse-tabs .ds-crm-subnav-tab').forEach((btn) => {
		btn.addEventListener('click', () => setActiveTab(btn.dataset.warehouseTab));
	});

	root.querySelector('.ds-crm-search')?.addEventListener(
		'input',
		debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			loadList();
		})
	);
	filterCompany?.addEventListener('change', (e) => {
		state.companyId = e.target.value;
		state.page = 1;
		loadList();
	});
	filterClient?.addEventListener('change', (e) => {
		state.clientId = e.target.value;
		state.page = 1;
		loadList();
	});
	dateFromEl?.addEventListener('change', (e) => {
		state.dateFrom = e.target.value;
		state.page = 1;
		loadList();
	});
	dateToEl?.addEventListener('change', (e) => {
		state.dateTo = e.target.value;
		state.page = 1;
		loadList();
	});
	root.querySelector('.ds-crm-per-page')?.addEventListener('change', (e) => {
		state.perPage = parseInt(e.target.value, 10);
		state.page = 1;
		loadList();
	});

	root.querySelectorAll('.ds-crm-warehouse-awaiting-table th[data-sort], .ds-crm-warehouse-table th[data-sort]').forEach((th) => {
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

	historyBody?.addEventListener('click', (e) => {
		const viewBtn = e.target.closest('.ds-crm-view');
		if (viewBtn) {
			viewReceive(parseInt(viewBtn.dataset.id, 10), viewBtn);
		}
	});

	viewModal?.addEventListener('click', (e) => {
		const voidBtn = e.target.closest('.ds-crm-btn-void-receive');
		if (voidBtn) {
			voidReceive(parseInt(voidBtn.dataset.id, 10));
		}
	});

	loadFormData().then(() => setActiveTab('awaiting'));
})();
