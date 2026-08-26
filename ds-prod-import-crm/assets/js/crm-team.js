/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="team"]');
	if (!root) return;

	const { postAjax, DsCrmUI } = DsCrm;
	const modal =
		root.querySelector('#ds-crm-team-permissions-modal') ||
		document.getElementById('ds-crm-team-permissions-modal');
	if (!modal) return;

	const groupsEl = modal.querySelector('.ds-crm-team-permissions-groups');
	const titleEl = modal.querySelector('#ds-crm-team-permissions-title');
	const subtitleEl = modal.querySelector('.ds-crm-team-permissions-subtitle');
	const resetBtn = modal.querySelector('.ds-crm-team-permissions-reset');
	const saveBtn = modal.querySelector('.ds-crm-team-permissions-save');

	let activeUserId = 0;

	DsCrmUI.mountModal(modal);
	DsCrmUI.wireModal(modal);

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const permBadge = (input) => {
		const roleDefault = input.dataset.roleDefault === '1';
		const enabled = input.checked;

		if (enabled === roleDefault) {
			return roleDefault
				? { className: 'ds-crm-perm-badge ds-crm-perm-badge--default', text: 'Role default' }
				: { className: 'ds-crm-perm-badge ds-crm-perm-badge--off', text: 'Off by role' };
		}

		return { className: 'ds-crm-perm-badge ds-crm-perm-badge--custom', text: 'Custom' };
	};

	const syncPermissionBadge = (input) => {
		const badge = input.closest('.ds-crm-permission-toggle')?.querySelector('.ds-crm-perm-badge');
		if (!badge) return;

		const next = permBadge(input);
		badge.className = next.className;
		badge.textContent = next.text;
	};

	const syncCapDependencies = (changedInput = null) => {
		if (!groupsEl) return;

		const inputs = Array.from(groupsEl.querySelectorAll('input[data-cap]'));
		const byCap = Object.fromEntries(inputs.map((input) => [input.dataset.cap, input]));

		if (changedInput && !changedInput.checked) {
			inputs.forEach((input) => {
				if (input.dataset.dependsOn === changedInput.dataset.cap) {
					input.checked = false;
				}
			});
		}

		inputs.forEach((input) => {
			const parent = input.dataset.dependsOn ? byCap[input.dataset.dependsOn] : null;
			const blocked = parent && !parent.checked;
			input.disabled = !!blocked;
			input.closest('.ds-crm-permission-toggle')?.classList.toggle('is-disabled', !!blocked);
			if (blocked) {
				input.checked = false;
			}
		});
	};

	const syncResetVisibility = () => {
		if (!resetBtn || !groupsEl) return;

		const hasPendingCustom = Array.from(groupsEl.querySelectorAll('input[data-cap]')).some((input) => {
			const roleDefault = input.dataset.roleDefault === '1';
			return input.checked !== roleDefault;
		});

		resetBtn.hidden = !hasPendingCustom && resetBtn.dataset.hasSavedCustom !== '1';
	};

	const renderGroups = (groups) => {
		if (!groups?.length) {
			return '<p class="ds-crm-ledger-empty">No permissions to configure.</p>';
		}

		return groups
			.map(
				(group) => `
			<div class="ds-crm-permissions-group">
				<h3 class="ds-crm-permissions-group-title">${escapeHtml(group.label)}</h3>
				<ul class="ds-crm-permissions-list">
					${group.items
						.map((item) => {
							const badge = item.is_customized
								? { className: 'ds-crm-perm-badge ds-crm-perm-badge--custom', text: 'Custom' }
								: item.enabled === item.role_default
									? item.role_default
										? { className: 'ds-crm-perm-badge ds-crm-perm-badge--default', text: 'Role default' }
										: { className: 'ds-crm-perm-badge ds-crm-perm-badge--off', text: 'Off by role' }
									: { className: 'ds-crm-perm-badge ds-crm-perm-badge--custom', text: 'Custom' };
							const dependsAttr = item.depends_on
								? ` data-depends-on="${escapeHtml(item.depends_on)}"`
								: '';
							return `
						<li>
							<label class="ds-crm-permission-toggle">
								<input
									type="checkbox"
									data-cap="${escapeHtml(item.cap)}"
									data-role-default="${item.role_default ? '1' : '0'}"${dependsAttr}
									${item.enabled ? 'checked' : ''}
								/>
								<span class="ds-crm-permission-label">${escapeHtml(item.label)}</span>
								<span class="${badge.className}">${badge.text}</span>
							</label>
						</li>`;
						})
						.join('')}
				</ul>
			</div>`
			)
			.join('');
	};

	const collectPermissions = () => {
		const permissions = {};
		modal.querySelectorAll('input[data-cap]').forEach((input) => {
			permissions[input.dataset.cap] = input.checked;
		});
		return permissions;
	};

	const updateUserRowBadge = (hasCustom) => {
		const row = root.querySelector(`tr[data-user-id="${activeUserId}"]`);
		const roleCell = row?.querySelector('.ds-crm-team-role-cell');
		if (!roleCell) return;

		roleCell.querySelector('.ds-crm-badge--custom')?.remove();

		if (hasCustom) {
			const badge = document.createElement('span');
			badge.className = 'ds-crm-badge ds-crm-badge--custom';
			badge.textContent = 'custom';
			roleCell.appendChild(document.createTextNode(' '));
			roleCell.appendChild(badge);
		}
	};

	const openForUser = async (userId) => {
		activeUserId = userId;

		await DsCrmUI.runModalAction({
			modal,
			label: 'Loading permissions…',
			task: async () => {
				const result = await postAjax('crm_team_user_permissions', { user_id: userId });

				if (!result?.success) {
					DsCrmUI.toast(result.data?.message || 'Could not load permissions.', 'error');
					return false;
				}

				const { user, groups, has_custom: hasCustom } = result.data;
				titleEl.textContent = `Permissions — ${user.display_name}`;
				const roleNote = user.has_multiple_roles
					? ' · Multiple CRM roles — defaults reflect the combined role capabilities'
					: '';
				subtitleEl.textContent = `${user.email} · ${user.role_label || user.role}${roleNote}`;
				if (groupsEl) {
					groupsEl.innerHTML = renderGroups(groups);
					syncCapDependencies();
				}
				if (resetBtn) {
					resetBtn.dataset.hasSavedCustom = hasCustom ? '1' : '0';
					syncResetVisibility();
				}
				DsCrmUI.openModal(modal);
				return true;
			},
		});
	};

	const handleReset = async () => {
		if (!activeUserId) return;

		if (!window.confirm('Reset this user to their CRM role defaults? Any custom permissions will be removed.')) {
			return;
		}

		DsCrmUI.setButtonLoading(resetBtn, true);

		try {
			const result = await postAjax('crm_team_reset_permissions', { user_id: activeUserId });
			if (!result?.success) {
				DsCrmUI.toast(result.data?.message || 'Reset failed.', 'error');
				return;
			}

			DsCrmUI.toast(result.data?.message || 'Reset to role defaults.');
			if (resetBtn) {
				resetBtn.dataset.hasSavedCustom = '0';
			}
			updateUserRowBadge(false);
			await openForUser(activeUserId);
		} finally {
			DsCrmUI.setButtonLoading(resetBtn, false);
		}
	};

	const handleSave = async () => {
		if (!activeUserId) return;

		DsCrmUI.setButtonLoading(saveBtn, true);

		try {
			const permissions = collectPermissions();
			const result = await postAjax('crm_team_save_permissions', {
				user_id: activeUserId,
				permissions: JSON.stringify(permissions),
			});

			if (!result?.success) {
				DsCrmUI.toast(result.data?.message || 'Save failed.', 'error');
				return;
			}

			const hasCustom = !!result.data?.has_custom;
			DsCrmUI.toast(result.data?.message || 'Permissions saved.');
			if (resetBtn) {
				resetBtn.dataset.hasSavedCustom = hasCustom ? '1' : '0';
			}
			updateUserRowBadge(hasCustom);
			DsCrmUI.closeModal(modal);
		} finally {
			DsCrmUI.setButtonLoading(saveBtn, false);
		}
	};

	groupsEl?.addEventListener('change', (e) => {
		const input = e.target.closest('input[data-cap]');
		if (!input) return;
		if (input.dataset.dependsOn) {
			const parent = groupsEl.querySelector(`input[data-cap="${input.dataset.dependsOn}"]`);
			if (parent && !parent.checked && input.checked) {
				parent.checked = true;
				syncPermissionBadge(parent);
			}
		}
		syncCapDependencies(input);
		syncPermissionBadge(input);
		groupsEl.querySelectorAll('input[data-cap]').forEach((el) => syncPermissionBadge(el));
		syncResetVisibility();
	});

	modal.addEventListener('click', async (e) => {
		if (e.target.closest('.ds-crm-team-permissions-reset')) {
			e.preventDefault();
			await handleReset();
			return;
		}

		if (e.target.closest('.ds-crm-team-permissions-save')) {
			e.preventDefault();
			await handleSave();
		}
	});

	root.addEventListener('click', async (e) => {
		const permBtn = e.target.closest('.ds-crm-team-permissions-btn');
		if (!permBtn) return;

		e.preventDefault();
		const userId = parseInt(permBtn.dataset.userId, 10);
		if (userId) {
			await openForUser(userId);
		}
	});
})();
