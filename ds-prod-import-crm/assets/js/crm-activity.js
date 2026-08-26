/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="activity"]');
	if (!root) return;

	const { postAjax, debounce, formatDateTime, DsCrmUI } = DsCrm;
	const tbody = root.querySelector('.ds-crm-activity-table tbody');
	const paginationEl = root.querySelector('.ds-crm-pagination');
	const pageNumbersEl = root.querySelector('.ds-crm-page-numbers');
	const pageInfo = root.querySelector('.ds-crm-page-info');
	const prevBtn = root.querySelector('.ds-crm-page-prev');
	const nextBtn = root.querySelector('.ds-crm-page-next');

	const state = {
		page: 1,
		perPage: 25,
		search: '',
		module: '',
		actionType: '',
		userId: '',
		dateFrom: '',
		dateTo: '',
		totalPages: 1,
		total: 0,
	};

	let listController = null;
	let listRequestId = 0;

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const renderChanges = (changes) => {
		if (!changes?.length) return '';
		return `<ul class="ds-crm-activity-changes">${changes.map((c) => `<li>${escapeHtml(c)}</li>`).join('')}</ul>`;
	};

	const renderRows = (items) => {
		if (!items?.length) {
			tbody.innerHTML = `<tr><td colspan="6" class="ds-crm-empty">${DsCrmApp.i18n?.noRecords || 'No activity found.'}</td></tr>`;
			return;
		}

		tbody.innerHTML = items
			.map(
				(item) => `
			<tr>
				<td class="ds-crm-datetime">${formatDateTime(item.created_at)}</td>
				<td>${escapeHtml(item.user_name)}</td>
				<td>${escapeHtml(item.module_label)}</td>
				<td><span class="ds-crm-badge ds-crm-badge-activity-${escapeHtml(item.action)}">${escapeHtml(item.action_label)}</span></td>
				<td>#${item.record_id || '—'}</td>
				<td>
					<div class="ds-crm-activity-desc">${escapeHtml(item.description)}</div>
					${renderChanges(item.changes)}
				</td>
			</tr>`
			)
			.join('');
	};

	const updatePagination = () => {
		paginationEl.hidden = state.totalPages <= 1 && !state.total;
		DsCrmUI.renderPagination({
			pageNumbersEl,
			pageInfoEl: pageInfo,
			prevBtn,
			nextBtn,
			page: state.page,
			totalPages: state.totalPages,
			total: state.total,
			totalLabel: 'events',
		});
	};

	const loadList = async () => {
		if (listController) {
			listController.abort();
		}
		listController = new AbortController();
		const requestId = ++listRequestId;

		tbody.innerHTML = '<tr class="ds-crm-loading-row"><td colspan="6">Loading…</td></tr>';

		try {
			const result = await postAjax(
				'crm_activity_list',
				{
					page: state.page,
					per_page: state.perPage,
					module: state.module,
					action_type: state.actionType,
					user_id: state.userId,
					search: state.search,
					date_from: state.dateFrom,
					date_to: state.dateTo,
				},
				{ cache: false, signal: listController.signal }
			);

			if (requestId !== listRequestId) {
				return;
			}

			if (!result?.success) {
				tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(result?.data?.message || 'Failed to load.')}</td></tr>`;
				return;
			}

			renderRows(result.data.items || []);
			DsCrmUI.renderModuleSummary(root, result.data.summary);
			state.totalPages = result.data.total_pages || 1;
			state.total = result.data.total || 0;
			state.page = result.data.page || state.page;
			updatePagination();
		} catch (err) {
			if (err?.name === 'AbortError' || requestId !== listRequestId) {
				return;
			}
			tbody.innerHTML = '<tr><td colspan="6">Failed to load activity log.</td></tr>';
		}
	};

	const goToPage = (page) => {
		const next = Math.max(1, Math.min(page, state.totalPages));
		if (next === state.page) {
			return;
		}
		state.page = next;
		loadList();
	};

	const fillSelect = (select, options, placeholder) => {
		if (!select) return;
		select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>`;
		Object.entries(options || {}).forEach(([value, label]) => {
			const opt = document.createElement('option');
			opt.value = value;
			opt.textContent = label;
			select.appendChild(opt);
		});
	};

	const loadFilters = async () => {
		const result = await postAjax('crm_activity_filters', {}, { cache: false });
		if (!result?.success) return;

		fillSelect(root.querySelector('.ds-crm-activity-module'), result.data.modules, 'All modules');
		fillSelect(root.querySelector('.ds-crm-activity-action'), result.data.actions, 'All actions');

		const userSelect = root.querySelector('.ds-crm-activity-user');
		if (userSelect) {
			userSelect.innerHTML = '<option value="">All users</option>';
			(result.data.users || []).forEach((user) => {
				const opt = document.createElement('option');
				opt.value = String(user.id);
				opt.textContent = user.label;
				userSelect.appendChild(opt);
			});
		}
	};

	root.querySelector('.ds-crm-activity-search')?.addEventListener(
		'input',
		debounce((e) => {
			state.search = e.target.value.trim();
			state.page = 1;
			loadList();
		}, 350)
	);

	['.ds-crm-activity-module', '.ds-crm-activity-action', '.ds-crm-activity-user', '.ds-crm-per-page'].forEach((sel) => {
		root.querySelector(sel)?.addEventListener('change', (e) => {
			if (sel.includes('module')) state.module = e.target.value;
			if (sel.includes('action')) state.actionType = e.target.value;
			if (sel.includes('user')) state.userId = e.target.value;
			if (sel.includes('per-page')) state.perPage = parseInt(e.target.value, 10) || 25;
			state.page = 1;
			loadList();
		});
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

	prevBtn?.addEventListener('click', () => goToPage(state.page - 1));
	nextBtn?.addEventListener('click', () => goToPage(state.page + 1));

	pageNumbersEl?.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-page]');
		if (!btn) return;
		goToPage(parseInt(btn.dataset.page, 10));
	});

	loadFilters().then(loadList);
})();
