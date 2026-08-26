/* global DsCrm, DsCrmApp */

(() => {
	const panel = document.querySelector('[data-crm-panel="data-reset"]');
	if (!panel) {
		return;
	}

	const { postAjax, DsCrmUI } = DsCrm;
	const countsEl = panel.querySelector('.ds-crm-data-reset-counts');
	const totalEl = panel.querySelector('.ds-crm-data-reset-total');
	const confirmInput = panel.querySelector('.ds-crm-data-reset-confirm-input');
	const submitBtn = panel.querySelector('.ds-crm-data-reset-submit');
	const refreshBtn = panel.querySelector('.ds-crm-data-reset-refresh');
	const errorEl = panel.querySelector('.ds-crm-data-reset-error');
	const successEl = panel.querySelector('.ds-crm-data-reset-success');

	let confirmPhrase = 'RESET';

	const setMessage = (el, message) => {
		if (!el) {
			return;
		}
		if (!message) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		el.hidden = false;
		const p = document.createElement('p');
		p.textContent = message;
		el.innerHTML = '';
		el.appendChild(p);
	};

	const formatCount = (count) => Number(count || 0).toLocaleString();

	const renderCounts = (counts) => {
		const tables = counts?.tables || [];
		if (!tables.length) {
			countsEl.innerHTML = '<li>—</li>';
			totalEl.hidden = true;
			return;
		}

		countsEl.innerHTML = tables
			.map((row) => `<li><span>${row.label}</span><strong>${formatCount(row.count)}</strong></li>`)
			.join('');

		if (totalEl) {
			totalEl.hidden = false;
			totalEl.textContent = `${formatCount(counts.total_rows)} total rows across all tables`;
		}
	};

	const updateSubmitState = () => {
		if (!submitBtn || !confirmInput) {
			return;
		}
		submitBtn.disabled = confirmInput.value.trim() !== confirmPhrase;
	};

	const loadStats = async () => {
		setMessage(errorEl, '');
		countsEl.innerHTML = '<li class="ds-crm-data-reset-loading">Loading counts…</li>';
		totalEl.hidden = true;

		const result = await postAjax('crm_admin_data_stats');
		if (!result.success) {
			countsEl.innerHTML = `<li>${result.data?.message || 'Could not load counts.'}</li>`;
			return;
		}

		confirmPhrase = result.data.confirm || 'RESET';
		renderCounts(result.data.counts);
	};

	const runReset = async () => {
		if (confirmInput.value.trim() !== confirmPhrase) {
			setMessage(errorEl, `Type ${confirmPhrase} exactly to confirm.`);
			return;
		}

		if (
			!window.confirm(
				'Delete ALL operational CRM data? This cannot be undone. Settings will be kept.'
			)
		) {
			return;
		}

		setMessage(errorEl, '');
		setMessage(successEl, '');
		submitBtn.disabled = true;
		submitBtn.textContent = 'Deleting…';

		const result = await postAjax('crm_admin_reset_data', {
			confirm_phrase: confirmInput.value.trim(),
		});

		submitBtn.textContent = 'Delete all CRM data';

		if (!result.success) {
			setMessage(errorEl, result.data?.message || 'Reset failed.');
			updateSubmitState();
			return;
		}

		confirmInput.value = '';
		updateSubmitState();
		renderCounts(result.data.counts);
		setMessage(successEl, result.data.message || 'CRM data cleared.');
		DsCrmUI.toast(result.data.message || 'CRM data cleared.');
	};

	confirmInput?.addEventListener('input', updateSubmitState);
	refreshBtn?.addEventListener('click', loadStats);
	submitBtn?.addEventListener('click', runReset);

	loadStats();
})();
