/* global DsCrm, DsCrmApp, wp */

(() => {
	const root = document.querySelector('[data-crm-module="settings"]');
	if (!root) return;

	const { postAjax, DsCrmUI } = DsCrm;
	const form = root.querySelector('.ds-crm-settings-form');
	const section = root.dataset.settingsSection || form?.querySelector('[name="settings_section"]')?.value || 'general';
	const errorBox = form?.querySelector('.ds-crm-form-error');
	const noticesEl = root.querySelector('.ds-crm-settings-notices');
	const pageSelect = form?.querySelector('.ds-crm-frontend-page-select');
	const shortcodeEl = root.querySelector('.ds-crm-shortcode-display');
	const linkWrap = root.querySelector('.ds-crm-frontend-link-wrap');
	const linkEl = root.querySelector('.ds-crm-frontend-link');
	const hintEl = root.querySelector('.ds-crm-page-shortcode-hint');
	const useWpMedia = Boolean(DsCrmApp?.useWpMedia && typeof wp !== 'undefined' && wp.media);
	const colorPreview = root.querySelector('.ds-crm-color-preview');

	const isValidHex = (value) => /^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/.test(value);

	const normalizeHex = (value) => {
		let hex = String(value || '').trim();
		if (!hex) return '';
		if (hex[0] !== '#') hex = `#${hex}`;
		if (/^#[a-fA-F0-9]{3}$/.test(hex)) {
			hex = `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`;
		}
		return isValidHex(hex) ? hex.toLowerCase() : '';
	};

	const syncColorField = (colorInput, hexInput) => {
		if (!colorInput || !hexInput) return;
		const hex = normalizeHex(hexInput.value || colorInput.value);
		if (hex) {
			colorInput.value = hex;
			hexInput.value = hex;
		}
	};

	const updateColorPreview = () => {
		if (!colorPreview) return;
		const sidebar = normalizeHex(form.querySelector('[name="color_sidebar"]')?.value) || '#1a1f2e';
		const accent = normalizeHex(form.querySelector('[name="color_accent"]')?.value) || '#2563eb';
		const accentSecondary =
			normalizeHex(form.querySelector('[name="color_accent_secondary"]')?.value) || '#7c3aed';
		const sidebarEl = colorPreview.querySelector('.ds-crm-color-preview-sidebar');
		const activeNav = colorPreview.querySelector('.ds-crm-color-preview-nav.is-active');
		const activeTab = colorPreview.querySelector('.ds-crm-color-preview-tab.is-active');
		const kpiPrimary = colorPreview.querySelector('.ds-crm-color-preview-kpi:not(.is-secondary)');
		const kpiSecondary = colorPreview.querySelector('.ds-crm-color-preview-kpi.is-secondary');
		if (sidebarEl) sidebarEl.style.background = sidebar;
		if (activeNav) activeNav.style.background = accent;
		if (activeTab) {
			activeTab.style.background = accent;
			activeTab.style.borderColor = accent;
		}
		if (kpiPrimary) kpiPrimary.style.borderLeftColor = accent;
		if (kpiSecondary) kpiSecondary.style.borderLeftColor = accentSecondary;
	};

	const initColorFields = () => {
		form.querySelectorAll('input[type="color"][name^="color_"]').forEach((colorInput) => {
			const hexInput = form.querySelector(`.ds-crm-color-hex[data-color-for="${colorInput.id}"]`);
			if (!hexInput) return;

			colorInput.addEventListener('input', () => {
				hexInput.value = colorInput.value;
				updateColorPreview();
			});

			hexInput.addEventListener('input', () => {
				const hex = normalizeHex(hexInput.value);
				if (hex) {
					colorInput.value = hex;
					hexInput.value = hex;
				}
				updateColorPreview();
			});

			hexInput.addEventListener('blur', () => syncColorField(colorInput, hexInput));
		});
	};

	initColorFields();

	const setError = (msg) => {
		if (!errorBox) return;
		if (!msg) {
			errorBox.hidden = true;
			errorBox.textContent = '';
			return;
		}
		errorBox.hidden = false;
		errorBox.textContent = msg;
	};

	const renderNotices = (warnings) => {
		if (!noticesEl) return;
		if (!warnings?.length) {
			noticesEl.innerHTML = '';
			return;
		}
		noticesEl.innerHTML = warnings.map((w) => `<div class="ds-crm-notice ds-crm-notice-info">${w}</div>`).join('');
	};

	const updatePageHint = () => {
		const opt = pageSelect?.selectedOptions?.[0];
		if (!opt || !hintEl) return;
		if (opt.dataset.hasShortcode === '1') {
			hintEl.hidden = false;
			hintEl.textContent = '✓ This page contains the CRM shortcode.';
			hintEl.className = 'description ds-crm-page-shortcode-hint ds-crm-hint-ok';
		} else if (opt.value) {
			hintEl.hidden = false;
			hintEl.textContent = '⚠ Add the shortcode to this page content.';
			hintEl.className = 'description ds-crm-page-shortcode-hint ds-crm-hint-warn';
		} else {
			hintEl.hidden = true;
		}
	};

	const setInput = (name, value) => {
		const el = form.querySelector(`[name="${name}"]`);
		if (el && 'value' in el) {
			el.value = value ?? '';
		}
	};

	const setRadio = (name, value) => {
		form.querySelectorAll(`[name="${name}"]`).forEach((radio) => {
			radio.checked = radio.value === value;
		});
	};

	const mediaUrlForSave = (mediaType, settingKey) => {
		const fieldEl = form.querySelector(`.ds-crm-media-field[data-media-type="${mediaType}"]`);
		const input = form.querySelector(`[name="${settingKey}"]`);
		if (fieldEl?.dataset.cleared === '1') {
			return '';
		}
		return input?.value || cachedSettings[settingKey] || '';
	};

	let cachedSettings = {};

	const setMediaPreview = (fieldEl, url) => {
		const input = fieldEl.querySelector('input[type="hidden"]');
		const box = fieldEl.querySelector('.ds-crm-media-preview-box');
		const img = fieldEl.querySelector('.ds-crm-media-preview-img');
		const removeBtn = fieldEl.querySelector('.ds-crm-media-remove');
		const selectBtn = fieldEl.querySelector('.ds-crm-media-select');

		if (input) input.value = url || '';
		if (url) {
			delete fieldEl.dataset.cleared;
		}

		if (box) {
			box.classList.toggle('is-empty', !url);
		}
		if (img) {
			if (url) {
				img.src = url;
				img.alt = '';
			} else {
				img.removeAttribute('src');
			}
		}
		if (removeBtn) removeBtn.hidden = !url;
		if (selectBtn) {
			selectBtn.textContent = url
				? DsCrmApp?.i18n?.changeMedia || 'Change image'
				: fieldEl.dataset.mediaType === 'favicon'
					? DsCrmApp?.i18n?.selectFavicon || 'Select favicon'
					: DsCrmApp?.i18n?.selectLogo || 'Select logo';
		}
	};

	const initMediaField = (fieldEl) => {
		if (!fieldEl) return;

		const selectBtn = fieldEl.querySelector('.ds-crm-media-select');
		const removeBtn = fieldEl.querySelector('.ds-crm-media-remove');
		let frame;

		removeBtn?.addEventListener('click', (e) => {
			e.preventDefault();
			fieldEl.dataset.cleared = '1';
			setMediaPreview(fieldEl, '');
		});

		if (!useWpMedia) {
			selectBtn?.setAttribute('disabled', 'disabled');
			return;
		}

		selectBtn?.addEventListener('click', (e) => {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: fieldEl.dataset.mediaTitle || 'Select image',
				button: { text: fieldEl.dataset.mediaButton || 'Use image' },
				library: { type: 'image' },
				multiple: false,
			});

			frame.on('select', () => {
				const attachment = frame.state().get('selection').first().toJSON();
				delete fieldEl.dataset.cleared;
				setMediaPreview(fieldEl, attachment.url || '');
			});

			frame.open();
		});
	};

	root.querySelectorAll('.ds-crm-media-field').forEach(initMediaField);

	const load = async () => {
		const res = await postAjax('crm_settings_get');
		if (!res.success) {
			setError(res.data?.message || 'Failed to load settings.');
			return;
		}

		const s = res.data.settings || {};
		cachedSettings = { ...s };

		if (shortcodeEl && res.data.shortcode) {
			shortcodeEl.textContent = res.data.shortcode;
		}

		const pages = res.data.pages || [];
		if (pageSelect) {
			const selected = String(s.frontend_page_id || '');
			pageSelect.innerHTML =
				'<option value="">— Select page —</option>' +
				pages
					.map(
						(p) =>
							`<option value="${p.id}" data-has-shortcode="${p.has_shortcode ? '1' : '0'}" ${String(p.id) === selected ? 'selected' : ''}>${p.title}${p.has_shortcode ? ' ✓' : ''}</option>`
					)
					.join('');
		}

		setInput('company_name', s.company_name || '');
		setInput('company_tagline', s.company_tagline || '');
		setInput('low_stock_threshold', s.low_stock_threshold ?? 5);
		setInput('currency_symbol', s.currency_symbol ?? '৳');

		setRadio('pricing_mode', s.pricing_mode === 'single' ? 'single' : 'dual');
		setRadio('client_orders_scope', s.client_orders_scope === 'all' ? 'all' : 'own');
		setInput('shipments_module_label', s.shipments_module_label || '');
		setInput('china_timezone', s.china_timezone || 'Asia/Shanghai');
		setInput('bangladesh_timezone', s.bangladesh_timezone || 'Asia/Dhaka');
		const dualTz = form.querySelector('[name="tracking_show_dual_tz"]');
		if (dualTz) {
			dualTz.checked = s.tracking_show_dual_tz === 1 || s.tracking_show_dual_tz === true || s.tracking_show_dual_tz === '1';
		}
		const missingAuto = form.querySelector('[name="missing_auto_approve"]');
		if (missingAuto) {
			missingAuto.checked = s.missing_auto_approve === 1 || s.missing_auto_approve === true || s.missing_auto_approve === '1';
		}

		['color_sidebar', 'color_accent', 'color_accent_secondary'].forEach((name) => {
			const colorInput = form.querySelector(`[name="${name}"]`);
			const hexInput = colorInput
				? form.querySelector(`.ds-crm-color-hex[data-color-for="${colorInput.id}"]`)
				: null;
			const value = normalizeHex(s[name] || '') || colorInput?.value || '';
			if (colorInput && value) colorInput.value = value;
			if (hexInput && value) hexInput.value = value;
			if (colorInput && hexInput) syncColorField(colorInput, hexInput);
		});
		updateColorPreview();

		root.querySelectorAll('.ds-crm-media-field').forEach((fieldEl) => {
			const key = fieldEl.dataset.mediaType === 'favicon' ? 'favicon_url' : 'company_logo_url';
			setMediaPreview(fieldEl, s[key] || '');
		});

		renderNotices(res.data.warnings || []);
		updatePageHint();

		if (res.data.frontend_url && linkWrap && linkEl) {
			linkWrap.hidden = false;
			linkEl.href = res.data.frontend_url;
			linkEl.textContent = res.data.frontend_url;
		}
	};

	pageSelect?.addEventListener('change', updatePageHint);

	root.querySelector('.ds-crm-copy-shortcode')?.addEventListener('click', () => {
		const text = shortcodeEl?.textContent || '[ds_prod_import_crm]';
		navigator.clipboard?.writeText(text).then(() => DsCrmUI.toast(DsCrmApp?.i18n?.copied || 'Shortcode copied.'));
	});

	const buildSavePayload = () => {
		const payload = { settings_section: section };

		switch (section) {
			case 'appearance':
				payload.company_name = form.querySelector('[name="company_name"]')?.value || '';
				payload.company_tagline = form.querySelector('[name="company_tagline"]')?.value || '';
				payload.company_logo_url = mediaUrlForSave('logo', 'company_logo_url');
				payload.favicon_url = mediaUrlForSave('favicon', 'favicon_url');
				payload.color_sidebar = normalizeHex(form.querySelector('[name="color_sidebar"]')?.value) || '#1a1f2e';
				payload.color_accent = normalizeHex(form.querySelector('[name="color_accent"]')?.value) || '#2563eb';
				payload.color_accent_secondary =
					normalizeHex(form.querySelector('[name="color_accent_secondary"]')?.value) || '#7c3aed';
				payload.frontend_page_id = pageSelect?.value || '0';
				break;
			case 'general':
			default:
				payload.low_stock_threshold = form.querySelector('[name="low_stock_threshold"]')?.value || '5';
				payload.currency_symbol = form.querySelector('[name="currency_symbol"]')?.value || '৳';
				payload.pricing_mode = form.querySelector('[name="pricing_mode"]:checked')?.value || 'single';
				payload.client_orders_scope = form.querySelector('[name="client_orders_scope"]:checked')?.value || 'own';
				payload.shipments_module_label = form.querySelector('[name="shipments_module_label"]')?.value || '';
				payload.china_timezone = form.querySelector('[name="china_timezone"]')?.value || 'Asia/Shanghai';
				payload.bangladesh_timezone = form.querySelector('[name="bangladesh_timezone"]')?.value || 'Asia/Dhaka';
				payload.tracking_show_dual_tz = form.querySelector('[name="tracking_show_dual_tz"]')?.checked ? '1' : '0';
				payload.missing_auto_approve = form.querySelector('[name="missing_auto_approve"]')?.checked ? '1' : '0';
				break;
		}

		return payload;
	};

	form?.addEventListener('submit', async (e) => {
		e.preventDefault();
		setError('');

		const submitBtn = form.querySelector('[type="submit"]');
		if (submitBtn) submitBtn.disabled = true;

		const res = await postAjax('crm_settings_save', buildSavePayload());

		if (submitBtn) submitBtn.disabled = false;

		if (!res.success) {
			setError(res.data?.message || 'Save failed.');
			return;
		}

		DsCrmUI.toast(res.data?.message || 'Settings saved.');
		if (res.data.frontend_url && linkWrap && linkEl) {
			linkWrap.hidden = false;
			linkEl.href = res.data.frontend_url;
			linkEl.textContent = res.data.frontend_url;
		}
		load();
	});

	root.querySelector('.ds-crm-sync-portal-users')?.addEventListener('click', async (e) => {
		const btn = e.currentTarget;
		btn.disabled = true;
		const res = await postAjax('crm_settings_sync_portal_users');
		btn.disabled = false;
		if (!res.success) {
			DsCrmUI.toast(res.data?.message || 'Sync failed.', 'error');
			return;
		}
		DsCrmUI.toast(res.data?.message || 'Sync complete.');
		window.location.reload();
	});

	load();
})();
