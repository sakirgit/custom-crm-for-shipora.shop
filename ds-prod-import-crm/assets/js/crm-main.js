/* global DsCrmApp */

const AJAX_GET_CACHE_MS = 45000;
const ajaxGetCache = new Map();

const clearAjaxGetCache = (actionPrefix = '') => {
	for (const key of ajaxGetCache.keys()) {
		if (!actionPrefix || key.startsWith(actionPrefix)) {
			ajaxGetCache.delete(key);
		}
	}
};

const postAjax = async (action, payload = {}, options = {}) => {
	const useCache = options.cache !== false && /_(get|options)$/.test(action);
	const cacheKey = useCache ? `${action}:${JSON.stringify(payload)}` : '';

	if (cacheKey) {
		const cached = ajaxGetCache.get(cacheKey);
		if (cached && Date.now() - cached.at < AJAX_GET_CACHE_MS) {
			return cached.data;
		}
	}

	const formData = new FormData();
	formData.append('action', action);
	formData.append('nonce', DsCrmApp.nonce);

	Object.entries(payload).forEach(([key, value]) => {
		if (value !== undefined && value !== null) {
			formData.append(key, value);
		}
	});

	const response = await fetch(DsCrmApp.ajaxUrl, {
		method: 'POST',
		body: formData,
		credentials: 'same-origin',
		signal: options.signal,
	});

	const data = await response.json();

	if (cacheKey && data?.success) {
		ajaxGetCache.set(cacheKey, { at: Date.now(), data });
	}

	return data;
};

const postAjaxForm = async (action, formData) => {
	formData.append('action', action);
	formData.append('nonce', DsCrmApp.nonce);

	const response = await fetch(DsCrmApp.ajaxUrl, {
		method: 'POST',
		body: formData,
		credentials: 'same-origin',
	});

	return response.json();
};

const debounce = (fn, delay = 300) => {
	let timer;
	return (...args) => {
		clearTimeout(timer);
		timer = setTimeout(() => fn(...args), delay);
	};
};

const formatAmount = (amount) => {
	const value = Number(amount);
	const symbol = window.DsCrmApp?.currencySymbol || '৳';
	if (Number.isNaN(value)) {
		return `${symbol}0.00`;
	}
	return `${symbol}${value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const parseWeight = (value) => {
	const n = parseFloat(value);
	if (Number.isNaN(n) || n < 0) {
		return 0;
	}
	return Math.round(n * 100) / 100;
};

const formatWeight = (kg, { withUnit = false } = {}) => {
	const value = parseWeight(kg);
	const formatted = value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	return withUnit ? `${formatted} kg` : formatted;
};

const sumWeights = (values) => values.reduce((sum, value) => sum + parseWeight(value), 0);

const allocateByWeight = (totalAmount, weights) => {
	const total = parseFloat(totalAmount) || 0;
	const normalized = weights.map((weight) => parseWeight(weight));
	const weightTotal = normalized.reduce((sum, weight) => sum + weight, 0);

	if (total <= 0 || weightTotal <= 0 || !normalized.length) {
		return normalized.map(() => 0);
	}

	const shares = [];
	let allocated = 0;

	normalized.forEach((weight, index) => {
		if (index === normalized.length - 1) {
			shares.push(Math.round((total - allocated) * 100) / 100);
			return;
		}

		const share = Math.round((weight / weightTotal) * total * 100) / 100;
		shares.push(share);
		allocated += share;
	});

	return shares;
};

const getTimezoneConfig = () => window.DsCrmApp?.timezone || {};

const parseMysqlParts = (dateStr) => {
	const raw = String(dateStr || '').trim();
	if (!raw) {
		return null;
	}
	const normalized = raw.replace(' ', 'T');
	const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
	if (!match) {
		return null;
	}
	const [, year, month, day, hour, minute, second] = match;
	return {
		year: Number(year),
		month: Number(month),
		day: Number(day),
		hour: Number(hour ?? 12),
		minute: Number(minute ?? 0),
		second: Number(second ?? 0),
		isDateOnly: !hour,
	};
};

const getIntlParts = (date, timeZone) => {
	const formatter = new Intl.DateTimeFormat('en-GB', {
		timeZone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: 'numeric',
		minute: '2-digit',
		second: '2-digit',
		hour12: false,
	});
	const parts = {};
	formatter.formatToParts(date).forEach((part) => {
		if (part.type !== 'literal') {
			parts[part.type] = part.value;
		}
	});
	return {
		year: Number(parts.year),
		month: Number(parts.month),
		day: Number(parts.day),
		hour: Number(parts.hour),
		minute: Number(parts.minute),
		second: Number(parts.second ?? 0),
	};
};

const zonedTimeToUtcDate = (parts, timeZone) => {
	const targetUtc = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
	let utcGuess = targetUtc;
	for (let i = 0; i < 4; i += 1) {
		const zoned = getIntlParts(new Date(utcGuess), timeZone);
		const zonedUtc = Date.UTC(zoned.year, zoned.month - 1, zoned.day, zoned.hour, zoned.minute, zoned.second);
		const diff = targetUtc - zonedUtc;
		if (diff === 0) {
			break;
		}
		utcGuess += diff;
	}
	return new Date(utcGuess);
};

const parseSiteDatetime = (dateStr) => {
	const parts = parseMysqlParts(dateStr);
	if (!parts) {
		return null;
	}
	const siteTimezone = getTimezoneConfig().siteTimezone || 'Asia/Dhaka';
	return zonedTimeToUtcDate(parts, siteTimezone);
};

const formatDate = (dateStr) => {
	if (!dateStr) {
		return '—';
	}
	const date = parseSiteDatetime(dateStr);
	if (!date || Number.isNaN(date.getTime())) {
		return dateStr;
	}
	const timeZone = getTimezoneConfig().displayTimezone || 'Asia/Dhaka';
	return date.toLocaleDateString('en-GB', {
		timeZone,
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
	});
};

const formatDateTime = (dateStr, options = {}) => {
	if (!dateStr) {
		return '—';
	}
	const timeZone = options.timeZone || getTimezoneConfig().displayTimezone || 'Asia/Dhaka';
	let date = parseSiteDatetime(dateStr);
	const parts = parseMysqlParts(dateStr);
	if (!date || Number.isNaN(date.getTime())) {
		date = new Date(dateStr);
	}
	if (!date || Number.isNaN(date.getTime())) {
		return dateStr;
	}
	const formatOptions = {
		timeZone,
		day: '2-digit',
		month: '2-digit',
		year: 'numeric',
	};
	if (!parts?.isDateOnly) {
		formatOptions.hour = 'numeric';
		formatOptions.minute = '2-digit';
		formatOptions.hour12 = options.hour12 !== false;
	}
	return date.toLocaleString('en-GB', formatOptions);
};

const formatTrackingTime = (time) => {
	if (!time?.primary) {
		return '';
	}
	if (time.dual && time.secondary) {
		const primaryLabel = time.primary_label || '';
		const secondaryLabel = time.secondary_label || '';
		return `${primaryLabel}: ${time.primary} · ${secondaryLabel}: ${time.secondary}`;
	}
	return time.primary;
};

const formatListDateTime = (item, fallbackField = '') => {
	if (item?.created_at) {
		return formatDateTime(item.created_at);
	}
	if (fallbackField && item?.[fallbackField]) {
		return formatDateTime(item[fallbackField]);
	}
	return '—';
};

const DsCrmUI = {
	escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	},

	productThumb(imageUrl, { size = 'md', linkUrl = '' } = {}) {
		const sizeClass = size === 'sm' ? ' ds-crm-product-line-thumb--sm' : '';
		if (imageUrl) {
			const safeUrl = String(imageUrl).replace(/"/g, '');
			const img = `<img src="${this.escapeHtml(safeUrl)}" alt="" class="ds-crm-product-line-thumb${sizeClass}" loading="lazy" />`;
			const href = String(linkUrl || imageUrl).replace(/"/g, '');
			if (href) {
				return `<a class="ds-crm-product-thumb-link" href="${this.escapeHtml(href)}" data-full-image="${this.escapeHtml(href)}" target="_blank" rel="noopener noreferrer" title="View full image">${img}</a>`;
			}
			return img;
		}
		return `<span class="ds-crm-product-line-thumb ds-crm-product-line-thumb--empty${sizeClass}">?</span>`;
	},

	closeImageLightbox() {
		const existing = document.getElementById('ds-crm-image-lightbox');
		if (existing) {
			existing.remove();
		}
		document.body.classList.remove('ds-crm-image-lightbox-open');
	},

	openImageLightbox(imageUrl) {
		const src = String(imageUrl || '').trim();
		if (!src) {
			return;
		}

		this.closeImageLightbox();

		const overlay = document.createElement('div');
		overlay.id = 'ds-crm-image-lightbox';
		overlay.className = 'ds-crm-image-lightbox';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-label', 'Product image');
		overlay.innerHTML = `
			<button type="button" class="ds-crm-image-lightbox-close" aria-label="Close">&times;</button>
			<div class="ds-crm-image-lightbox-stage">
				<img class="ds-crm-image-lightbox-img" src="${this.escapeHtml(src)}" alt="Product image" />
			</div>
		`;

		const close = () => this.closeImageLightbox();
		overlay.addEventListener('click', (event) => {
			if (event.target === overlay || event.target.closest('.ds-crm-image-lightbox-close')) {
				close();
			}
		});
		overlay.querySelector('.ds-crm-image-lightbox-img')?.addEventListener('click', (event) => {
			event.stopPropagation();
		});

		document.body.appendChild(overlay);
		document.body.classList.add('ds-crm-image-lightbox-open');
		overlay.querySelector('.ds-crm-image-lightbox-close')?.focus();
	},

	wireProductImageLightbox() {
		if (document.documentElement.dataset.dsCrmImageLightbox === '1') {
			return;
		}
		document.documentElement.dataset.dsCrmImageLightbox = '1';

		document.addEventListener('click', (event) => {
			const link = event.target.closest('.ds-crm-product-thumb-link');
			if (!link) {
				return;
			}
			const fullUrl = link.getAttribute('data-full-image') || link.getAttribute('href') || '';
			if (!fullUrl) {
				return;
			}
			event.preventDefault();
			this.openImageLightbox(fullUrl);
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && document.getElementById('ds-crm-image-lightbox')) {
				this.closeImageLightbox();
			}
		});
	},

	productCell(name, imageUrl = '', { size = 'md', fullImageUrl = '' } = {}) {
		const safeName = this.escapeHtml(name || '—');
		return `<div class="ds-crm-product-cell" title="${safeName}">${this.productThumb(imageUrl, {
			size,
			linkUrl: fullImageUrl || imageUrl,
		})}<span class="ds-crm-product-cell-name">${safeName}</span></div>`;
	},

	deliveryPriorityRank(priority) {
		const ranks = { urgent: 0, priority: 1, normal: 2 };
		return ranks[priority] ?? 2;
	},

	deliveryPriorityLabel(priority) {
		const labels = { urgent: 'Emergency', priority: '2nd priority', normal: 'Normal' };
		return labels[priority] || labels.normal;
	},

	deliveryPriorityBeaconHtml({ variant = 'urgent' } = {}) {
		if (variant === 'priority') {
			return '<span class="ds-crm-priority-beacon ds-crm-priority-beacon--priority" aria-hidden="true"><span class="ds-crm-priority-beacon-light ds-crm-priority-beacon-light--yellow"></span></span>';
		}
		return '<span class="ds-crm-priority-beacon ds-crm-priority-beacon--urgent" aria-hidden="true"><span class="ds-crm-priority-beacon-light"></span><span class="ds-crm-priority-beacon-light ds-crm-priority-beacon-light--alt"></span></span>';
	},

	deliveryPrioritySignalHtml(priority) {
		const slug = ['urgent', 'priority', 'normal'].includes(priority) ? priority : 'normal';
		if (slug === 'urgent') {
			return `<span class="ds-crm-priority-signal ds-crm-priority-signal--urgent" title="Emergency" aria-label="Emergency">${this.deliveryPriorityBeaconHtml({ variant: 'urgent' })}</span>`;
		}
		if (slug === 'priority') {
			return `<span class="ds-crm-priority-signal ds-crm-priority-signal--priority" title="2nd priority" aria-label="2nd priority">${this.deliveryPriorityBeaconHtml({ variant: 'priority' })}</span>`;
		}
		return '';
	},

	deliveryPriorityBadge(priority) {
		return this.deliveryPrioritySignalHtml(priority);
	},

	deliveryPrioritySelectOptions(selected = 'normal') {
		return ['normal', 'priority', 'urgent']
			.map(
				(slug) =>
					`<option value="${slug}"${slug === selected ? ' selected' : ''}>${this.escapeHtml(this.deliveryPriorityLabel(slug))}</option>`
			)
			.join('');
	},

	syncDeliveryPriorityRow(row) {
		if (!row) {
			return;
		}
		const priority = row.querySelector('.line-delivery-priority')?.value || 'normal';
		row.classList.remove('ds-crm-order-line--urgent', 'ds-crm-order-line--priority');
		if (priority === 'urgent') {
			row.classList.add('ds-crm-order-line--urgent');
		} else if (priority === 'priority') {
			row.classList.add('ds-crm-order-line--priority');
		}
		const signalEl = row.querySelector('.ds-crm-priority-signal-slot');
		if (signalEl) {
			signalEl.innerHTML = this.deliveryPrioritySignalHtml(priority);
		}
	},

	sortByDeliveryPriority(items) {
		return [...(items || [])].sort((a, b) => {
			const rankA = this.deliveryPriorityRank(a.delivery_priority);
			const rankB = this.deliveryPriorityRank(b.delivery_priority);
			if (rankA !== rankB) {
				return rankA - rankB;
			}
			return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0);
		});
	},

	productPickerSuggestionHtml(product, textHtml) {
		const thumb = product?.image_url
			? `<img src="${this.escapeHtml(product.image_url)}" class="ds-crm-suggestion-thumb" alt="" />`
			: '<span class="ds-crm-suggestion-thumb ds-crm-suggestion-thumb--empty">?</span>';
		return `${thumb}<span class="ds-crm-suggestion-text">${textHtml}</span>`;
	},

	productPickerSelectedHtml(product) {
		const img = product?.image_url
			? `<img src="${this.escapeHtml(product.image_url)}" class="ds-crm-selected-product-thumb" alt="" />`
			: '<span class="ds-crm-selected-product-thumb ds-crm-selected-product-thumb--empty">?</span>';
		return `<div class="ds-crm-selected-product-inner">${img}<span class="ds-crm-selected-product-name">${this.escapeHtml(product?.name || '')}</span></div>`;
	},

	formatLineMeta(product, { showDelivery = false } = {}) {
		const parts = [];
		if (product.color) parts.push(product.color);
		if (product.size) parts.push(product.size);
		const variant = parts.length ? parts.join(' / ') : '';
		const qty = parseInt(product.quantity, 10) || 0;

		if (showDelivery && qty > 0) {
			const delivered = parseInt(product.qty_delivered, 10) || 0;
			const due = product.qty_due != null ? parseInt(product.qty_due, 10) : Math.max(0, qty - delivered);
			const delivery = `${delivered}/${qty} delivered${due > 0 ? ` · ${due} due` : ''}`;
			if (variant) return `${variant} · ${delivery}`;
			return delivery;
		}

		const qtyLabel = qty > 0 ? ` × ${qty}` : '';
		if (variant && qtyLabel) return `${variant}${qtyLabel}`;
		if (variant) return variant;
		if (qtyLabel) return qtyLabel.trim();
		return '';
	},

	renderProductPreview(preview, itemCount, { showDelivery = false } = {}) {
		const items = preview || [];
		if (!items.length) {
			return `<span class="ds-crm-order-preview-empty">${itemCount || 0} item(s)</span>`;
		}

		const lines = items
			.map((p) => {
				const thumbSrc = p.image_url || '';
				const fullSrc = p.full_image_url || p.image_url || '';
				let img = thumbSrc
					? `<img src="${this.escapeHtml(thumbSrc)}" alt="" class="ds-crm-order-preview-thumb" loading="lazy" />`
					: '<span class="ds-crm-order-preview-thumb ds-crm-order-preview-thumb--empty">?</span>';
				if (thumbSrc && fullSrc) {
					img = `<a class="ds-crm-product-thumb-link" href="${this.escapeHtml(fullSrc)}" data-full-image="${this.escapeHtml(fullSrc)}" target="_blank" rel="noopener noreferrer" title="View full image">${img}</a>`;
				}
				const meta = this.formatLineMeta(p, { showDelivery });
				const metaHtml = meta ? `<span class="ds-crm-order-preview-line-meta">${this.escapeHtml(meta)}</span>` : '';
				const signal = this.deliveryPrioritySignalHtml(p.delivery_priority);
				return `
					<div class="ds-crm-order-preview-line ds-crm-order-preview-line--${this.escapeHtml(p.delivery_priority || 'normal')}" title="${this.escapeHtml(p.name)}">
						<span class="ds-crm-order-preview-thumb-wrap">${img}</span>
						<span class="ds-crm-order-preview-line-text">
							<span class="ds-crm-order-preview-line-name">${signal}${this.escapeHtml(p.name)}</span>
							${metaHtml}
						</span>
					</div>`;
			})
			.join('');

		const extra =
			(itemCount || items.length) > items.length
				? `<div class="ds-crm-order-preview-more">+${(itemCount || items.length) - items.length} more line(s)</div>`
				: '';

		return `<div class="ds-crm-product-preview"><div class="ds-crm-order-preview-lines">${lines}</div>${extra}</div>`;
	},

	actionIcon(name) {
		const icons = {
			view: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M1.5 10s3.5-6.5 8.5-6.5 8.5 6.5 8.5 6.5-3.5 6.5-8.5 6.5S1.5 10 1.5 10Z"/><circle cx="10" cy="10" r="2.5"/></svg>',
			print: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 7V2.5h10V7"/><path d="M5 14.5H3.5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H15"/><path d="M5 11.5h10v6H5z"/></svg>',
			edit: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12.5 3.5 16.5 7.5 7 17H3v-4L12.5 3.5Z"/><path d="M11 5 15 9"/></svg>',
			export: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M10 3v7"/><path d="m7 7 3 3 3-3"/><path d="M4.5 12.5v3a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-3"/></svg>',
			open: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 4.5H4.5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V12"/><path d="M11.5 3.5H16.5V8.5"/><path d="M9 11 16.5 3.5"/></svg>',
			ledger: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 3.5h8.5a1.5 1.5 0 0 1 1.5 1.5v10a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 15V5A1.5 1.5 0 0 1 5 3.5Z"/><path d="M7 7h6M7 10h6M7 13h4"/></svg>',
			delete: '<svg class="ds-crm-action-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4.5 6.5h11"/><path d="M8 6.5V4.5h4v2"/><path d="M6.5 6.5l.7 9h5.6l.7-9"/><path d="M8.5 9v4.5M11.5 9v4.5"/></svg>',
		};
		return icons[name] || '';
	},

	/**
	 * Order # as selectable text + optional open-icon link (tables / meta).
	 *
	 * @param {string} orderNumber Display text.
	 * @param {string} href        Destination URL; empty = text only.
	 * @param {{ beaconHtml?: string, linkTitle?: string, className?: string }} [opts]
	 * @returns {string}
	 */
	orderNumberWithLink(orderNumber, href = '', { beaconHtml = '', linkTitle = 'Open order', className = '' } = {}) {
		const num = this.escapeHtml(orderNumber || '—');
		const text = `<span class="ds-crm-order-number-text">${num}</span>`;
		const open =
			href
				? `<a class="ds-crm-order-number-open" href="${this.escapeHtml(href)}" title="${this.escapeHtml(linkTitle)}" aria-label="${this.escapeHtml(linkTitle)}">${this.actionIcon('open')}</a>`
				: '';
		const extras = className ? ` ${className}` : '';
		return `<div class="ds-crm-order-number-cell-inner${extras}">${beaconHtml || ''}${text}${open}</div>`;
	},

	actionButton(label, icon, { tag = 'button', className = '', attrs = '', iconOnly = false } = {}) {
		const titleAttr = iconOnly && !attrs.includes('title=') ? ` title="${this.escapeHtml(label)}" aria-label="${this.escapeHtml(label)}"` : '';
		const labelHtml = iconOnly ? '' : ` ${this.escapeHtml(label)}`;
		const content = `${this.actionIcon(icon)}${labelHtml}`;
		const classes = `button button-small${className ? ` ${className}` : ''}`;
		if (tag === 'a') {
			return `<a class="${classes}" ${attrs}${titleAttr}>${content}</a>`;
		}
		return `<button type="button" class="${classes}" ${attrs}${titleAttr}>${content}</button>`;
	},

	/** Wrap row action controls so desktop can middle-align and mobile can stack. */
	wrapActions(...parts) {
		const html = parts.filter(Boolean).join('');
		return `<div class="ds-crm-actions-inner">${html}</div>`;
	},

	wireDismissibleNotice(noticeEl) {
		if (!noticeEl || noticeEl.dataset.wired === '1') {
			return;
		}
		const key = noticeEl.dataset.noticeKey;
		if (key && localStorage.getItem(`ds_crm_notice_${key}`) === '1') {
			noticeEl.hidden = true;
			return;
		}
		const dismissBtn = noticeEl.querySelector('.ds-crm-notice-dismiss');
		dismissBtn?.addEventListener('click', () => {
			noticeEl.hidden = true;
			if (key) {
				localStorage.setItem(`ds_crm_notice_${key}`, '1');
			}
		});
		noticeEl.dataset.wired = '1';
	},

	syncModuleSummaryFilter(root, activeStatus = '', activeTracking = '', activePeriod = '') {
		if (!root) {
			return;
		}
		root.querySelectorAll('.ds-crm-module-summary-card[data-filter-status]').forEach((card) => {
			const filter = card.dataset.filterStatus || '';
			const isActive = activeStatus ? filter === activeStatus : filter === 'all';
			card.classList.toggle('is-filter-active', isActive && !activeTracking && !activePeriod);
		});
		root.querySelectorAll('.ds-crm-module-summary-card[data-filter-tracking]').forEach((card) => {
			const filter = card.dataset.filterTracking || '';
			const isActive = !!activeTracking && filter === activeTracking;
			card.classList.toggle('is-filter-active', isActive);
		});
		root.querySelectorAll('.ds-crm-module-summary-card[data-filter-period]').forEach((card) => {
			const filter = card.dataset.filterPeriod || '';
			const current = activePeriod || 'all';
			card.classList.toggle('is-filter-active', filter === current);
		});
	},

	printOrderDocument({
		order,
		items,
		summary,
		payments = [],
		deliveries = [],
		creator = null,
		branding = {},
		statusLabel = '',
	}) {
		const { formatAmount, formatDate, formatDateTime, formatWeight } = window.DsCrm;
		const colors = branding.colors || {};
		const accent = colors.accent || '#2563eb';
		const accentSecondary = colors.accent_secondary || '#7c3aed';
		const company = branding.company_name || 'CRM';
		const tagline = branding.company_tagline || '';
		const logoUrl = branding.logo_url || '';
		const statusText = statusLabel || order.status || '—';
		const totalWeight = formatWeight(sumWeights((items || []).map((item) => item.weight_kg)));

		const toAbsoluteUrl = (url) => {
			const raw = String(url || '').trim();
			if (!raw) {
				return '';
			}
			try {
				return new URL(raw, window.location.href).href;
			} catch {
				return raw;
			}
		};

		const printStamp = () => {
			const d = new Date();
			const pad = (n) => String(n).padStart(2, '0');
			return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}_${pad(d.getHours())}-${pad(d.getMinutes())}-${pad(d.getSeconds())}`;
		};

		const printProductCell = (item) => {
			const name = item.product_name || '—';
			const priority = item.delivery_priority || 'normal';
			const priorityLabel = priority !== 'normal' ? this.deliveryPriorityLabel(priority) : '';
			const nameHtml = priorityLabel
				? `<span class="ds-crm-product-cell-name"><span class="priority-inline">${this.escapeHtml(priorityLabel)}</span> ${this.escapeHtml(name)}</span>`
				: `<span class="ds-crm-product-cell-name">${this.escapeHtml(name)}</span>`;
			const absThumb = toAbsoluteUrl(item.product_image_url || item.product_full_image_url || '');
			const thumb = absThumb
				? `<img src="${this.escapeHtml(absThumb)}" alt="" class="ds-crm-product-line-thumb ds-crm-product-line-thumb--sm" width="32" height="32" decoding="sync" />`
				: '<span class="ds-crm-product-line-thumb ds-crm-product-line-thumb--empty ds-crm-product-line-thumb--sm">?</span>';
			return `<div class="ds-crm-product-cell">${thumb}${nameHtml}</div>`;
		};

		const lineRows = (items || [])
			.map((item) => {
				const variant = [item.color, item.size].filter(Boolean).join(' / ') || '—';
				const qty = parseFloat(item.quantity) || 0;
				const unit = parseFloat(item.unit_price) || 0;
				const delivered = item.qty_delivered ?? 0;
				const due = item.qty_due ?? Math.max(0, qty - (parseFloat(delivered) || 0));
				const lineTotal = qty * unit;
				return `<tr class="ds-crm-order-line--${this.escapeHtml(item.delivery_priority || 'normal')}">
					<td>${printProductCell(item)}</td>
					<td>${this.escapeHtml(variant)}</td>
					<td>${qty}</td>
					<td>${formatWeight(item.weight_kg)}</td>
					<td>${delivered}</td>
					<td>${due}</td>
					<td>${formatAmount(item.unit_price)}</td>
					<td>${formatAmount(lineTotal)}</td>
				</tr>`;
			})
			.join('');

		const deliveryRows =
			deliveries && deliveries.length
				? deliveries
						.map(
							(d) => `<tr>
						<td>${formatDate(d.delivery_date)}</td>
						<td>${this.escapeHtml(d.delivery_number || '—')}</td>
						<td>${formatWeight(d.total_kg, { withUnit: true })}</td>
						<td>${formatAmount(d.shipping_bill)}</td>
						<td>${d.item_count || 0}</td>
					</tr>`
						)
						.join('')
				: '<tr><td colspan="5" class="empty">No deliveries recorded yet.</td></tr>';

		const paymentRows =
			payments && payments.length
				? payments
						.map(
							(p) => `<tr>
						<td>${formatDate(p.payment_date)}</td>
						<td>${this.escapeHtml(p.payment_number || '—')}</td>
						<td>${formatAmount(p.amount)}</td>
						<td>${this.escapeHtml(p.payment_method || '—')}</td>
						<td>${this.escapeHtml(p.reference || '—')}</td>
					</tr>`
						)
						.join('')
				: '<tr><td colspan="5" class="empty">No payments recorded yet.</td></tr>';

		const absoluteLogoUrl = toAbsoluteUrl(logoUrl);
		const logoHtml = absoluteLogoUrl
			? `<div class="brand-logo-wrap"><img src="${this.escapeHtml(absoluteLogoUrl)}" alt="" class="brand-logo" decoding="sync" /></div>`
			: `<div class="brand-logo brand-logo--placeholder">${this.escapeHtml(company.charAt(0))}</div>`;

		const orderBaseName = String(order.order_number || order.id || 'order-details').replace(/[<>:"/\\|?*\s]+/g, '-');
		const printFileName = `${orderBaseName}_${printStamp()}`;
		const printTitle = this.escapeHtml(printFileName);

		const html = `<!DOCTYPE html><html><head><meta charset="utf-8" /><title>${printTitle}</title>
			<style>
				:root{--accent:${accent};--accent-secondary:${accentSecondary}}
				*{box-sizing:border-box}
				body{font-family:system-ui,-apple-system,sans-serif;color:#111827;margin:0;font-size:13px;line-height:1.45}
				.sheet{padding:24px}
				.brand-bar{display:flex;align-items:center;gap:20px;padding:18px 22px;border-bottom:4px solid var(--accent);background:linear-gradient(90deg,color-mix(in srgb,var(--accent) 8%,#fff),#fff)}
				.brand-logo-wrap{display:flex;align-items:center;justify-content:center;flex-shrink:0;min-width:140px;min-height:96px;padding:10px 14px;background:#fff;border-radius:12px;border:1px solid color-mix(in srgb,var(--accent) 18%,#e5e7eb)}
				.brand-logo{display:block;width:auto;height:auto;max-width:200px;max-height:96px;object-fit:contain}
				.brand-logo--placeholder{display:flex;align-items:center;justify-content:center;width:96px;height:96px;background:var(--accent);color:#fff;font-size:36px;font-weight:700;border-radius:12px;flex-shrink:0}
				.brand-text h1{margin:0;font-size:22px;color:#111827}
				.brand-text p{margin:4px 0 0;color:#6b7280;font-size:13px}
				.doc-title{margin:20px 0 12px;font-size:18px;color:var(--accent)}
				.meta-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
				.meta-card{border:1px solid #e5e7eb;border-left:4px solid var(--accent);border-radius:8px;padding:10px 12px;background:#fafafa}
				.meta-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px}
				.meta-value{font-weight:600;color:#111827}
				.status-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:color-mix(in srgb,var(--accent) 12%,#fff);color:var(--accent);font-size:12px;font-weight:600}
				.section{margin-top:22px}
				.section h2{margin:0 0 8px;font-size:15px;color:var(--accent-secondary);border-bottom:1px solid #e5e7eb;padding-bottom:6px}
				table{width:100%;border-collapse:collapse;margin-top:8px}
				th,td{border:1px solid #e5e7eb;padding:8px;text-align:left;vertical-align:top}
				th{background:color-mix(in srgb,var(--accent) 6%,#f9fafb);font-size:12px;text-transform:uppercase;letter-spacing:.03em}
				td.empty{color:#6b7280;font-style:italic}
				.notes{margin:12px 0 0;padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb}
				.totals{margin-top:16px;max-width:360px;margin-left:auto;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
				.totals div{display:flex;justify-content:space-between;padding:8px 12px;border-bottom:1px solid #f3f4f6}
				.totals .grand{font-weight:700;background:color-mix(in srgb,var(--accent) 8%,#fff);border-top:2px solid var(--accent)}
				.totals .due{font-weight:700;color:var(--accent-secondary)}
				.ds-crm-product-cell{display:flex;align-items:center;gap:8px}
				.ds-crm-product-cell-name{font-weight:500;line-height:1.35}
				.priority-inline{display:inline-block;margin-right:4px;padding:1px 6px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;vertical-align:middle}
				.ds-crm-order-line--urgent .priority-inline{background:#fee2e2;color:#991b1b}
				.ds-crm-product-line-thumb{display:block;width:32px;height:32px;object-fit:cover;border-radius:5px;border:1px solid #ccc;background:#f3f4f6;flex-shrink:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
				.ds-crm-product-line-thumb--empty{display:inline-flex;width:32px;height:32px;align-items:center;justify-content:center;background:#f3f4f6;border-radius:5px;border:1px solid #ccc;color:#9ca3af}
				.footer{margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:11px;text-align:center}
				@media print{
					body{margin:0}
					.sheet{padding:12px}
					.brand-bar{-webkit-print-color-adjust:exact;print-color-adjust:exact}
					th,.priority-inline,.ds-crm-product-line-thumb{-webkit-print-color-adjust:exact;print-color-adjust:exact}
					img{max-width:100%;-webkit-print-color-adjust:exact;print-color-adjust:exact}
				}
			</style></head><body>
			<div class="sheet">
				<div class="brand-bar">
					${logoHtml}
					<div class="brand-text">
						<h1>${this.escapeHtml(company)}</h1>
						${tagline ? `<p>${this.escapeHtml(tagline)}</p>` : ''}
					</div>
				</div>

				<h2 class="doc-title">Order ${this.escapeHtml(order.order_number || '—')}</h2>

				<div class="meta-grid">
					<div class="meta-card"><span class="meta-label">Client</span><span class="meta-value">${this.escapeHtml(order.client_name || '—')}${order.client_phone ? `<br /><span style="font-weight:400;color:#6b7280">${this.escapeHtml(order.client_phone)}</span>` : ''}</span></div>
					<div class="meta-card"><span class="meta-label">Order date</span><span class="meta-value">${formatDate(order.order_date)}</span></div>
					<div class="meta-card"><span class="meta-label">Status</span><span class="meta-value"><span class="status-pill">${this.escapeHtml(statusText)}</span></span></div>
					<div class="meta-card"><span class="meta-label">Created by</span><span class="meta-value">${this.escapeHtml(creator?.created_by_name || '—')}</span></div>
					<div class="meta-card"><span class="meta-label">Total weight</span><span class="meta-value">${totalWeight} kg</span></div>
					<div class="meta-card"><span class="meta-label">Line items</span><span class="meta-value">${(items || []).length}</span></div>
				</div>

				${order.notes ? `<div class="notes"><strong>Notes</strong><p style="margin:6px 0 0">${this.escapeHtml(order.notes)}</p></div>` : ''}

				<div class="section">
					<h2>Line items</h2>
					<table>
						<thead><tr><th>Product</th><th>Color / Size</th><th>Qty</th><th>Weight</th><th>Delivered</th><th>Due</th><th>Unit price</th><th>Line total</th></tr></thead>
						<tbody>${lineRows || '<tr><td colspan="8" class="empty">No line items.</td></tr>'}</tbody>
					</table>
				</div>

				<div class="section">
					<h2>Deliveries</h2>
					<table>
						<thead><tr><th>Date</th><th>Delivery #</th><th>Total KG</th><th>Shipping bill</th><th>Items</th></tr></thead>
						<tbody>${deliveryRows}</tbody>
					</table>
				</div>

				<div class="section">
					<h2>Payments</h2>
					<table>
						<thead><tr><th>Date</th><th>Payment #</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead>
						<tbody>${paymentRows}</tbody>
					</table>
				</div>

				<div class="totals">
					<div><span>Order bill</span><span>${formatAmount(summary?.order_bill || 0)}</span></div>
					<div><span>Shipping bill</span><span>${formatAmount(summary?.shipping_bill || 0)}</span></div>
					<div class="grand"><span>Total bill</span><span>${formatAmount(summary?.total_bill || 0)}</span></div>
					<div><span>Paid</span><span>${formatAmount(summary?.total_paid || 0)}</span></div>
					<div class="due"><span>Due</span><span>${formatAmount(summary?.total_due || 0)}</span></div>
				</div>

				<div class="footer">Printed ${formatDateTime(new Date().toISOString())}</div>
			</div>
			</body></html>`;

		const frame = document.createElement('iframe');
		frame.setAttribute('aria-hidden', 'true');
		frame.setAttribute('title', `Print order ${printFileName}`);
		// Off-screen but non-zero size so Safari actually loads/decodes images before print.
		frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:1200px;border:0;opacity:0;pointer-events:none;';
		document.body.appendChild(frame);

		const printWin = frame.contentWindow;
		const doc = frame.contentDocument || printWin?.document;
		if (!doc || !printWin) {
			frame.remove();
			this.toast('Unable to open print view.', 'error');
			return;
		}

		const originalTitle = document.title;
		const restoreTitle = () => {
			document.title = originalTitle;
		};

		doc.open();
		doc.write(html);
		doc.close();
		doc.title = printFileName;

		const cleanup = () => {
			restoreTitle();
			setTimeout(() => frame.remove(), 100);
		};

		const waitForImages = () => {
			const imgs = Array.from(doc.images || []);
			if (!imgs.length) {
				return Promise.resolve();
			}
			return Promise.all(
				imgs.map(
					(img) =>
						new Promise((resolve) => {
							let settled = false;
							const done = () => {
								if (settled) {
									return;
								}
								settled = true;
								resolve();
							};
							if (img.complete && img.naturalWidth > 0) {
								done();
								return;
							}
							img.addEventListener('load', done, { once: true });
							img.addEventListener('error', done, { once: true });
							// Safari often skips lazy/deferred loads inside hidden iframes — force a sync fetch.
							const src = img.getAttribute('src') || img.src;
							if (src) {
								img.src = '';
								img.src = src;
							}
							setTimeout(done, 4000);
						})
				)
			);
		};

		const runPrint = () => {
			try {
				document.title = printFileName;
				printWin.addEventListener('afterprint', cleanup, { once: true });
				printWin.focus();
				printWin.print();
				if (!('onafterprint' in printWin)) {
					setTimeout(cleanup, 2000);
				}
			} catch (error) {
				cleanup();
				this.toast('Print failed. Try again.', 'error');
			}
		};

		const startPrint = () => {
			waitForImages().then(() => {
				// Give Safari a short layout tick after images decode.
				setTimeout(runPrint, 150);
			});
		};

		if (printWin.document?.readyState === 'complete') {
			startPrint();
		} else {
			frame.onload = startPrint;
		}
	},

	toast(message, type = 'success') {
		let container = document.querySelector('.ds-crm-toast-container');
		if (!container) {
			container = document.createElement('div');
			container.className = 'ds-crm-toast-container';
			document.body.appendChild(container);
		}

		const el = document.createElement('div');
		el.className = `ds-crm-toast ds-crm-toast-${type}`;
		el.textContent = message;
		container.appendChild(el);

		setTimeout(() => {
			el.remove();
		}, 3500);
	},

	mountModal(modal) {
		if (!modal || modal.dataset.dsCrmMounted === '1') {
			return;
		}

		document.body.appendChild(modal);
		modal.dataset.dsCrmMounted = '1';
	},

	mountAllModals() {
		document.querySelectorAll('.ds-crm-modal').forEach((modal) => {
			this.mountModal(modal);
		});
	},

	positionAutocompleteList(listEl, anchorEl) {
		if (!listEl || !anchorEl) {
			return;
		}

		if (!listEl._dsCrmHome) {
			listEl._dsCrmHome = listEl.parentElement;
		}

		listEl.classList.add('ds-crm-autocomplete-floating');
		if (listEl.parentElement !== document.body) {
			document.body.appendChild(listEl);
		}

		const rect = anchorEl.getBoundingClientRect();
		const width = Math.max(rect.width, 260);
		let left = rect.left;
		const maxLeft = window.innerWidth - width - 12;

		if (left > maxLeft) {
			left = Math.max(12, maxLeft);
		}

		listEl.style.width = `${width}px`;
		listEl.style.left = `${left}px`;
		listEl.style.top = `${rect.bottom + 4}px`;
		listEl.hidden = false;
	},

	resetAutocompleteList(listEl) {
		if (!listEl) {
			return;
		}

		listEl.hidden = true;
		listEl.classList.remove('ds-crm-autocomplete-floating');
		listEl.style.width = '';
		listEl.style.left = '';
		listEl.style.top = '';

		if (listEl._dsCrmHome && listEl.parentElement === document.body) {
			listEl._dsCrmHome.appendChild(listEl);
		}
	},

	openModal(modal) {
		if (!modal) {
			return;
		}

		this.mountModal(modal);
		this.hideModalLoading(modal);
		modal.hidden = false;
		document.body.classList.add('ds-crm-modal-open');
	},

	showModalLoading(modal, label = 'Loading…') {
		if (!modal) {
			return;
		}

		this.mountModal(modal);
		modal.hidden = false;
		document.body.classList.add('ds-crm-modal-open');

		const dialog = modal.querySelector('.ds-crm-modal-dialog');
		if (!dialog) {
			return;
		}

		let overlay = dialog.querySelector('.ds-crm-modal-loading');
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.className = 'ds-crm-modal-loading';
			overlay.setAttribute('aria-live', 'polite');
			overlay.innerHTML =
				'<div class="ds-crm-spinner" role="status" aria-label="Loading"></div>' +
				'<p class="ds-crm-modal-loading-text"></p>';
			dialog.appendChild(overlay);
		}

		const textEl = overlay.querySelector('.ds-crm-modal-loading-text');
		if (textEl) {
			textEl.textContent = label;
		}

		overlay.hidden = false;
		dialog.classList.add('is-loading');
	},

	hideModalLoading(modal) {
		if (!modal) {
			return;
		}

		const dialog = modal.querySelector('.ds-crm-modal-dialog');
		dialog?.classList.remove('is-loading');
		const overlay = dialog?.querySelector('.ds-crm-modal-loading');
		if (overlay) {
			overlay.hidden = true;
		}
	},

	setButtonLoading(button, loading) {
		if (!button) {
			return;
		}

		if (loading) {
			if (!button.dataset.dsCrmLabel) {
				button.dataset.dsCrmLabel = button.textContent;
			}
			button.classList.add('is-loading');
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
		} else {
			button.classList.remove('is-loading');
			button.disabled = false;
			button.removeAttribute('aria-busy');
			if (button.dataset.dsCrmLabel) {
				button.textContent = button.dataset.dsCrmLabel;
				delete button.dataset.dsCrmLabel;
			}
		}
	},

	async runModalAction({ modal, trigger = null, label = 'Loading…', task }) {
		this.setButtonLoading(trigger, true);
		this.showModalLoading(modal, label);

		try {
			const keepOpen = await task();
			if (keepOpen === false) {
				this.closeModal(modal);
			}
		} catch (err) {
			this.closeModal(modal);
			this.toast('Failed to load. Please try again.', 'error');
		} finally {
			this.hideModalLoading(modal);
			this.setButtonLoading(trigger, false);
		}
	},

	closeModal(modal) {
		if (!modal) {
			return;
		}

		modal.hidden = true;
		document.querySelectorAll('.ds-crm-autocomplete-floating').forEach((listEl) => {
			this.resetAutocompleteList(listEl);
		});

		if (!document.querySelector('.ds-crm-modal:not([hidden])')) {
			document.body.classList.remove('ds-crm-modal-open');
		}
	},

	wireModal(modal) {
		if (!modal) {
			return;
		}

		const overlay = modal.querySelector('.ds-crm-modal-overlay');
		const closeButtons = modal.querySelectorAll('.ds-crm-modal-close, .ds-crm-modal-cancel');

		overlay?.addEventListener('click', () => this.closeModal(modal));
		closeButtons.forEach((btn) => {
			btn.addEventListener('click', () => this.closeModal(modal));
		});
	},

	/**
	 * Page numbers with ellipsis for large result sets (e.g. 1 … 4 5 6 … 20).
	 *
	 * @param {number} current
	 * @param {number} total
	 * @param {number} siblingCount
	 * @returns {(number|string)[]}
	 */
	buildPageRange(current, total, siblingCount = 1) {
		if (total <= 1) {
			return total === 1 ? [1] : [];
		}

		const pages = new Set([1, total]);
		for (let i = current - siblingCount; i <= current + siblingCount; i += 1) {
			if (i >= 1 && i <= total) {
				pages.add(i);
			}
		}

		const sorted = [...pages].sort((a, b) => a - b);
		const out = [];
		let prev = 0;

		sorted.forEach((page) => {
			if (prev && page - prev > 1) {
				out.push('…');
			}
			out.push(page);
			prev = page;
		});

		return out;
	},

	/**
	 * Render numbered pagination controls.
	 *
	 * @param {object} opts
	 * @param {HTMLElement|null} opts.pageNumbersEl
	 * @param {HTMLElement|null} opts.pageInfoEl
	 * @param {HTMLButtonElement|null} opts.prevBtn
	 * @param {HTMLButtonElement|null} opts.nextBtn
	 * @param {number} opts.page
	 * @param {number} opts.totalPages
	 * @param {number} opts.total
	 * @param {string} [opts.totalLabel]
	 */
	renderPagination({ pageNumbersEl, pageInfoEl, prevBtn, nextBtn, page, totalPages, total, totalLabel = 'events' }) {
		if (pageNumbersEl) {
			const range = this.buildPageRange(page, totalPages);
			pageNumbersEl.innerHTML = range
				.map((item) => {
					if (item === '…') {
						return '<span class="ds-crm-page-ellipsis" aria-hidden="true">…</span>';
					}
					const isActive = item === page;
					return `<button type="button" class="ds-crm-page-num${isActive ? ' is-active' : ''}" data-page="${item}"${isActive ? ' aria-current="page"' : ''}>${item}</button>`;
				})
				.join('');
		}

		if (pageInfoEl) {
			pageInfoEl.textContent = `Page ${page} of ${totalPages} (${total.toLocaleString()} ${totalLabel})`;
		}

		if (prevBtn) {
			prevBtn.disabled = page <= 1;
		}

		if (nextBtn) {
			nextBtn.disabled = page >= totalPages;
		}
	},

	ensureModuleSummary(root) {
		if (!root) {
			return null;
		}

		let el = root.querySelector('.ds-crm-module-summary');
		if (!el) {
			el = document.createElement('div');
			el.className = 'ds-crm-module-summary';
			el.hidden = true;
		}

		const header = root.querySelector('.ds-crm-page-header');
		if (header) {
			if (el.parentElement !== header.parentElement || header.nextElementSibling !== el) {
				header.insertAdjacentElement('afterend', el);
			}
		} else if (!el.parentElement) {
			root.prepend(el);
		}

		return el;
	},

	renderModuleSummary(root, cards, { onCardClick = null } = {}) {
		const el = this.ensureModuleSummary(root);
		if (!el) {
			return;
		}

		if (!cards?.length) {
			el.hidden = true;
			el.innerHTML = '';
			return;
		}

		el.hidden = false;
		el.className = 'ds-crm-module-summary ds-crm-module-summary--compact';
		el.innerHTML = `<div class="ds-crm-kpi-grid ds-crm-module-summary-grid">${cards
			.map((card, index) => {
				const tone = card.tone ? ` ds-crm-kpi-${card.tone}` : ' ds-crm-kpi-blue';
				const hasFilter = !!(card.filter_status || card.filter_tracking || card.filter_period);
				const clickable = hasFilter && onCardClick ? ' ds-crm-module-summary-card--clickable' : '';
				const filterStatus = card.filter_status ? ` data-filter-status="${this.escapeHtml(card.filter_status)}"` : '';
				const filterTracking = card.filter_tracking
					? ` data-filter-tracking="${this.escapeHtml(card.filter_tracking)}"`
					: '';
				const filterPeriod = card.filter_period
					? ` data-filter-period="${this.escapeHtml(card.filter_period)}"`
					: '';
				return `<button type="button" class="ds-crm-kpi-card${tone} ds-crm-module-summary-card${clickable}" data-summary-index="${index}"${filterStatus}${filterTracking}${filterPeriod}><div><span class="ds-crm-kpi-label">${this.escapeHtml(card.label)}</span><span class="ds-crm-kpi-value">${this.escapeHtml(card.value)}</span></div></button>`;
			})
			.join('')}</div>`;

		if (onCardClick) {
			el.querySelectorAll('[data-filter-status], [data-filter-tracking], [data-filter-period]').forEach((btn) => {
				btn.addEventListener('click', () =>
					onCardClick({
						status: btn.dataset.filterStatus || '',
						tracking: btn.dataset.filterTracking || '',
						period: btn.dataset.filterPeriod || '',
					})
				);
			});
		}
	},

	/**
	 * Client-side filter on items already loaded for the current page.
	 *
	 * @param {Array} items
	 * @param {object} options
	 * @return {Array}
	 */
	filterListItems(items, { clientId = '', query = '', getSearchText = null } = {}) {
		let result = Array.isArray(items) ? [...items] : [];

		if (clientId) {
			result = result.filter((item) => String(item.client_id || '') === String(clientId));
		}

		if (query && typeof getSearchText === 'function') {
			const q = query.toLowerCase();
			result = result.filter((item) => getSearchText(item).toLowerCase().includes(q));
		}

		return result;
	},

	renderFinancialOverview(root, financial, { title = 'Financial overview' } = {}) {
		if (!root || !financial) {
			return;
		}

		let el = root.querySelector('.ds-crm-financial-overview');
		if (!el) {
			el = document.createElement('section');
			el.className = 'ds-crm-financial-overview';
			const anchor = root.querySelector('.ds-crm-module-summary') || root.querySelector('.ds-crm-toolbar');
			if (anchor) {
				anchor.insertAdjacentElement('afterend', el);
			} else {
				root.prepend(el);
			}
		}

		const formatAmount = window.DsCrm?.formatAmount || ((v) => v);

		const renderCard = (label, value, tone, { kind = '' } = {}) => {
			const toneClass = tone ? ` ds-crm-kpi-${tone}` : ' ds-crm-kpi-blue';
			const kindClass = kind ? ` ds-crm-kpi-kind-${kind}` : '';
			return `<div class="ds-crm-kpi-card${toneClass}${kindClass}" data-kpi-kind="${this.escapeHtml(kind || 'metric')}"><div><span class="ds-crm-kpi-label">${this.escapeHtml(label)}</span><span class="ds-crm-kpi-value">${this.escapeHtml(formatAmount(value))}</span></div></div>`;
		};

		const dueTone = (amount) => (parseFloat(amount) > 0 ? 'rose' : 'slate');

		let cardsHtml =
			renderCard('Total bill', financial.total_bill, 'indigo', { kind: 'bill-total' }) +
			renderCard('Paid', financial.total_paid, 'green', { kind: 'paid-total' }) +
			renderCard('Due', financial.total_due, dueTone(financial.total_due), { kind: 'due-total' });

		if (financial.order_bill != null) {
			cardsHtml +=
				renderCard('Order bill', financial.order_bill, 'blue', { kind: 'bill-order' }) +
				renderCard('Order paid', financial.order_paid, 'teal', { kind: 'paid-order' }) +
				renderCard('Order due', financial.order_due, dueTone(financial.order_due) === 'rose' ? 'amber' : 'slate', {
					kind: 'due-order',
				}) +
				renderCard('Delivery bill', financial.delivery_bill, 'purple', { kind: 'bill-delivery' }) +
				renderCard('Delivery paid', financial.delivery_paid, 'cyan', { kind: 'paid-delivery' }) +
				renderCard('Delivery due', financial.delivery_due, dueTone(financial.delivery_due) === 'rose' ? 'orange' : 'slate', {
					kind: 'due-delivery',
				});
		} else if (financial.shipping_bill != null) {
			cardsHtml =
				renderCard('Total bill', financial.total_bill, 'indigo', { kind: 'bill-total' }) +
				renderCard('Receive shipping', financial.shipping_bill, 'sky', { kind: 'bill-shipping' }) +
				(financial.manual_bills != null
					? renderCard('Manual bills', financial.manual_bills, 'amber', { kind: 'bill-manual' })
					: '') +
				renderCard('Paid', financial.total_paid, 'green', { kind: 'paid-total' }) +
				renderCard('Due', financial.total_due, dueTone(financial.total_due), { kind: 'due-total' });
		}

		el.innerHTML = `
			<h2 class="ds-crm-financial-overview-title">${this.escapeHtml(title)}</h2>
			<div class="ds-crm-kpi-grid ds-crm-financial-overview-grid">${cardsHtml}</div>`;
	},
};

const wireProductPicker = (pickerEl, options = {}) => {
	if (!pickerEl) {
		return;
	}

	const { postAjax, debounce, formatAmount } = window.DsCrm;
	const ajaxAction = options.ajaxAction || 'crm_products_search';
	const minSearchLength = options.minSearchLength || 2;
	const allowNewProduct = Boolean(options.allowNewProduct);
	const idInput = pickerEl.querySelector('.line-product-id');
	const nameInput = pickerEl.querySelector('.line-product-name');
	const selectedEl = pickerEl.querySelector('.ds-crm-selected-product');
	const suggestions = pickerEl.querySelector('.line-product-suggestions');

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const hideSuggestions = () => {
		DsCrmUI.resetAutocompleteList(suggestions);
	};

	const showHint = (message, anchorEl = nameInput) => {
		if (!suggestions || !anchorEl) {
			return;
		}

		suggestions.innerHTML = `<div class="ds-crm-autocomplete-hint">${escapeHtml(message)}</div>`;
		DsCrmUI.positionAutocompleteList(suggestions, anchorEl);
	};

	const searchWrap = pickerEl.querySelector('.ds-crm-picker-search');
	const newPanel = pickerEl.querySelector('.ds-crm-line-new-product');

	const resetNewProductFields = () => {
		if (!newPanel) {
			return;
		}
		const fileInput = newPanel.querySelector('.line-product-image');
		if (fileInput) {
			fileInput.value = '';
		}
		const preview = newPanel.querySelector('.line-product-image-preview');
		if (preview) {
			preview.hidden = true;
			preview.removeAttribute('src');
		}
		const category = newPanel.querySelector('.line-product-category');
		if (category) {
			category.selectedIndex = 0;
		}
		const newNameEl = newPanel.querySelector('.ds-crm-new-product-name');
		if (newNameEl) {
			newNameEl.value = '';
		}
	};

	const setPickerMode = (mode) => {
		if (!allowNewProduct) {
			return;
		}
		pickerEl.dataset.pickerMode = mode;

		if (mode === 'search') {
			delete pickerEl.dataset.newProduct;
			searchWrap?.removeAttribute('hidden');
			if (selectedEl) {
				selectedEl.hidden = true;
				selectedEl.textContent = '';
			}
			newPanel?.setAttribute('hidden', '');
			resetNewProductFields();
			return;
		}

		if (mode === 'catalog') {
			delete pickerEl.dataset.newProduct;
			searchWrap?.setAttribute('hidden', '');
			newPanel?.setAttribute('hidden', '');
			resetNewProductFields();
			return;
		}

		if (mode === 'new') {
			pickerEl.dataset.newProduct = '1';
			searchWrap?.setAttribute('hidden', '');
			if (selectedEl) {
				selectedEl.hidden = true;
				selectedEl.textContent = '';
			}
			if (idInput) {
				idInput.value = '0';
			}
			newPanel?.removeAttribute('hidden');
		}
	};

	const clearProduct = () => {
		if (idInput) {
			idInput.value = '0';
		}
		if (nameInput) {
			nameInput.value = '';
			nameInput.hidden = false;
		}
		setPickerMode('search');
		hideSuggestions();
	};

	const selectProduct = (product) => {
		if (!product?.id) {
			return;
		}

		setPickerMode('catalog');

		if (idInput) {
			idInput.value = String(product.id);
		}
		if (nameInput) {
			nameInput.value = product.name || '';
			nameInput.hidden = true;
		}
		if (selectedEl) {
			selectedEl.hidden = false;
			const label = options.selectedLabel
				? options.selectedLabel(product)
				: escapeHtml(product.name);
			selectedEl.innerHTML = `${label} <button type="button" class="button-link ds-crm-clear-product">Change</button>`;
			selectedEl.querySelector('.ds-crm-clear-product')?.addEventListener('click', clearProduct);
		}
		hideSuggestions();
		if (typeof options.onSelect === 'function') {
			options.onSelect(product, pickerEl);
		}
	};

	const renderSuggestions = (items, hasMore = false) => {
		if (!suggestions || !items.length) {
			hideSuggestions();
			return;
		}

		const rows = items
			.map((product) => {
				const label = options.formatSuggestion
					? options.formatSuggestion(product)
					: product.sku
						? `${escapeHtml(product.name)} · ${escapeHtml(product.sku)} — ${formatAmount(product.unit_price)}`
						: `${escapeHtml(product.name)} — ${formatAmount(product.unit_price)}`;
				return `<button type="button" class="ds-crm-suggestion" data-product-id="${product.id}">${label}</button>`;
			})
			.join('');

		const moreHint = hasMore
			? `<div class="ds-crm-autocomplete-hint ds-crm-autocomplete-more">Keep typing to narrow results…</div>`
			: '';

		suggestions.innerHTML = rows + moreHint;
		DsCrmUI.positionAutocompleteList(suggestions, nameInput);
	};

	const loadSuggestions = async (term = '') => {
		const query = term.trim();

		if (query.length < minSearchLength) {
			showHint(`Type at least ${minSearchLength} characters to search products…`);
			return;
		}

		showHint('Searching…');

		const result = await postAjax(ajaxAction, { search: query });
		if (!result.success) {
			showHint('Could not load products. Try again.');
			return;
		}

		if (result.data?.hint && !result.data.items?.length) {
			showHint(result.data.hint);
			return;
		}

		const items = result.data.items || [];

		if (!items.length) {
			if (allowNewProduct && query.length >= minSearchLength) {
				suggestions.innerHTML = `<button type="button" class="ds-crm-suggestion ds-crm-suggestion-new-product" data-new-name="${escapeHtml(query)}">+ Add “${escapeHtml(query)}” as new product</button>`;
				DsCrmUI.positionAutocompleteList(suggestions, nameInput);
				return;
			}
			showHint(allowNewProduct ? 'No match — type a name and add as new product.' : 'No products found. Add it in Products first.');
			return;
		}

		pickerEl._productCache = items.reduce((map, item) => {
			map[String(item.id)] = item;
			return map;
		}, pickerEl._productCache || {});

		renderSuggestions(items, Boolean(result.data.has_more));

		if (allowNewProduct && query.length >= minSearchLength) {
			const exact = items.find((p) => String(p.name).toLowerCase() === query.toLowerCase());
			if (!exact) {
				suggestions.insertAdjacentHTML(
					'beforeend',
					`<button type="button" class="ds-crm-suggestion ds-crm-suggestion-new-product" data-new-name="${escapeHtml(query)}">+ Add “${escapeHtml(query)}” as new product</button>`
				);
			}
		}
	};

	nameInput?.addEventListener('focus', () => {
		const term = nameInput.value.trim();
		if (term.length >= minSearchLength) {
			loadSuggestions(term);
		} else {
			showHint(`Type at least ${minSearchLength} characters to search products…`);
		}
	});

	nameInput?.addEventListener(
		'input',
		debounce(() => {
			loadSuggestions(nameInput.value);
		}, 300)
	);

	suggestions?.addEventListener('click', (event) => {
		const newBtn = event.target.closest('.ds-crm-suggestion-new-product');
		if (newBtn) {
			const term = newBtn.dataset.newName || nameInput?.value.trim() || '';
			if (nameInput) {
				nameInput.value = term;
			}
			setPickerMode('new');
			const newNameEl = newPanel?.querySelector('.ds-crm-new-product-name');
			if (newNameEl) {
				newNameEl.value = term;
			}
			hideSuggestions();
			if (typeof options.onNewProduct === 'function') {
				options.onNewProduct(term, pickerEl);
			}
			return;
		}

		const btn = event.target.closest('.ds-crm-suggestion');
		if (!btn) {
			return;
		}
		const product = pickerEl._productCache?.[btn.dataset.productId];
		if (product) {
			selectProduct(product);
		}
	});

	document.addEventListener('click', (event) => {
		if (!pickerEl.contains(event.target) && !suggestions?.contains(event.target)) {
			hideSuggestions();
		}
	});

	window.addEventListener(
		'resize',
		debounce(() => {
			if (suggestions && !suggestions.hidden && nameInput) {
				DsCrmUI.positionAutocompleteList(suggestions, nameInput);
			}
		}, 100)
	);

	document.addEventListener(
		'scroll',
		() => {
			if (suggestions && !suggestions.hidden && nameInput) {
				DsCrmUI.positionAutocompleteList(suggestions, nameInput);
			}
		},
		true
	);

	pickerEl._clearProduct = clearProduct;
	pickerEl._selectProduct = selectProduct;
	if (allowNewProduct) {
		pickerEl._setPickerMode = setPickerMode;
		setPickerMode('search');
	}
};

wireProductPicker.isValid = (pickerEl) => parseInt(pickerEl?.querySelector('.line-product-id')?.value, 10) > 0;

wireProductPicker.reset = (pickerEl) => {
	if (pickerEl?._clearProduct) {
		pickerEl._clearProduct();
	}
};

const buildModuleUrl = (moduleSlug, extraParams = {}) => {
	const params = { crm_module: moduleSlug, ...extraParams };
	const base = window.DsCrmApp?.moduleBaseUrl || '';

	if (!base) {
		const qs = new URLSearchParams();
		Object.entries(params).forEach(([key, value]) => {
			if (value !== undefined && value !== null && value !== '') {
				qs.set(key, String(value));
			}
		});
		return `${window.location.origin}${window.location.pathname}?${qs.toString()}`;
	}

	try {
		const url = new URL(base, window.location.origin);
		Object.entries(params).forEach(([key, value]) => {
			if (value !== undefined && value !== null && value !== '') {
				url.searchParams.set(key, String(value));
			}
		});
		return url.toString();
	} catch {
		const sep = base.includes('?') ? '&' : '?';
		const qs = Object.entries(params)
			.filter(([, value]) => value !== undefined && value !== null && value !== '')
			.map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
			.join('&');
		return `${base}${sep}${qs}`;
	}
};

window.DsCrm = {
	postAjax,
	postAjaxForm,
	debounce,
	formatAmount,
	formatWeight,
	parseWeight,
	sumWeights,
	allocateByWeight,
	formatDate,
	formatDateTime,
	formatListDateTime,
	formatTrackingTime,
	buildModuleUrl,
	clearAjaxGetCache,
	DsCrmUI,
	wireProductPicker,
};

document.addEventListener('DOMContentLoaded', () => {
	DsCrmUI.mountAllModals();
	DsCrmUI.wireProductImageLightbox();

	const app = document.querySelector('.ds-crm-app');
	const toggle = app?.querySelector('.ds-crm-nav-toggle');
	const backdrop = app?.querySelector('.ds-crm-nav-backdrop');
	if (!app || !toggle) {
		return;
	}

	const setNavOpen = (open) => {
		app.classList.toggle('is-nav-open', open);
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
		document.body.classList.toggle('ds-crm-nav-open', open);
	};

	toggle.addEventListener('click', () => {
		setNavOpen(!app.classList.contains('is-nav-open'));
	});

	backdrop?.addEventListener('click', () => setNavOpen(false));

	app.querySelectorAll('.ds-crm-sidebar a').forEach((link) => {
		link.addEventListener('click', () => setNavOpen(false));
	});

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && app.classList.contains('is-nav-open')) {
			setNavOpen(false);
		}
	});

	window.addEventListener(
		'resize',
		() => {
			if (window.matchMedia('(min-width: 901px)').matches) {
				setNavOpen(false);
			}
		},
		{ passive: true }
	);
});
