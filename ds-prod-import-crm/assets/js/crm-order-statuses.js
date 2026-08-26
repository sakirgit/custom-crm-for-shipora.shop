/* global DsCrm */

(() => {
	const root = document.querySelector('[data-crm-module="order-statuses"]');
	if (!root) return;

	const { postAjax, DsCrmUI } = DsCrm;
	const modal = document.getElementById('ds-crm-status-modal');
	const form = modal?.querySelector('.ds-crm-status-form');
	const tbody = root.querySelector('.ds-crm-statuses-table tbody');
	const slugField = form?.querySelector('.ds-crm-status-slug-field');

	DsCrmUI.wireModal(modal);

	const load = async () => {
		const res = await postAjax('crm_order_statuses_list');
		if (!res.success) {
			tbody.innerHTML = '<tr><td colspan="7">Failed to load.</td></tr>';
			return;
		}
		const items = res.data.items || [];
		DsCrmUI.renderModuleSummary(root, res.data.summary);
		if (!items.length) {
			tbody.innerHTML = '<tr><td colspan="7">No statuses.</td></tr>';
			return;
		}
		tbody.innerHTML = items
			.map(
				(s) => `
			<tr data-id="${s.id}" data-system="${s.is_system}"
				data-auto-on-paid="${s.auto_on_paid == 1 ? '1' : '0'}"
				data-blocks-workflow="${s.blocks_workflow == 1 ? '1' : '0'}"
				data-is-closed="${s.is_closed == 1 ? '1' : '0'}">
				<td><span class="ds-crm-badge" style="background:${s.color}22;color:${s.color}">${s.label}</span></td>
				<td><code>${s.slug}</code></td>
				<td>${s.color}</td>
				<td>${s.auto_on_paid == 1 ? 'Yes' : '—'}</td>
				<td>${s.blocks_workflow == 1 ? 'Yes' : '—'}</td>
				<td>${s.is_closed == 1 ? 'Yes' : '—'}</td>
				<td>
					<button type="button" class="button button-small ds-crm-edit">Edit</button>
					${s.is_system == 1 ? '' : '<button type="button" class="button button-small ds-crm-delete">Delete</button>'}
				</td>
			</tr>`
			)
			.join('');
	};

	root.querySelector('.ds-crm-btn-add-status')?.addEventListener('click', () => {
		form.reset();
		form.querySelector('[name="id"]').value = '';
		slugField.hidden = false;
		DsCrmUI.openModal(modal);
	});

	tbody?.addEventListener('click', async (e) => {
		const tr = e.target.closest('tr');
		if (!tr) return;
		const id = tr.dataset.id;
		const cells = tr.querySelectorAll('td');
		if (e.target.closest('.ds-crm-edit')) {
			form.querySelector('[name="id"]').value = id;
			form.querySelector('[name="label"]').value = cells[0].textContent.trim();
			form.querySelector('[name="slug"]').value = tr.querySelector('code')?.textContent || '';
			form.querySelector('[name="color"]').value = cells[2].textContent.trim();
			form.querySelector('[name="auto_on_paid"]').checked = tr.dataset.autoOnPaid === '1';
			form.querySelector('[name="blocks_workflow"]').checked = tr.dataset.blocksWorkflow === '1';
			form.querySelector('[name="is_closed"]').checked = tr.dataset.isClosed === '1';
			slugField.hidden = tr.dataset.system === '1';
			DsCrmUI.openModal(modal);
		}
		if (e.target.closest('.ds-crm-delete') && confirm('Delete this status?')) {
			await postAjax('crm_order_statuses_delete', { id });
			load();
		}
	});

	form?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const res = await postAjax('crm_order_statuses_save', {
			id: form.querySelector('[name="id"]').value,
			label: form.querySelector('[name="label"]').value,
			slug: form.querySelector('[name="slug"]').value,
			color: form.querySelector('[name="color"]').value,
			auto_on_paid: form.querySelector('[name="auto_on_paid"]').checked ? 1 : 0,
			blocks_workflow: form.querySelector('[name="blocks_workflow"]').checked ? 1 : 0,
			is_closed: form.querySelector('[name="is_closed"]').checked ? 1 : 0,
		});
		if (!res.success) {
			alert(res.data?.message || 'Save failed');
			return;
		}
		DsCrmUI.closeModal(modal);
		load();
	});

	load();
})();
