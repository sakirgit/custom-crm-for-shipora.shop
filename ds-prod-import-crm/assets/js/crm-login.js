/* global DsCrmLogin */

(() => {
	const form = document.querySelector('.ds-crm-login-form');
	if (!form || !window.DsCrmLogin) {
		return;
	}

	const card = form.closest('.ds-crm-login-card');
	const submitBtn = form.querySelector('.ds-crm-login-button');
	let errorEl = card?.querySelector('.ds-crm-login-error');

	const showError = (message) => {
		if (!card) {
			return;
		}

		if (!errorEl) {
			errorEl = document.createElement('div');
			errorEl.className = 'ds-crm-login-error';
			errorEl.setAttribute('role', 'alert');
			const intro = card.querySelector('.ds-crm-login-intro');
			if (intro?.nextSibling) {
				card.insertBefore(errorEl, intro.nextSibling);
			} else {
				card.appendChild(errorEl);
			}
		}

		errorEl.textContent = message;
		errorEl.hidden = false;
	};

	const hideError = () => {
		if (errorEl) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}
	};

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		hideError();

		if (!submitBtn) {
			return;
		}

		const originalLabel = submitBtn.textContent;
		submitBtn.disabled = true;
		submitBtn.setAttribute('aria-busy', 'true');
		submitBtn.textContent = DsCrmLogin.loggingIn || 'Signing in…';

		const formData = new FormData(form);
		formData.append('action', 'crm_front_login');
		formData.append('nonce', DsCrmLogin.nonce);

		if (!formData.get('redirect_to')) {
			formData.set('redirect_to', window.location.href.split('#')[0]);
		}

		try {
			const response = await fetch(DsCrmLogin.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			});

			const result = await response.json();

			if (result?.success && result.data?.redirect) {
				window.location.assign(result.data.redirect);
				return;
			}

			showError(result?.data?.message || DsCrmLogin.failed || 'Login failed. Please try again.');
		} catch {
			showError(DsCrmLogin.failed || 'Login failed. Please try again.');
		} finally {
			submitBtn.disabled = false;
			submitBtn.removeAttribute('aria-busy');
			submitBtn.textContent = originalLabel;
		}
	});
})();
