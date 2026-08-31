/* global DsCrm, DsCrmApp */

(() => {
	const root = document.querySelector('[data-crm-module="orders-view"]');
	if (!root) {
		return;
	}

	const { postAjax, formatDate, formatDateTime, formatAmount, formatWeight, sumWeights, DsCrmUI, buildModuleUrl } = DsCrm;
	const orderId = parseInt(root.dataset.orderId, 10) || 0;
	const canManageStatus = root.dataset.canStatus === '1';
	const loadingEl = root.querySelector('.ds-crm-order-view-loading');
	const contentEl = root.querySelector('.ds-crm-order-view-content');
	const viewToolbar = root.querySelector('.ds-crm-order-view-toolbar');
	const viewMeta = root.querySelector('.ds-crm-order-view-meta');
	const viewItemsBody = root.querySelector('.ds-crm-order-view-items tbody');
	const viewSummary = root.querySelector('.ds-crm-order-view-summary');
	const trackingSection = root.querySelector('.ds-crm-order-tracking-section');
	const trackingSummary = root.querySelector('.ds-crm-order-tracking-summary');
	const trackingTimeline = root.querySelector('.ds-crm-order-tracking-timeline');
	const statusRow = root.querySelector('.ds-crm-order-status-row');
	const statusSelect = root.querySelector('.ds-crm-order-status-select');

	let statusMap = {};
	let lastViewData = null;

	const escapeHtml = (str) => {
		const div = document.createElement('div');
		div.textContent = str ?? '';
		return div.innerHTML;
	};

	const orderFormUrl = (id) => buildModuleUrl('orders', { order_action: 'edit', order_id: id });
	const listUrl = buildModuleUrl('orders');

	const statusLabel = (slug) => statusMap[slug]?.label || slug;

	const loadStatusMap = async () => {
		const res = await postAjax('crm_orders_list_filters');
		if (!res.success) {
			return;
		}
		(res.data.statuses || []).forEach((s) => {
			statusMap[s.slug] = s;
		});
	};

	const printOrderData = (data) => {
		if (!data?.order) {
			return;
		}
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

	const ORDER_VIEW_COLUMN_LABELS = {
		product: 'Product',
		priority: 'Priority',
		variant: 'Color / Size',
		quantity: 'Ordered',
		accepted: 'Accepted',
		weight: 'Weight kg',
		delivered: 'Delivered',
		due: 'Due',
		exported: 'On the way',
		to_export: 'To export',
		unit_price: 'Unit price',
		line_total: 'Line total',
	};

	const orderViewColumnLabel = (key, isClient = false) => {
		if (isClient && key === 'exported') {
			return 'On the way';
		}
		if (isClient && key === 'weight') {
			return 'Weight kg';
		}
		return ORDER_VIEW_COLUMN_LABELS[key] || key;
	};

	const renderOrderLineItemsHead = (columns, isClient = false) => {
		const headRow = root.querySelector('.ds-crm-order-view-items thead tr');
		if (!headRow) {
			return;
		}
		headRow.innerHTML = (columns || [])
			.map((key) => `<th>${escapeHtml(orderViewColumnLabel(key, isClient))}</th>`)
			.join('');
	};

	const lineAcceptedQty = (item) => {
		if (item.accepted_quantity != null && item.accepted_quantity !== '') {
			return parseInt(item.accepted_quantity, 10) || 0;
		}
		if (item.qty_accepted != null) {
			return parseInt(item.qty_accepted, 10) || 0;
		}
		return parseInt(item.quantity, 10) || 0;
	};

	const renderOrderLineCell = (item, column) => {
		switch (column) {
			case 'product':
				return `<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, {
					fullImageUrl: item.product_full_image_url || item.product_image_url,
				})}</td>`;
			case 'priority':
				return `<td>${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority) || '—'}</td>`;
			case 'variant':
				return `<td>${escapeHtml([item.color, item.size].filter(Boolean).join(' / ') || '—')}</td>`;
			case 'quantity':
				return `<td>${item.quantity}</td>`;
			case 'accepted':
				return `<td><strong>${lineAcceptedQty(item)}</strong></td>`;
			case 'weight': {
				const shippedWeight = parseFloat(item.weight_exported_kg);
				if (!Number.isNaN(shippedWeight) && shippedWeight > 0) {
					return `<td>${formatWeight(shippedWeight)}</td>`;
				}
				// Before China submits this line, show estimated line weight when available.
				if ((parseInt(item.qty_exported, 10) || 0) < 1) {
					const lineWeight = parseFloat(item.weight_kg);
					if (!Number.isNaN(lineWeight) && lineWeight > 0) {
						return `<td>${formatWeight(lineWeight)}</td>`;
					}
				}
				return '<td>—</td>';
			}
			case 'delivered':
				return `<td>${item.qty_delivered}</td>`;
			case 'due':
				return `<td>${item.qty_due}</td>`;
			case 'exported':
				return `<td>${item.qty_exported ?? 0}</td>`;
			case 'to_export':
				return `<td>${item.qty_to_export ?? 0}</td>`;
			case 'unit_price':
				return `<td>${formatAmount(item.unit_price)}</td>`;
			case 'line_total':
				return `<td>${formatAmount(lineAcceptedQty(item) * (parseFloat(item.unit_price) || 0))}</td>`;
			default:
				return '<td>—</td>';
		}
	};

	const renderOrderLineItemsFoot = (columns, items, summary) => {
		const tfoot = root.querySelector('.ds-crm-order-view-items tfoot');
		const totalRow = tfoot?.querySelector('.ds-crm-order-lines-total');
		if (!tfoot || !totalRow) {
			return;
		}

		const totalColIndex = (columns || []).indexOf('line_total');
		if (totalColIndex < 0 || !items?.length) {
			tfoot.hidden = true;
			return;
		}

		const grandTotal =
			summary?.order_bill != null
				? parseFloat(summary.order_bill)
				: items.reduce(
						(sum, item) => sum + (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0),
						0
					);
		const colspanBefore = totalColIndex;
		const colspanAfter = columns.length - totalColIndex - 1;

		tfoot.hidden = false;
		totalRow.innerHTML = `
			<td colspan="${colspanBefore}" class="ds-crm-order-total-label">Grand total</td>
			<td class="ds-crm-order-total-value">${formatAmount(grandTotal)}</td>
			${colspanAfter > 0 ? `<td colspan="${colspanAfter}"></td>` : ''}`;
	};

	const formatTrackingTimeHtml = (time) => {
		if (!time || !time.primary) {
			return '';
		}
		const primaryLabel = time.primary_label || (DsCrmApp?.timezone?.isChinaOffice ? DsCrmApp.timezone.chinaLabel : DsCrmApp?.timezone?.bangladeshLabel) || '';
		const secondaryLabel = time.secondary_label || (DsCrmApp?.timezone?.isChinaOffice ? DsCrmApp?.timezone?.bangladeshLabel : DsCrmApp?.timezone?.chinaLabel) || '';
		const secondary =
			time.dual && time.secondary
				? `<span class="ds-crm-order-tracking-time-secondary">${escapeHtml(secondaryLabel)}: ${escapeHtml(time.secondary)}</span>`
				: '';
		return `<time class="ds-crm-order-tracking-time" datetime="${escapeHtml(time.raw || '')}">
			<span class="ds-crm-order-tracking-time-primary">${escapeHtml(primaryLabel)}: ${escapeHtml(time.primary)}</span>
			${secondary}
		</time>`;
	};

	const renderTracking = (tracking, needsPricing) => {
		if (!trackingSection || !tracking) {
			return;
		}

		trackingSection.hidden = false;
		const tone = tracking.tone || 'info';
		const metrics = tracking.metrics || {};
		const isClient = tracking.audience === 'client';
		const qtyBasis = metrics.qty_accepted > 0 ? metrics.qty_accepted : metrics.qty_ordered ?? 0;
		const exportLabel = isClient ? 'submitted to BD' : 'exported';
		const pricingFlag = isClient
			? 'China office is confirming supply'
			: 'Prices needed for supply confirmation';

		trackingSummary.innerHTML = `
			<div class="ds-crm-order-tracking-now ds-crm-order-tracking--${escapeHtml(tone)}">
				<strong class="ds-crm-order-tracking-now-label">${escapeHtml(tracking.short_label || '')}</strong>
				${tracking.short_detail ? `<span class="ds-crm-order-tracking-now-detail">${escapeHtml(tracking.short_detail)}</span>` : ''}
			</div>
			<div class="ds-crm-order-tracking-metrics">
				<span>${metrics.qty_accepted ?? 0} / ${metrics.qty_ordered ?? 0} accepted</span>
				<span>${metrics.qty_exported ?? 0} / ${qtyBasis} ${exportLabel}</span>
				<span>${metrics.qty_delivered ?? 0} / ${qtyBasis} delivered</span>
				${needsPricing ? `<span class="ds-crm-order-tracking-pricing-flag">${pricingFlag}</span>` : ''}
			</div>`;

		const steps = tracking.steps || [];
		trackingTimeline.innerHTML = steps
			.map((step) => {
				const state = step.state || 'pending';
				const legs = Array.isArray(step.legs) ? step.legs : [];
				const legsHtml = legs.length
					? `<ul class="ds-crm-order-tracking-legs">${legs
							.map((leg) => {
								const meta = [
									leg.shipment_number,
									!isClient ? leg.company : '',
									leg.ship_date ? formatDate(leg.ship_date) : '',
								]
									.filter(Boolean)
									.join(' · ');
								const notes =
									!isClient && leg.notes
										? `<span class="ds-crm-order-tracking-step-desc"><strong>Notes:</strong> ${escapeHtml(leg.notes)}</span>`
										: '';
								return `<li class="ds-crm-order-tracking-leg">
									<strong>${escapeHtml(leg.label || `${leg.qty_total || 0} pcs`)}</strong>
									${meta ? `<span class="ds-crm-order-tracking-step-desc">${escapeHtml(meta)}</span>` : ''}
									${notes}
									${formatTrackingTimeHtml(leg.time)}
								</li>`;
							})
							.join('')}</ul>`
					: '';
				return `<li class="ds-crm-order-tracking-step ds-crm-order-tracking-step--${escapeHtml(state)}">
					<span class="ds-crm-order-tracking-step-marker" aria-hidden="true"></span>
					<div class="ds-crm-order-tracking-step-body">
						<strong>${escapeHtml(step.label || '')}</strong>
						${step.desc ? `<span class="ds-crm-order-tracking-step-desc">${escapeHtml(step.desc)}</span>` : ''}
						${formatTrackingTimeHtml(step.time)}
						${legsHtml}
					</div>
				</li>`;
			})
			.join('');

		// Non-export events only (exports already shown as legs under Submitted to Bangladesh).
		const events = (tracking.events || []).filter((ev) => ev.key !== 'export');
		if (events.length && trackingTimeline) {
			const eventsHtml = events
				.map(
					(ev) => `<li class="ds-crm-order-tracking-event">
					<strong>${escapeHtml(ev.label || '')}</strong>
					${ev.detail ? `<span class="ds-crm-order-tracking-step-desc">${escapeHtml(ev.detail)}</span>` : ''}
					${formatTrackingTimeHtml(ev.time)}
				</li>`
				)
				.join('');
			trackingTimeline.insertAdjacentHTML(
				'beforeend',
				`<li class="ds-crm-order-tracking-events-wrap">
					<strong class="ds-crm-order-tracking-events-title">Key dates</strong>
					<ul class="ds-crm-order-tracking-events">${eventsHtml}</ul>
				</li>`
			);
		}
	};

	const renderOrderMeta = ({ order, creator, viewUi, workflowBlocked, editBlockedReason, totalWeight, totalWeightLabel, needsPricing }) => {
		const meta = viewUi?.meta || {};
		const isClient = viewUi?.mode === 'client';
		const metaItems = [];

		if (meta.client) {
			metaItems.push(`
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Client</span>
					<span class="ds-crm-meta-value">${escapeHtml(order.client_name || '—')}${order.client_phone ? `<span class="ds-crm-meta-muted"> (${escapeHtml(order.client_phone)})</span>` : ''}</span>
				</div>`);
		}

		if (meta.date) {
			metaItems.push(`
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Date</span>
					<span class="ds-crm-meta-value">${formatDate(order.order_date)}</span>
				</div>`);
		}

		if (meta.status) {
			metaItems.push(`
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Status</span>
					<span class="ds-crm-meta-value"><span class="ds-crm-badge ds-crm-badge-${escapeHtml(order.status)}">${escapeHtml(statusLabel(order.status))}</span></span>
				</div>`);
		}

		if (meta.created_by) {
			metaItems.push(`
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">Created by</span>
					<span class="ds-crm-meta-value">${escapeHtml(creator?.created_by_name || '—')}${creator?.is_mine ? ' <span class="ds-crm-badge ds-crm-badge--custom">You</span>' : ''}</span>
				</div>`);
		}

		if (meta.total_weight && parseFloat(totalWeight) > 0) {
			metaItems.push(`
				<div class="ds-crm-meta-item">
					<span class="ds-crm-meta-label">${escapeHtml(totalWeightLabel || 'Total weight')}</span>
					<span class="ds-crm-meta-value">${totalWeight} kg</span>
				</div>`);
		}

		let workflowNotice = '';
		if (viewUi?.sections?.workflow && workflowBlocked) {
			if (needsPricing) {
				workflowNotice = isClient
					? '<div class="ds-crm-notice ds-crm-notice-warning ds-crm-order-accept-notice">Your order is awaiting pricing and approval from the China office. You can still edit or cancel until it is accepted.</div>'
					: '<div class="ds-crm-notice ds-crm-notice-warning ds-crm-order-accept-notice">Set unit prices on every line, then accept this order to enable export, delivery, and billing.</div>';
			} else if (isClient) {
				workflowNotice =
					'<div class="ds-crm-notice ds-crm-notice-warning ds-crm-order-accept-notice">Your order is awaiting approval. You can edit or cancel it until it is accepted.</div>';
			} else {
				workflowNotice =
					'<div class="ds-crm-notice ds-crm-notice-warning ds-crm-order-accept-notice">This order is waiting for approval. Export, delivery, and billing stay disabled until it is accepted.</div>';
			}
		}

		const lockedNotice = editBlockedReason
			? `<div class="ds-crm-notice ds-crm-notice-info ds-crm-order-edit-locked-notice">${escapeHtml(editBlockedReason)}</div>`
			: '';

		const notesBlock =
			meta.notes && order.notes
				? `<div class="ds-crm-order-notes"><span class="ds-crm-meta-label">Notes</span><p>${escapeHtml(order.notes)}</p></div>`
				: '';
		const approvalNoteBlock =
			(order.approval_note || '').trim()
				? `<div class="ds-crm-order-notes"><span class="ds-crm-meta-label">Approval note</span><p>${escapeHtml(order.approval_note)}</p></div>`
				: '';

		return `${workflowNotice}${lockedNotice}<div class="ds-crm-order-meta-grid">${metaItems.join('')}</div>${notesBlock}${approvalNoteBlock}`;
	};

	const renderOrderSummarySections = ({
		viewUi,
		deliveries,
		exportShipments,
		exportRemaining,
		payments,
		summary,
		history,
		workflowBlocked,
		canDeliver,
		canRecordExport,
		canRecordPayment,
		shipmentFormUrl,
		deliveryUrl,
		paymentUrl,
	}) => {
		const sections = viewUi?.sections || {};
		const parts = [];
		const statCard = (label, value, extraClass = '') =>
			`<div class="ds-crm-stat-card ${extraClass}"><span class="ds-crm-stat-label">${label}</span><span class="ds-crm-stat-value">${value}</span></div>`;

		if (sections.china_export) {
			const isClient = viewUi.mode === 'client';
			const rem = exportRemaining || {};
			const remainingHtml =
				rem.qty_accepted > 0
					? isClient
						? `<p class="description ds-crm-export-remaining-summary">Accepted ${rem.qty_accepted} · on the way ${rem.qty_exported || 0}${
								(rem.qty_remaining || 0) > 0 ? ` · still pending <strong>${rem.qty_remaining || 0}</strong>` : ''
							}</p>`
						: `<p class="description ds-crm-export-remaining-summary">Accepted ${rem.qty_accepted} · shipped ${rem.qty_exported || 0} · still to ship <strong>${rem.qty_remaining || 0}</strong></p>`
					: '';
			const shipmentRows =
				exportShipments && exportShipments.length
					? exportShipments
							.map((s) => {
								const when = formatDate(s.ship_date);
								const items = s.items || [];
								const totalSupplied = items.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0);
								const totalWeight = sumWeights(items.map((item) => item.weight_kg));
								const linesHtml = items
									.map((item) => {
										const variant = [item.color, item.size].filter(Boolean).join(' / ') || '—';
										const accepted =
											item.accepted_quantity != null && item.accepted_quantity !== ''
												? item.accepted_quantity
												: item.qty_accepted != null
													? item.qty_accepted
													: item.qty_ordered || 0;
										if (isClient) {
											return `<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}">
												<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
												<td>${escapeHtml(variant)}</td>
												<td><strong>${accepted}</strong></td>
												<td><strong>${item.quantity}</strong></td>
												<td>${formatWeight(item.weight_kg)}</td>
											</tr>`;
										}
										return `<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}">
											<td>${DsCrmUI.productCell(item.product_name, item.product_image_url, { size: 'sm', fullImageUrl: item.product_full_image_url || item.product_image_url })}</td>
											<td class="ds-crm-priority-signal-cell">${DsCrmUI.deliveryPrioritySignalHtml(item.delivery_priority) || '—'}</td>
											<td>${escapeHtml(variant)}</td>
											<td>${item.qty_ordered ?? '—'}</td>
											<td><strong>${accepted}</strong></td>
											<td><strong>${item.quantity}</strong></td>
											<td>${formatWeight(item.weight_kg)}</td>
										</tr>`;
									})
									.join('');
								const metaHtml = isClient
									? `<div class="ds-crm-shipment-history-batch-meta">
											<span><strong>Submitted:</strong> ${escapeHtml(when)}</span>
											<span><strong>Weight:</strong> ${escapeHtml(formatWeight(s.total_kg, { withUnit: true }))}</span>
										</div>`
									: `<div class="ds-crm-shipment-history-batch-meta">
											<span><strong>Company:</strong> ${escapeHtml(s.company_name || '—')}</span>
											<span><strong>Ship date:</strong> ${escapeHtml(when)}</span>
											<span><strong>Weight:</strong> ${escapeHtml(formatWeight(s.total_kg, { withUnit: true }))}</span>
										</div>
										${s.notes ? `<p class="ds-crm-shipment-history-notes"><strong>Notes:</strong> ${escapeHtml(s.notes)}</p>` : ''}`;
								const headHtml = isClient
									? `<thead>
												<tr>
													<th>Product</th>
													<th>Color / Size</th>
													<th>Accepted</th>
													<th>On the way</th>
													<th>Weight kg</th>
												</tr>
											</thead>`
									: `<thead>
												<tr>
													<th>Product</th>
													<th>Priority</th>
													<th>Color / Size</th>
													<th>Ordered</th>
													<th>Accepted</th>
													<th>Supplied</th>
													<th>Weight</th>
												</tr>
											</thead>`;
								const footHtml = linesHtml
									? isClient
										? `<tfoot>
												<tr class="ds-crm-order-lines-total ds-crm-shipment-history-lines-total">
													<td colspan="3" class="ds-crm-order-total-label">Total</td>
													<td class="ds-crm-order-total-value"><strong>${totalSupplied}</strong></td>
													<td class="ds-crm-order-total-value">${formatWeight(totalWeight)}</td>
												</tr>
											</tfoot>`
										: `<tfoot>
												<tr class="ds-crm-order-lines-total ds-crm-shipment-history-lines-total">
													<td colspan="5" class="ds-crm-order-total-label">Total</td>
													<td class="ds-crm-order-total-value"><strong>${totalSupplied}</strong></td>
													<td class="ds-crm-order-total-value">${formatWeight(totalWeight)}</td>
												</tr>
											</tfoot>`
									: '';
								const emptyColspan = isClient ? 5 : 7;
								return `<article class="ds-crm-shipment-history-batch ds-crm-order-export-batch">
									<div class="ds-crm-shipment-history-batch-head">
										<div class="ds-crm-shipment-history-batch-title">
											<strong>${escapeHtml(isClient ? `Shipment ${when}` : s.shipment_number || '—')}</strong>
											<span class="ds-crm-badge ds-crm-badge-info">${escapeHtml(String(s.qty_total || 0))} pcs</span>
										</div>
										${metaHtml}
									</div>
									<div class="ds-crm-table-wrap">
										<table class="ds-crm-table ds-crm-shipment-history-lines-table">
											${headHtml}
											<tbody>${linesHtml || `<tr><td colspan="${emptyColspan}" class="ds-crm-empty">No products.</td></tr>`}</tbody>
											${footHtml}
										</table>
									</div>
								</article>`;
							})
							.join('')
					: `<p class="ds-crm-order-payments-empty">${
							isClient
								? 'No products have been submitted from China yet.'
								: 'No supply shipments recorded yet.'
						}</p>`;

			const remainingLeft = (rem.qty_remaining || 0) > 0;
			const exportAction = isClient
				? ''
				: canRecordExport
					? `<a class="button${remainingLeft ? ' button-primary' : ''}" href="${shipmentFormUrl}">${
							remainingLeft ? 'Continue supply' : 'Open China workspace'
						}</a>`
					: workflowBlocked
						? '<span class="description">Export is disabled until this order is accepted.</span>'
						: '<span class="description">You cannot record export shipments.</span>';

			parts.push(`
			<section class="ds-crm-order-view-section">
				<h2 class="ds-crm-order-view-section-title">${
					isClient
						? 'From China — approved & on the way'
						: `${escapeHtml(DsCrmApp?.moduleLabels?.shipments || 'China export')} — supply history`
				}</h2>
				${remainingHtml}
				<div class="ds-crm-order-export-batches">${shipmentRows}</div>
				${exportAction ? `<p class="ds-crm-order-view-actions">${exportAction}</p>` : ''}
			</section>`);
		}

		if (sections.deliveries) {
			const deliveryRows =
				deliveries && deliveries.length
					? `<ul class="ds-crm-order-payment-list">${deliveries
							.map((d) => {
								const detail =
									viewUi.mode === 'client'
										? `${d.item_count} item${d.item_count === 1 ? '' : 's'}`
										: `${formatWeight(d.total_kg, { withUnit: true })} · ${formatAmount(d.shipping_bill)}`;
								return `<li><span class="ds-crm-order-payment-date">${formatDate(d.delivery_date)}</span><span class="ds-crm-order-payment-amount">${detail}</span><span class="ds-crm-order-payment-ref">${escapeHtml(d.delivery_number)}</span></li>`;
							})
							.join('')}</ul>`
					: '<p class="ds-crm-order-payments-empty">No deliveries recorded yet.</p>';

			const deliveryAction = sections.deliveries_action
				? canDeliver
					? `<a class="button" href="${deliveryUrl}">New delivery</a>`
					: '<span class="description">Accept this order before creating a delivery.</span>'
				: '';

			parts.push(`
			<section class="ds-crm-order-view-section">
				<h2 class="ds-crm-order-view-section-title">Deliveries</h2>
				${deliveryRows}
				${deliveryAction ? `<p class="ds-crm-order-view-actions">${deliveryAction}</p>` : ''}
			</section>`);
		}

		if (sections.billing) {
			const dueClass = summary.total_due > 0 ? 'ds-crm-stat-card--due' : 'ds-crm-stat-card--ok';
			const orderDueClass = summary.order_due > 0 ? 'ds-crm-stat-card--due' : '';
			const deliveryDueClass = summary.delivery_due > 0 ? 'ds-crm-stat-card--due' : '';
			let billingHtml = '';

			if (sections.billing_mode === 'client' || sections.billing_mode === 'simple') {
				billingHtml = `
				<p class="description ds-crm-client-billing-note">
					Product and delivery charges for this order. Payments you make are shared across your orders — oldest orders are covered first (product bill, then delivery).
				</p>
				<div class="ds-crm-order-stats ds-crm-order-stats--client-billing">
					${statCard('Total order bill', formatAmount(summary.order_bill))}
					${statCard('Total delivery bill', formatAmount(summary.delivery_bill))}
					${statCard('Total paid', formatAmount(summary.total_paid), summary.total_paid > 0 ? 'ds-crm-stat-card--ok' : '')}
					${statCard('Total due', formatAmount(summary.total_due), dueClass)}
				</div>
				<div class="ds-crm-client-billing-breakdown">
					<p><strong>Product:</strong> ${formatAmount(summary.order_paid)} paid · ${formatAmount(summary.order_due)} due of ${formatAmount(summary.order_bill)}</p>
					<p><strong>Delivery:</strong> ${formatAmount(summary.delivery_paid)} paid · ${formatAmount(summary.delivery_due)} due of ${formatAmount(summary.delivery_bill)}</p>
					<p class="ds-crm-client-billing-total"><strong>Combined bill:</strong> ${formatAmount(summary.total_bill)}</p>
				</div>`;
			} else {
				billingHtml = `
				<div class="ds-crm-order-stats">
					${statCard('Order bill', formatAmount(summary.order_bill))}
					${statCard('Order paid', formatAmount(summary.order_paid))}
					${statCard('Order due', formatAmount(summary.order_due), orderDueClass)}
					${statCard('Delivery bill', formatAmount(summary.delivery_bill))}
					${statCard('Delivery paid', formatAmount(summary.delivery_paid))}
					${statCard('Delivery due', formatAmount(summary.delivery_due), deliveryDueClass)}
					${statCard('Total bill', formatAmount(summary.total_bill))}
					${statCard('Paid', formatAmount(summary.total_paid))}
					${statCard('Due', formatAmount(summary.total_due), dueClass)}
				</div>`;
			}

			parts.push(`
			<section class="ds-crm-order-view-section ds-crm-order-view-financial">
				<h2 class="ds-crm-order-view-section-title">${sections.billing_mode === 'client' || sections.billing_mode === 'simple' ? 'Billing' : 'Billing summary'}</h2>
				${billingHtml}
			</section>`);
		}

		if (sections.payments) {
			const isClient = viewUi.mode === 'client';
			const payRows =
				payments && payments.length
					? `<ul class="ds-crm-order-payment-list">${payments
							.map(
								(p) =>
									`<li><span class="ds-crm-order-payment-date">${formatDate(p.payment_date)}</span><span class="ds-crm-order-payment-amount">${formatAmount(p.amount)}</span><span class="ds-crm-order-payment-ref">${escapeHtml(p.payment_number)}${
										p.payment_method ? ` · ${escapeHtml(String(p.payment_method).replace(/_/g, ' '))}` : ''
									}</span></li>`
							)
							.join('')}</ul>`
					: `<p class="ds-crm-order-payments-empty">${
							isClient
								? 'No payments are linked to this order. Your account payments (shared across orders) are under My payments.'
								: 'No payments recorded yet.'
						}</p>`;

			const paymentAction = sections.payments_action
				? `<a class="button button-primary" href="${paymentUrl}">Record payment</a>`
				: isClient
					? `<a class="button" href="${buildModuleUrl('payments')}">View my payments</a>`
					: '';

			parts.push(`
			<section class="ds-crm-order-view-section">
				<h2 class="ds-crm-order-view-section-title">${isClient ? 'Payments' : 'Payments'}</h2>
				${
					isClient
						? '<p class="description">Payments apply to your whole account balance. Optional order references are shown below when present.</p>'
						: ''
				}
				${payRows}
				${paymentAction ? `<p class="ds-crm-order-view-actions">${paymentAction}</p>` : ''}
			</section>`);
		}

		if (sections.activity) {
			const historyRows =
				history && history.length
					? `<ul class="ds-crm-activity-timeline">${history
							.map(
								(h) => `
							<li class="ds-crm-activity-timeline-item ds-crm-activity-timeline-item--${escapeHtml(h.badge || h.action || 'update')}">
								<div class="ds-crm-activity-timeline-head">
									<span class="ds-crm-activity-timeline-when">${formatDateTime(h.created_at)}</span>
									<span class="ds-crm-badge ds-crm-badge-activity-${escapeHtml(h.badge || h.action)}">${escapeHtml(h.action_label)}</span>
								</div>
								<div class="ds-crm-activity-timeline-body">
									<strong>${escapeHtml(h.user_name)}</strong>
									<span class="ds-crm-activity-timeline-desc">${escapeHtml(h.description)}</span>
								</div>
								${h.changes?.length ? `<ul class="ds-crm-activity-changes">${h.changes.map((c) => `<li>${escapeHtml(c)}</li>`).join('')}</ul>` : ''}
							</li>`
							)
							.join('')}</ul>`
					: '<p class="ds-crm-ledger-empty">No activity recorded yet.</p>';

			parts.push(`
			<section class="ds-crm-order-view-section">
				<h2 class="ds-crm-order-view-section-title">Activity history</h2>
				${historyRows}
			</section>`);
		}

		return parts.join('');
	};

	const cancelOrder = async (id) => {
		if (!window.confirm('Cancel this order? It cannot be undone.')) {
			return;
		}
		const res = await postAjax('crm_orders_cancel', { id });
		if (res.success) {
			DsCrmUI.toast(res.data?.message || 'Order cancelled.');
			window.location.href = listUrl;
		} else {
			DsCrmUI.toast(res.data?.message || 'Cancel failed.', 'error');
		}
	};

	const acceptOrder = async (id, needsPricing) => {
		if (needsPricing) {
			DsCrmUI.toast('Open China workspace to set accepted quantity and unit price.', 'error');
			if (lastViewData?.shipment_form_url) {
				window.location.href = lastViewData.shipment_form_url;
			} else {
				window.location.href = orderFormUrl(id);
			}
			return;
		}
		if (!window.confirm('Approve this order? Accepted quantities and prices will be locked from the current lines.')) {
			return;
		}

		const items = (lastViewData?.items || []).map((item) => ({
			id: item.id,
			accepted_quantity:
				item.accepted_quantity != null && item.accepted_quantity !== ''
					? parseInt(item.accepted_quantity, 10)
					: parseInt(item.quantity, 10) || 0,
			unit_price: item.unit_price,
		}));

		const res = await postAjax('crm_orders_accept', {
			id,
			items: JSON.stringify(items),
		});
		if (res.success) {
			DsCrmUI.toast(res.data?.message || 'Order accepted.');
			loadOrder();
		} else {
			DsCrmUI.toast(res.data?.message || 'Accept failed.', 'error');
		}
	};

	const renderOrder = (data) => {
		const {
			order,
			items,
			summary,
			payments,
			statuses,
			deliveries,
			export_shipments,
			export_remaining,
			can_edit,
			can_cancel,
			can_change_status,
			can_edit_own_only,
			can_accept,
			can_deliver,
			can_record_export,
			can_record_payment,
			shipment_form_url,
			workflow_blocked,
			edit_blocked_reason,
			view_ui,
			creator,
			history,
			tracking,
			needs_pricing,
		} = data;

		lastViewData = data;
		const viewUi = view_ui || { mode: 'staff', columns: [], meta: {}, sections: {} };
		const isClient = viewUi.mode === 'client';
		let lineColumns = [...(viewUi.columns || [])];
		const hasLineWeight = items.some((item) => {
			if (isClient) {
				return parseFloat(item.weight_exported_kg) > 0;
			}
			return parseFloat(item.weight_exported_kg) > 0 || parseFloat(item.weight_kg) > 0;
		});
		if (!hasLineWeight) {
			lineColumns = lineColumns.filter((column) => column !== 'weight');
		}
		// Clients only see "On the way" once China has submitted something.
		if (isClient && !items.some((item) => (parseInt(item.qty_exported, 10) || 0) > 0)) {
			lineColumns = lineColumns.filter((column) => column !== 'exported');
		}

		const exportedWeightTotal = sumWeights(items.map((i) => i.weight_exported_kg));
		const lineWeightTotal = sumWeights(items.map((i) => i.weight_kg));
		const useExportedWeight = parseFloat(exportedWeightTotal) > 0;
		const totalWeight = formatWeight(
			isClient ? (useExportedWeight ? exportedWeightTotal : 0) : useExportedWeight ? exportedWeightTotal : lineWeightTotal
		);
		const totalWeightLabel =
			isClient && useExportedWeight ? 'Total weight (on the way)' : 'Total weight';
		const paymentUrl = buildModuleUrl('payments', { client_id: order.client_id, order_id: order.id });
		const deliveryUrl = buildModuleUrl('delivery', { delivery_action: 'new', order_id: order.id });

		root.querySelector('#ds-crm-order-view-title').textContent = `Order ${order.order_number}`;

		if (viewToolbar) {
			const printBtn = DsCrmUI.actionButton('Print order', 'print', { className: 'ds-crm-print-order' });
			const editBtn =
				can_edit && order.status !== 'cancelled'
					? DsCrmUI.actionButton(can_edit_own_only ? 'Edit your order' : 'Edit order', 'edit', {
							tag: 'a',
							attrs: `href="${orderFormUrl(order.id)}"`,
						})
					: '';
			const cancelBtn =
				can_cancel && order.status !== 'cancelled'
					? `<button type="button" class="button button-small ds-crm-cancel-order" data-id="${order.id}">Cancel order</button>`
					: '';
			const acceptBtn = can_accept
				? `<button type="button" class="button button-primary button-small ds-crm-accept-order" data-id="${order.id}" data-needs-pricing="${needs_pricing ? '1' : '0'}">Accept order</button>`
				: '';
			const pricingBtn =
				can_accept && needs_pricing
					? DsCrmUI.actionButton('Set prices', 'edit', { tag: 'a', attrs: `href="${orderFormUrl(order.id)}"` })
					: '';

			viewToolbar.innerHTML =
				printBtn || editBtn || pricingBtn || acceptBtn || cancelBtn
					? `<div class="ds-crm-order-view-toolbar-inner">${printBtn}${editBtn}${pricingBtn}${acceptBtn}${cancelBtn}</div>`
					: '';
			viewToolbar.hidden = !printBtn && !editBtn && !pricingBtn && !acceptBtn && !cancelBtn;
		}

		renderTracking(tracking, needs_pricing);

		viewMeta.innerHTML = renderOrderMeta({
			order,
			creator,
			viewUi,
			workflowBlocked: workflow_blocked,
			editBlockedReason: edit_blocked_reason,
			totalWeight,
			totalWeightLabel,
			needsPricing: needs_pricing,
		});

		if (statusRow && statusSelect && canManageStatus && can_change_status) {
			statusRow.hidden = false;
			statusSelect.innerHTML = (statuses || [])
				.map((s) => `<option value="${s.slug}" ${s.slug === order.status ? 'selected' : ''}>${escapeHtml(s.label)}</option>`)
				.join('');
			statusSelect.dataset.orderId = order.id;
		} else if (statusRow) {
			statusRow.hidden = true;
		}

		renderOrderLineItemsHead(lineColumns, isClient);

		if (!items.length) {
			viewItemsBody.innerHTML = `<tr><td colspan="${Math.max(lineColumns.length, 1)}" class="ds-crm-empty">No line items.</td></tr>`;
			renderOrderLineItemsFoot(lineColumns, [], summary);
		} else {
			viewItemsBody.innerHTML = items
				.map(
					(item) => `
				<tr class="ds-crm-order-line--${escapeHtml(item.delivery_priority || 'normal')}">
					${lineColumns.map((column) => renderOrderLineCell(item, column)).join('')}
				</tr>`
				)
				.join('');
			renderOrderLineItemsFoot(lineColumns, items, summary);
		}

		viewSummary.innerHTML = renderOrderSummarySections({
			viewUi,
			deliveries,
			exportShipments: export_shipments || [],
			exportRemaining: export_remaining || {},
			payments,
			summary,
			history,
			workflowBlocked: workflow_blocked,
			canDeliver: can_deliver,
			canRecordExport: can_record_export,
			canRecordPayment: can_record_payment,
			shipmentFormUrl: shipment_form_url,
			deliveryUrl,
			paymentUrl,
		});
	};

	const loadOrder = async () => {
		if (!orderId) {
			return;
		}

		loadingEl.hidden = false;
		contentEl.hidden = true;

		const result = await postAjax('crm_orders_get', { id: orderId });
		loadingEl.hidden = true;

		if (!result.success) {
			loadingEl.hidden = false;
			loadingEl.innerHTML = `<p class="ds-crm-form-error">${escapeHtml(result.data?.message || 'Failed to load order.')}</p>`;
			return;
		}

		contentEl.hidden = false;
		renderOrder(result.data);
	};

	viewToolbar?.addEventListener('click', (e) => {
		const acceptBtn = e.target.closest('.ds-crm-accept-order');
		if (acceptBtn) {
			acceptOrder(parseInt(acceptBtn.dataset.id, 10), acceptBtn.dataset.needsPricing === '1');
			return;
		}
		const cancelBtn = e.target.closest('.ds-crm-cancel-order');
		if (cancelBtn) {
			cancelOrder(cancelBtn.dataset.id);
			return;
		}
		const printBtn = e.target.closest('.ds-crm-print-order');
		if (printBtn && lastViewData) {
			printOrderData(lastViewData);
		}
	});

	statusRow?.querySelector('.ds-crm-save-order-status')?.addEventListener('click', async () => {
		const orderIdVal = statusSelect?.dataset.orderId;
		if (!orderIdVal || !statusSelect?.value) {
			return;
		}
		const res = await postAjax('crm_orders_update_status', { id: orderIdVal, status: statusSelect.value });
		if (res.success) {
			DsCrmUI.toast(res.data?.message || 'Status updated');
			loadOrder();
		} else {
			DsCrmUI.toast(res.data?.message || 'Update failed', 'error');
		}
	});

	loadStatusMap().then(loadOrder);
})();
