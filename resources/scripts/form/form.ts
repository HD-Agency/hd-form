/**
 * HD Form Handler — main module.
 *
 * Lazy-loaded on elements matching [data-hd-form] or [data-hde-form].
 *
 * @package HDForm
 */

import '../../styles/form.scss';
import { createWeakStore } from '../shared/weak';
import { dispatchScan } from '../shared/events';
import { qs, qsa, on, off, __, sprintf } from '../shared/helpers';

interface FormState {
	cleanup: () => void;
}

const store = createWeakStore<HTMLFormElement, FormState>();

const getConfig = () => (window as any).hdConfig || (window as any).hdeConfig;

const SUBMIT_URL = () => {
	const cfg = getConfig();
	if (cfg?.restApiUrl) {
		return `${cfg.restApiUrl.replace(/\/?$/, '/')}form/submit`;
	}
	const base = cfg?._baseUrl || '/';
	return `${base.replace(/\/?$/, '/')}wp-json/hd/v1/form/submit`;
};
const NONCE = () => (getConfig()?.restToken as string) || '';

const LEGACY_HONEYPOT_FIELD = '_hp_field';
const HONEYPOT_META_KEYS = new Set(['_hp_name', '_hp_ts', '_hp_sig']);

const CLASS_FIELD_ERROR = 'hd-form-field--error';
const CLASS_FORM_ERROR = 'hd-form-error';
const CLASS_FORM_LOADING = 'hd-form--loading';
const CLASS_FORM_MESSAGE = 'hd-form-message';

const MESSAGE_DISMISS_MS = 8000;

interface HoneypotConfig {
	field: string;
	timestamp: string;
	signature: string;
}

interface FormPayload extends Record<string, unknown> {
	form_type: string;
	form_id: string;
	name: string;
	email: string;
	phone: string;
	fields: Record<string, unknown>;
	field_labels: Record<string, string>;
	utm: Record<string, string>;
	captcha_type: string;
	captcha_token: string;
	page_url: string;
	_render_ts: string;
	_user_interacted?: boolean;
	_hp_name: string;
	_hp_ts: string;
	_hp_sig: string;
}

function honeypotConfig(form: HTMLFormElement): HoneypotConfig {
	const config = (getConfig()?.form?.honeypot as Record<string, string>) || {};
	const field = form.dataset.hpField || config.field || LEGACY_HONEYPOT_FIELD;
	const timestamp = form.dataset.hpTs || String(config.timestamp || '');
	const signature = form.dataset.hpSig || config.signature || '';

	return { field, timestamp, signature };
}

function hasDatasetKey(form: HTMLFormElement, key: string): boolean {
	return Object.prototype.hasOwnProperty.call(form.dataset, key);
}

function getUtmParams(): Record<string, string> {
	const params = new URLSearchParams(window.location.search);
	const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
	const utm: Record<string, string> = {};

	keys.forEach((key) => {
		const value = params.get(key);
		if (value) {
			const subKey = key.replace('utm_', '');
			if (subKey !== '__proto__' && subKey !== 'constructor' && subKey !== 'prototype') {
				Reflect.set(utm, subKey, value);
			}
		}
	});

	return utm;
}

async function collectFormData(form: HTMLFormElement): Promise<FormPayload> {
	const formData = new FormData(form);
	const formType = form.dataset.hdForm || form.dataset.hdeForm || 'generic';
	const formId = form.dataset.formId || form.id || `${formType}-${Date.now()}`;
	const captchaType = form.dataset.captcha || 'none';

	let name = (formData.get('name') as string) || '';
	let email = (formData.get('email') as string) || '';
	let phone = (formData.get('phone') as string) || '';
	let message = (formData.get('message') as string) || '';

	const CORE_MAP_TARGETS = new Set(['name', 'email', 'phone', 'message'] as const);
	const mappedInputNames = new Set<string>();

	form.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[data-map]').forEach((el) => {
		const target = el.dataset.map as string;
		if (!target || !CORE_MAP_TARGETS.has(target as 'name' | 'email' | 'phone' | 'message')) return;

		const val = el.value?.trim() || '';
		if (!val) return;

		mappedInputNames.add(el.name);

		if (target === 'name' && !name) name = val;
		else if (target === 'email' && !email) email = val;
		else if (target === 'phone' && !phone) phone = val;
		else if (target === 'message' && !message) message = val;
	});

	const fields: Record<string, unknown> = {};
	const fieldLabels: Record<string, string> = {};
	const honeypot = honeypotConfig(form);
	const coreKeys = new Set(['name', 'email', 'phone', 'message', LEGACY_HONEYPOT_FIELD, honeypot.field, ...HONEYPOT_META_KEYS, ...mappedInputNames]);

	const captchaKeys = new Set(['cf-turnstile-response', 'g-recaptcha-response', 'h-captcha-response', 'altcha', 'mtcaptcha-verifiedtoken']);
	let captchaToken = '';

	for (const [key, value] of formData.entries()) {
		if (captchaKeys.has(key)) {
			captchaToken = value as string;
			continue;
		}

		if (coreKeys.has(key)) continue;

		if (key !== '__proto__' && key !== 'constructor' && key !== 'prototype') {
			Reflect.set(fields, key, value);

			const input = form.querySelector<HTMLElement>(`[name="${key}"]`);
			if (input?.dataset?.title) {
				Reflect.set(fieldLabels, key, input.dataset.title);
			}
		}
	}

	if (!captchaToken) {
		const captchaConfig = getConfig()?.captcha;
		const provider = form.dataset.captcha || captchaConfig?.provider;
		const siteKey = captchaConfig?.siteKey;

		if (provider === 'recaptcha_v3' && siteKey && (window as any).grecaptcha) {
			try {
				captchaToken = await new Promise<string>((resolve) => {
					(window as any).grecaptcha.ready(() => {
						(window as any).grecaptcha
							.execute(siteKey, { action: 'form_submit' })
							.then((t: string) => resolve(t || ''))
							.catch(() => resolve(''));
					});
				});
			} catch {
				captchaToken = '';
			}
		} else if (provider === 'turnstile' && (window as any).turnstile) {
			try {
				captchaToken = (window as any).turnstile.getResponse() || '';
			} catch {
				/* ignore */
			}
		} else if ((provider === 'recaptcha_v2' || provider === 'recaptcha_v2_checkbox') && (window as any).grecaptcha) {
			try {
				captchaToken = (window as any).grecaptcha.getResponse() || '';
			} catch {
				/* ignore */
			}
		} else if (provider === 'hcaptcha' && (window as any).hcaptcha) {
			try {
				captchaToken = (window as any).hcaptcha.getResponse() || '';
			} catch {
				/* ignore */
			}
		} else if (provider === 'altcha') {
			const altchaInput = form.querySelector<HTMLInputElement>('input[name="altcha"]');
			if (altchaInput?.value) {
				captchaToken = altchaInput.value;
			}
		}
	}

	(['name', 'email', 'phone', 'message'] as const).forEach((key) => {
		const input = form.querySelector<HTMLElement>(`[name="${key}"]`);
		if (input?.dataset?.title) {
			Reflect.set(fieldLabels, key, input.dataset.title);
		}
	});

	form.querySelectorAll<HTMLElement>('[data-map]').forEach((el) => {
		const target = el.dataset.map as string;
		if (!target || !CORE_MAP_TARGETS.has(target as 'name' | 'email' | 'phone' | 'message')) return;
		if (el.dataset.title && !fieldLabels[target]) {
			Reflect.set(fieldLabels, target, el.dataset.title);
		}
	});

	if (message) {
		fields.message = message;
	}

	const payload: FormPayload = {
		form_type: formType,
		form_id: formId,
		name,
		email,
		phone,
		fields,
		field_labels: fieldLabels,
		utm: getUtmParams(),
		captcha_type: captchaType,
		captcha_token: captchaToken,
		page_url: window.location.href,
		_render_ts: form.dataset.renderTs || String(Date.now()),
		_user_interacted: form.dataset.userInteracted === '1',
		_hp_name: honeypot.field,
		_hp_ts: honeypot.timestamp,
		_hp_sig: honeypot.signature,
		[honeypot.field]: (formData.get(honeypot.field) as string) || '',
	};

	if (hasDatasetKey(form, 'successAction')) payload.success_action = form.dataset.successAction;
	if (hasDatasetKey(form, 'successRedirect')) payload.success_redirect = form.dataset.successRedirect;
	if (hasDatasetKey(form, 'successDelay')) payload.success_delay = form.dataset.successDelay;
	if (hasDatasetKey(form, 'lang')) payload._lang = form.dataset.lang;

	return payload;
}

function validateForm(form: HTMLFormElement): boolean {
	let valid = true;

	form.querySelectorAll(`.${CLASS_FORM_ERROR}, .hde-form-error`).forEach((el) => el.remove());
	form.querySelectorAll(`.${CLASS_FIELD_ERROR}, .hde-form-field--error`).forEach((el) => {
		el.classList.remove(CLASS_FIELD_ERROR, 'hde-form-field--error');
	});

	form.querySelectorAll<HTMLInputElement>('[required]').forEach((input) => {
		if (!input.value.trim()) {
			valid = false;
			input.classList.add(CLASS_FIELD_ERROR);

			const label = input.dataset.title || input.name;
			const error = document.createElement('span');
			error.className = CLASS_FORM_ERROR;
			error.textContent = sprintf(__('%s is required.', 'hd-form'), label);
			input.parentNode?.insertBefore(error, input.nextSibling);
		}
	});

	const emailInput = form.querySelector<HTMLInputElement>('[type="email"]');
	if (emailInput?.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
		valid = false;
		emailInput.classList.add(CLASS_FIELD_ERROR);

		const error = document.createElement('span');
		error.className = CLASS_FORM_ERROR;
		error.textContent = __('Invalid email format.', 'hd-form');
		emailInput.parentNode?.insertBefore(error, emailInput.nextSibling);
	}

	return valid;
}

function validateCaptcha(form: HTMLFormElement, payload: FormPayload): boolean {
	const captchaConfig = getConfig()?.captcha;
	const provider = form.dataset.captcha || captchaConfig?.provider || payload.captcha_type;
	if (!provider || provider === 'none') {
		return true;
	}

	const isInvisible = provider === 'recaptcha_v3' || provider === 'recaptcha_v2_invisible';
	if (isInvisible) {
		return true;
	}

	if (!payload.captcha_token) {
		const widget = form.querySelector('.cf-turnstile, .g-recaptcha, .h-captcha, altcha-widget, .mtcaptcha');
		const errorMsg = __('Please complete the CAPTCHA verification.', 'hd-form');
		if (widget) {
			const existingErr = form.querySelector(`.${CLASS_FORM_ERROR}, .hde-form-error`);
			if (!existingErr) {
				const errorEl = document.createElement('span');
				errorEl.className = CLASS_FORM_ERROR;
				errorEl.textContent = errorMsg;
				widget.parentNode?.insertBefore(errorEl, widget.nextSibling);
			}
			widget.scrollIntoView({ behavior: 'smooth', block: 'center' });
		} else {
			showMessage(form, 'error', errorMsg);
		}
		return false;
	}

	return true;
}

/**
 * Toggle the loading state (CSS class + disabled submit button).
 *
 * Hoisted ahead of async payload collection so a slow file read cannot
 * open a window for duplicate submissions via double-clicks.
 */
function setFormLoading(form: HTMLFormElement, loading: boolean): void {
	form.classList.toggle(CLASS_FORM_LOADING, loading);

	const btn = form.querySelector<HTMLButtonElement>('[type="submit"]');
	if (!btn) return;

	if (loading) {
		btn.dataset.hdOriginalText = btn.textContent || '';
		btn.disabled = true;
		btn.textContent = btn.dataset.loading || __('Sending...', 'hd-form');
	} else {
		btn.disabled = false;
		btn.textContent = btn.dataset.hdOriginalText || '';
		delete btn.dataset.hdOriginalText;
	}
}

async function submitForm(form: HTMLFormElement, payload: FormPayload): Promise<void> {
	try {
		const fileInputs = [...form.querySelectorAll<HTMLInputElement>('input[type="file"]')].filter((i) => i.files?.length);
		const hasFiles = fileInputs.length > 0;

		let fetchOptions: RequestInit;

		if (hasFiles) {
			const fd = new FormData();
			fd.append('payload', JSON.stringify(payload));
			fileInputs.forEach((input) => {
				[...(input.files as FileList)].forEach((file) => fd.append(input.name, file));
			});
			fetchOptions = {
				method: 'POST',
				headers: { 'X-WP-Nonce': NONCE() },
				body: fd,
			};
		} else {
			fetchOptions = {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE(),
				},
				body: JSON.stringify(payload),
			};
		}

		const response = await fetch(SUBMIT_URL(), fetchOptions);
		const result = await response.json();

		if (result.success) {
			const eventDetail = {
				formType: payload.form_type,
				formId: payload.form_id,
				pageUrl: payload.page_url,
				tracking: JSON.parse(form.dataset.tracking || '{}'),
			};

			form.dispatchEvent(new CustomEvent('hd:form:success', { bubbles: true, detail: eventDetail }));
			form.dispatchEvent(new CustomEvent('hde:form:success', { bubbles: true, detail: eventDetail }));

			form.reset();
			form.dataset.renderTs = String(Date.now());

			resetCaptcha(form);
			handleSuccessAction(form, result);
		} else {
			resetCaptcha(form);
			showMessage(form, 'error', result.message || __('An error occurred.', 'hd-form'));
		}
	} catch {
		resetCaptcha(form);
		showMessage(form, 'error', __('Network error. Please try again.', 'hd-form'));
	}
}

function handleSuccessAction(form: HTMLFormElement, result: Record<string, any>): void {
	const onSuccess = result.on_success || {};
	const action = onSuccess.action || 'message';
	const message = onSuccess.message || result.message || __('Thank you!', 'hd-form');
	const redirectUrl = typeof onSuccess.redirect_url === 'string' ? onSuccess.redirect_url : '';
	const redirectDelay = Math.min(10, Math.max(0, parseInt(onSuccess.redirect_delay, 10) || 0));

	if (action === 'popup') {
		const popupTitle = onSuccess.popup_title || __('Thank you!', 'hd-form');
		const popupContent = onSuccess.popup_content || message;
		showSuccessPopup(popupTitle, popupContent);
		form.reset();
		return;
	}

	if (action === 'redirect' && redirectUrl) {
		if (redirectDelay > 0) {
			showMessage(form, 'success', message);
			window.setTimeout(() => {
				window.location.assign(redirectUrl);
			}, redirectDelay * 1000);
			return;
		}

		window.location.assign(redirectUrl);
		return;
	}

	showMessage(form, 'success', message);
	form.reset();
}

function showSuccessPopup(title: string, content: string): void {
	document.querySelectorAll('.hd-form-modal-overlay, .hde-form-modal-overlay').forEach((el) => el.remove());

	const overlay = document.createElement('div');
	overlay.className = 'hd-form-modal-overlay';
	overlay.innerHTML = `
		<div class="hd-form-modal" role="dialog" aria-modal="true">
			<button type="button" class="hd-form-modal-close" aria-label="${__('Close', 'hd-form')}">&times;</button>
			<div class="hd-form-modal-header">
				<h3 class="hd-form-modal-title"></h3>
			</div>
			<div class="hd-form-modal-body">
				<p></p>
			</div>
		</div>
	`;

	const titleEl = overlay.querySelector<HTMLElement>('.hd-form-modal-title');
	const contentEl = overlay.querySelector<HTMLElement>('.hd-form-modal-body p');
	if (titleEl) titleEl.textContent = title;
	if (contentEl) contentEl.textContent = content;

	const closeBtn = overlay.querySelector<HTMLButtonElement>('.hd-form-modal-close');
	const close = () => {
		document.removeEventListener('keydown', handleKeyDown);
		overlay.remove();
	};

	const handleKeyDown = (e: KeyboardEvent) => {
		if (e.key === 'Escape') close();
	};

	closeBtn?.addEventListener('click', close);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) close();
	});
	document.addEventListener('keydown', handleKeyDown);

	document.body.appendChild(overlay);
}

function showMessage(form: HTMLFormElement, type: 'success' | 'error', text: string): void {
	form.querySelectorAll(`.${CLASS_FORM_MESSAGE}, .hde-form-message`).forEach((el) => el.remove());

	const msg = document.createElement('div');
	msg.className = `${CLASS_FORM_MESSAGE} ${CLASS_FORM_MESSAGE}--${type}`;
	msg.textContent = text;
	msg.setAttribute('role', 'alert');

	form.prepend(msg);

	setTimeout(() => msg.remove(), MESSAGE_DISMISS_MS);
}

function injectHoneypot(form: HTMLFormElement): void {
	const honeypot = honeypotConfig(form);
	if (form.querySelector(`[name="${honeypot.field}"]`)) return;

	const hp = document.createElement('input');
	hp.type = 'text';
	hp.name = honeypot.field;
	hp.tabIndex = -1;
	hp.autocomplete = 'off';
	hp.setAttribute('aria-hidden', 'true');
	hp.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;opacity:0;';
	form.appendChild(hp);

	form.dataset.hpField = honeypot.field;
	form.dataset.hpTs = honeypot.timestamp;
	form.dataset.hpSig = honeypot.signature;
	form.dataset.renderTs = String(Date.now());
}

function resetCaptcha(form: HTMLFormElement): void {
	const turnstile = form.querySelector<HTMLElement>('.cf-turnstile');
	if (turnstile && (window as any).turnstile) {
		const widgetId = turnstile.dataset.widgetId;
		try {
			if (widgetId) {
				(window as any).turnstile.reset(widgetId);
			} else {
				(window as any).turnstile.reset();
			}
		} catch {
			/* ignore */
		}
	}

	const recaptcha = form.querySelector('.g-recaptcha');
	if (recaptcha && (window as any).grecaptcha) {
		try {
			(window as any).grecaptcha.reset();
		} catch {
			/* ignore */
		}
	}

	const hcaptcha = form.querySelector('.h-captcha');
	if (hcaptcha && (window as any).hcaptcha) {
		try {
			(window as any).hcaptcha.reset();
		} catch {
			/* ignore */
		}
	}

	const altcha = form.querySelector('altcha-widget');
	if (altcha && typeof (altcha as any).reset === 'function') {
		try {
			(altcha as any).reset();
		} catch {
			/* ignore */
		}
	}

	const v3 = form.querySelector<HTMLInputElement>('.hd-recaptcha-v3-token, .hde-recaptcha-v3-token');
	if (v3) {
		v3.value = '';
	}
}

/**
 * Explicitly render a just-inserted CAPTCHA container when the provider API
 * is already loaded. When it is not, the provider script's auto-render pass
 * picks the container up once it loads.
 */
function renderProviderWidget(provider: string, el: HTMLElement): void {
	const w = window as any;
	try {
		if (provider === 'turnstile' && w.turnstile?.render) {
			el.dataset.widgetId = String(w.turnstile.render(el));
		} else if ((provider === 'recaptcha_v2' || provider === 'recaptcha_v2_checkbox') && w.grecaptcha?.render) {
			w.grecaptcha.render(el);
		}
	} catch {
		/* Container already rendered by the provider's auto-render pass. */
	}
}

function injectCaptcha(form: HTMLFormElement): void {
	const captchaConfig = getConfig()?.captcha;
	if (!captchaConfig || captchaConfig.provider === 'none') {
		return;
	}

	const provider = captchaConfig.provider;
	const siteKey = (captchaConfig.siteKey as string) || '';

	if (provider !== 'altcha' && !siteKey) {
		return;
	}

	form.dataset.captcha = provider;

	const submitBtn = form.querySelector('[type="submit"]');

	if (provider === 'turnstile') {
		if (!form.querySelector('.cf-turnstile')) {
			const turnstileDiv = document.createElement('div');
			turnstileDiv.className = 'cf-turnstile hd-captcha-field';
			turnstileDiv.dataset.sitekey = siteKey;
			if (submitBtn) submitBtn.parentNode?.insertBefore(turnstileDiv, submitBtn);
			else form.appendChild(turnstileDiv);
			renderProviderWidget(provider, turnstileDiv);
		}
	} else if (provider === 'recaptcha_v2' || provider === 'recaptcha_v2_checkbox') {
		if (!form.querySelector('.g-recaptcha')) {
			const recaptchaDiv = document.createElement('div');
			recaptchaDiv.className = 'g-recaptcha hd-captcha-field';
			recaptchaDiv.dataset.sitekey = siteKey;
			if (submitBtn) submitBtn.parentNode?.insertBefore(recaptchaDiv, submitBtn);
			else form.appendChild(recaptchaDiv);
			renderProviderWidget(provider, recaptchaDiv);
		}
	} else if (provider === 'recaptcha_v3') {
		if (!form.querySelector('.hd-recaptcha-v3-token, .hde-recaptcha-v3-token')) {
			const v3Input = document.createElement('input');
			v3Input.type = 'hidden';
			v3Input.className = 'hd-recaptcha-v3-token';
			v3Input.name = 'g-recaptcha-response';
			v3Input.dataset.sitekey = siteKey;
			v3Input.dataset.action = 'form_submit';
			form.appendChild(v3Input);
		}
	} else if (provider === 'hcaptcha') {
		if (!form.querySelector('.h-captcha')) {
			const hcaptchaDiv = document.createElement('div');
			hcaptchaDiv.className = 'h-captcha hd-captcha-field';
			hcaptchaDiv.dataset.sitekey = siteKey;
			if (submitBtn) submitBtn.parentNode?.insertBefore(hcaptchaDiv, submitBtn);
			else form.appendChild(hcaptchaDiv);
		}
	} else if (provider === 'mtcaptcha') {
		if (!form.querySelector('.mtcaptcha')) {
			const mtcaptchaDiv = document.createElement('div');
			mtcaptchaDiv.className = 'mtcaptcha hd-captcha-field';
			mtcaptchaDiv.dataset.sitekey = siteKey;
			if (submitBtn) submitBtn.parentNode?.insertBefore(mtcaptchaDiv, submitBtn);
			else form.appendChild(mtcaptchaDiv);
		}
	} else if (provider === 'altcha') {
		if (!form.querySelector('altcha-widget')) {
			const altchaWidget = document.createElement('altcha-widget');
			altchaWidget.className = 'hd-altcha-widget hd-captcha-field';
			const challengeUrl = captchaConfig.challengeUrl || `${getConfig()?.restApiUrl || '/wp-json/hd/v1/'}captcha/altcha-challenge`;
			altchaWidget.setAttribute('challengeurl', challengeUrl);
			if (submitBtn) submitBtn.parentNode?.insertBefore(altchaWidget, submitBtn);
			else form.appendChild(altchaWidget);
		}
	}

	dispatchScan(form);
}

function initForm(form: HTMLFormElement): void {
	if (store.has(form)) return;

	injectHoneypot(form);
	injectCaptcha(form);

	const markInteracted = () => {
		form.dataset.userInteracted = '1';
	};
	const interactEvents = ['pointerdown', 'keydown', 'touchstart', 'focusin'];
	interactEvents.forEach((evt) => {
		on(form, evt, markInteracted, { once: true, passive: true });
	});

	const handler = async (e: Event) => {
		e.preventDefault();
		if (!validateForm(form)) return;
		if (form.classList.contains(CLASS_FORM_LOADING)) return;

		setFormLoading(form, true);
		try {
			const payload = await collectFormData(form);
			if (!validateCaptcha(form, payload)) return;
			await submitForm(form, payload);
		} finally {
			setFormLoading(form, false);
		}
	};

	on(form, 'submit', handler);

	store.set(form, {
		cleanup: () => {
			off(form, 'submit', handler);
		},
	});
}

function destroyForm(form: HTMLFormElement): void {
	const state = store.get(form);
	if (state) {
		state.cleanup();
		store.delete(form);
	}

	const hp = qs(`[name="${honeypotConfig(form).field}"]`, form);
	if (hp) hp.remove();

	qsa(`.${CLASS_FORM_MESSAGE}, .${CLASS_FORM_ERROR}, .hde-form-message, .hde-form-error`, form).forEach((el) => el.remove());
}

const SELECTOR = '[data-hd-form], [data-hde-form]';

export default {
	initAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR, root)];
		list.forEach(initForm);
	},

	destroyAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR, root)];
		list.forEach(destroyForm);
	},
};
