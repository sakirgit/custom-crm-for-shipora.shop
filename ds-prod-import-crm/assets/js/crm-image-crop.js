/* global DsCrmUI */

const DsCrmImageCrop = (() => {
	const CANVAS_SIZE = 360;
	let modalEl = null;
	const wiredPasteHosts = new WeakSet();

	const ensureModal = () => {
		if (modalEl) {
			return modalEl;
		}

		modalEl = document.createElement('div');
		modalEl.className = 'ds-crm-modal ds-crm-image-crop-modal';
		modalEl.hidden = true;
		modalEl.innerHTML = `
			<div class="ds-crm-modal-overlay"></div>
			<div class="ds-crm-modal-dialog ds-crm-modal-md" role="dialog" aria-modal="true" aria-labelledby="ds-crm-image-crop-title">
				<div class="ds-crm-modal-header">
					<h2 id="ds-crm-image-crop-title">Crop product image</h2>
					<button type="button" class="ds-crm-modal-close" aria-label="Close">&times;</button>
				</div>
				<div class="ds-crm-image-crop-body">
					<p class="description">Drag the highlighted square to choose the thumbnail area. The full image is also saved.</p>
					<div class="ds-crm-image-crop-stage">
						<canvas class="ds-crm-image-crop-canvas" width="${CANVAS_SIZE}" height="${CANVAS_SIZE}"></canvas>
					</div>
				</div>
				<div class="ds-crm-modal-footer">
					<button type="button" class="button ds-crm-image-crop-cancel">Cancel</button>
					<button type="button" class="button button-primary ds-crm-image-crop-apply">Use image</button>
				</div>
			</div>
		`;
		document.body.appendChild(modalEl);
		DsCrmUI?.wireModal(modalEl);
		return modalEl;
	};

	const loadImage = (file) =>
		new Promise((resolve, reject) => {
			const url = URL.createObjectURL(file);
			const img = new Image();
			img.onload = () => {
				URL.revokeObjectURL(url);
				resolve(img);
			};
			img.onerror = () => {
				URL.revokeObjectURL(url);
				reject(new Error('Could not load image.'));
			};
			img.src = url;
		});

	const fitImage = (img) => {
		const scale = Math.min(CANVAS_SIZE / img.width, CANVAS_SIZE / img.height);
		const dw = img.width * scale;
		const dh = img.height * scale;
		const ox = (CANVAS_SIZE - dw) / 2;
		const oy = (CANVAS_SIZE - dh) / 2;
		return { scale, dw, dh, ox, oy };
	};

	const drawPreview = (ctx, img, fit, cropX, cropY, cropSize) => {
		const { dw, dh, ox, oy } = fit;

		ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
		ctx.fillStyle = '#0f172a';
		ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
		ctx.drawImage(img, ox, oy, dw, dh);

		ctx.fillStyle = 'rgba(15, 23, 42, 0.62)';
		ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

		ctx.save();
		ctx.beginPath();
		ctx.rect(cropX, cropY, cropSize, cropSize);
		ctx.clip();
		ctx.drawImage(img, ox, oy, dw, dh);
		ctx.restore();

		ctx.strokeStyle = 'rgba(255, 255, 255, 0.95)';
		ctx.lineWidth = 2;
		ctx.strokeRect(cropX + 0.5, cropY + 0.5, cropSize - 1, cropSize - 1);
		ctx.strokeStyle = '#2563eb';
		ctx.lineWidth = 1;
		ctx.strokeRect(cropX + 2.5, cropY + 2.5, cropSize - 5, cropSize - 5);

		const handle = 8;
		ctx.fillStyle = '#fff';
		[
			[cropX, cropY],
			[cropX + cropSize - handle, cropY],
			[cropX, cropY + cropSize - handle],
			[cropX + cropSize - handle, cropY + cropSize - handle],
		].forEach(([x, y]) => ctx.fillRect(x, y, handle, handle));
	};

	const cropToBlob = (img, fit, cropX, cropY, cropSize, outputSize = 200) =>
		new Promise((resolve) => {
			const srcX = (cropX - fit.ox) / fit.scale;
			const srcY = (cropY - fit.oy) / fit.scale;
			const srcSize = cropSize / fit.scale;

			const canvas = document.createElement('canvas');
			canvas.width = outputSize;
			canvas.height = outputSize;
			const ctx = canvas.getContext('2d');
			ctx.drawImage(img, srcX, srcY, srcSize, srcSize, 0, 0, outputSize, outputSize);
			canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.9);
		});

	const applyFileToInput = (input, file) => {
		if (!input || !file) {
			return;
		}
		const dataTransfer = new DataTransfer();
		dataTransfer.items.add(file);
		input.files = dataTransfer.files;
	};

	const handleFile = async (file, input, onReady) => {
		try {
			const result = await open(file);
			applyFileToInput(input, result.original);
			onReady?.(result);
		} catch (error) {
			if (error?.message !== 'cancelled') {
				DsCrmUI?.toast?.(error.message || 'Image crop failed.', 'error');
			}
			if (input) {
				input.value = '';
			}
		}
	};

	const open = (file) =>
		new Promise((resolve, reject) => {
			if (!file || !file.type?.startsWith('image/')) {
				reject(new Error('Please choose an image file.'));
				return;
			}

			loadImage(file)
				.then((img) => {
					const modal = ensureModal();
					const canvas = modal.querySelector('.ds-crm-image-crop-canvas');
					const ctx = canvas.getContext('2d');
					const fit = fitImage(img);
					const cropSize = Math.min(fit.dw, fit.dh);
					let cropX = fit.ox + (fit.dw - cropSize) / 2;
					let cropY = fit.oy + (fit.dh - cropSize) / 2;
					let dragging = false;
					let startX = 0;
					let startY = 0;
					let startCropX = 0;
					let startCropY = 0;

					const clampCrop = () => {
						cropX = Math.min(Math.max(fit.ox, cropX), fit.ox + fit.dw - cropSize);
						cropY = Math.min(Math.max(fit.oy, cropY), fit.oy + fit.dh - cropSize);
					};

					const redraw = () => {
						drawPreview(ctx, img, fit, cropX, cropY, cropSize);
						canvas.style.cursor = dragging ? 'grabbing' : 'grab';
					};

					const pointInCrop = (x, y) =>
						x >= cropX && x <= cropX + cropSize && y >= cropY && y <= cropY + cropSize;

					const getCanvasPoint = (event) => {
						const rect = canvas.getBoundingClientRect();
						const scaleX = CANVAS_SIZE / rect.width;
						const scaleY = CANVAS_SIZE / rect.height;
						return {
							x: (event.clientX - rect.left) * scaleX,
							y: (event.clientY - rect.top) * scaleY,
						};
					};

					redraw();

					const onPointerDown = (event) => {
						const point = getCanvasPoint(event);
						if (!pointInCrop(point.x, point.y)) {
							return;
						}
						dragging = true;
						startX = event.clientX;
						startY = event.clientY;
						startCropX = cropX;
						startCropY = cropY;
						canvas.setPointerCapture(event.pointerId);
						redraw();
					};

					const onPointerMove = (event) => {
						if (!dragging) {
							return;
						}
						const rect = canvas.getBoundingClientRect();
						const scaleX = CANVAS_SIZE / rect.width;
						const scaleY = CANVAS_SIZE / rect.height;
						cropX = startCropX + (event.clientX - startX) * scaleX;
						cropY = startCropY + (event.clientY - startY) * scaleY;
						clampCrop();
						redraw();
					};

					const onPointerUp = (event) => {
						dragging = false;
						try {
							canvas.releasePointerCapture(event.pointerId);
						} catch (e) {
							// ignore
						}
						redraw();
					};

					canvas.onpointerdown = onPointerDown;
					canvas.onpointermove = onPointerMove;
					canvas.onpointerup = onPointerUp;
					canvas.onpointercancel = onPointerUp;

					const cleanup = () => {
						canvas.onpointerdown = null;
						canvas.onpointermove = null;
						canvas.onpointerup = null;
						canvas.onpointercancel = null;
						modal.querySelector('.ds-crm-image-crop-apply').onclick = null;
						modal.querySelector('.ds-crm-image-crop-cancel').onclick = null;
					};

					modal.querySelector('.ds-crm-image-crop-apply').onclick = async () => {
						const cropped = await cropToBlob(img, fit, cropX, cropY, cropSize);
						cleanup();
						DsCrmUI.closeModal(modal);
						resolve({ original: file, cropped });
					};

					modal.querySelector('.ds-crm-image-crop-cancel').onclick = () => {
						cleanup();
						DsCrmUI.closeModal(modal);
						reject(new Error('cancelled'));
					};

					DsCrmUI.openModal(modal);
				})
				.catch(reject);
		});

	const attach = (input, onReady) => {
		if (!input) {
			return;
		}

		input.addEventListener('change', () => {
			const file = input.files?.[0];
			if (file) {
				handleFile(file, input, onReady);
			}
		});
	};

	const wireDropZone = (zone, input, onReady) => {
		if (!zone) {
			return;
		}

		if (input) {
			attach(input, onReady);
		}

		const browse = () => input?.click();

		zone.addEventListener('click', (event) => {
			if (event.target.closest('input[type="file"]') || event.target.closest('label[for]')) {
				return;
			}
			browse();
		});

		zone.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				browse();
			}
		});

		zone.addEventListener('dragover', (event) => {
			event.preventDefault();
			zone.classList.add('is-dragover');
		});

		zone.addEventListener('dragleave', () => {
			zone.classList.remove('is-dragover');
		});

		zone.addEventListener('drop', (event) => {
			event.preventDefault();
			zone.classList.remove('is-dragover');
			const file = event.dataTransfer?.files?.[0];
			if (file?.type?.startsWith('image/')) {
				handleFile(file, input, onReady);
			}
		});

		const onPaste = (event) => {
			const items = event.clipboardData?.items;
			if (!items) {
				return;
			}
			for (const item of items) {
				if (item.type?.startsWith('image/')) {
					event.preventDefault();
					const file = item.getAsFile();
					if (file) {
						handleFile(file, input, onReady);
					}
					break;
				}
			}
		};

		const pasteHost = zone.closest('.ds-crm-modal') || zone.closest('.ds-crm-line-new-product');

		if (pasteHost) {
			if (!wiredPasteHosts.has(pasteHost)) {
				pasteHost.addEventListener('paste', (event) => {
					if (pasteHost.hidden) {
						return;
					}
					if (pasteHost.classList.contains('ds-crm-line-new-product')) {
						const picker = pasteHost.closest('.ds-crm-product-picker');
						if (picker?.dataset.pickerMode !== 'new') {
							return;
						}
					}
					onPaste(event);
				});
				wiredPasteHosts.add(pasteHost);
			}
		} else {
			zone.addEventListener('paste', onPaste);
		}
	};

	return { open, attach, wireDropZone, handleFile };
})();

window.DsCrmImageCrop = DsCrmImageCrop;
