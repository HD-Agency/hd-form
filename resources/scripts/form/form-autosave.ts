/**
 * HD Form Auto-save & Resume Module.
 *
 * Saves draft input data to localStorage as the user types (debounced).
 * Automatically restores values upon page reload and clears draft on submission.
 *
 * @package HDForm
 */

import { createWeakStore } from '../shared/weak';
import { qs, qsa, on, off, debounce, __, sprintf } from '../shared/helpers';

interface AutosaveState {
	cleanup: () => void;
}

const store = createWeakStore<HTMLFormElement, AutosaveState>();

const EXCLUDED_NAMES = new Set(['_wpnonce', '_hp_name', '_hp_ts', '_hp_sig', '_hp_field', '_render_ts', 'captcha_token']);
const DEBOUNCE_MS = 400;
const SELECTOR =
	'[data-hd-form][data-autosave], [data-hde-form][data-autosave], [data-hd-autosave], [data-hde-autosave], [data-multistep]:not([data-autosave="false"])';

function getStorageKey(form: HTMLFormElement): string {
	const formType = form.dataset.hdForm || form.dataset.hdeForm || 'default';
	const formId = form.id || form.dataset.formId || window.location.pathname;
	const userId = (window as any).hdConfig?.userId || (window as any).hdeConfig?.userId || 0;
	return `hd_draft_${userId}_${formType}_${formId}`;
}

function shouldIgnoreInput(input: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement): boolean {
	if (!input.name || EXCLUDED_NAMES.has(input.name)) return true;
	if (input.type === 'password' || input.type === 'file' || input.type === 'hidden') return true;
	// PII (email/phone) never persists to localStorage drafts.
	if (input instanceof HTMLInputElement && (input.type === 'email' || input.type === 'tel')) return true;
	if (/(\be-?mail\b|phone|\btel\b|mobile|sdt)/i.test(input.name)) return true;
	if (input.disabled && input.dataset.hdStepDisabled !== 'true' && input.dataset.hdeStepDisabled !== 'true') return true;
	return false;
}

function saveDraft(form: HTMLFormElement): void {
	const key = getStorageKey(form);
	const data: Record<string, string | string[]> = {};

	const inputs = qsa<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('input, textarea, select', form);
	inputs.forEach((input) => {
		if (shouldIgnoreInput(input)) return;

		if (input instanceof HTMLInputElement && (input.type === 'radio' || input.type === 'checkbox')) {
			if (input.checked) {
				if (input.type === 'checkbox') {
					const existing = data[input.name];
					if (Array.isArray(existing)) {
						existing.push(input.value);
					} else if (typeof existing === 'string') {
						data[input.name] = [existing, input.value];
					} else {
						data[input.name] = [input.value];
					}
				} else {
					data[input.name] = input.value;
				}
			}
		} else {
			if (input.value !== '') {
				data[input.name] = input.value;
			}
		}
	});

	if (form.dataset.multistep !== undefined) {
		const currentStep = form.dataset.currentStep || '0';
		data['__current_step'] = currentStep;
	}

	try {
		if (Object.keys(data).length > 0) {
			localStorage.setItem(key, JSON.stringify({ data, timestamp: Date.now() }));
		} else {
			localStorage.removeItem(key);
		}
	} catch {
		// localStorage error fallback
	}
}

function renderRestoreAlert(form: HTMLFormElement, timestamp: number): void {
	if (qs('.hd-autosave-alert, .hde-autosave-alert', form)) return;

	const alert = document.createElement('div');
	alert.className = 'hd-autosave-alert';

	const formattedTime = new Date(timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	alert.innerHTML = `
		<span class="hd-autosave-message">
			<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
			${sprintf(__('Restored draft data at %s', 'hd-form'), formattedTime)}
		</span>
		<button type="button" data-action="clear-draft" class="hd-autosave-clear-btn">${__('Clear draft', 'hd-form')}</button>
	`;

	const clearBtn = qs('[data-action="clear-draft"]', alert);
	if (clearBtn) {
		on(clearBtn, 'click', () => {
			clearDraft(form);
			alert.remove();
			form.reset();
		});
	}

	form.insertBefore(alert, form.firstChild);
}

export function clearDraft(form: HTMLFormElement): void {
	const key = getStorageKey(form);
	try {
		localStorage.removeItem(key);
	} catch {
		// localStorage fallback
	}
	qs('.hd-autosave-alert, .hde-autosave-alert', form)?.remove();
}

function restoreDraft(form: HTMLFormElement): void {
	const key = getStorageKey(form);
	let raw: string | null = null;
	try {
		raw = localStorage.getItem(key);
	} catch {
		return;
	}

	if (!raw) return;

	try {
		const parsed = JSON.parse(raw);
		const data: Record<string, string | string[]> = parsed.data || {};
		if (!data || Object.keys(data).length === 0) return;

		const userId = (window as any).hdConfig?.userId || (window as any).hdeConfig?.userId || 0;
		const ageMs = Date.now() - (parsed.timestamp || 0);
		const maxAge = 2 * 60 * 60 * 1000; // 2 hours

		if (userId === 0 && ageMs > maxAge) {
			clearDraft(form);
			return;
		}

		let hasRestored = false;

		Object.entries(data).forEach(([name, val]) => {
			if (name === '__current_step') return;

			const escapedName = CSS.escape(name);
			const fields = qsa<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(`[name="${escapedName}"]`, form);
			if (!fields.length) return;

			fields.forEach((field) => {
				if (shouldIgnoreInput(field)) return;

				if (field instanceof HTMLInputElement && (field.type === 'radio' || field.type === 'checkbox')) {
					if (Array.isArray(val)) {
						field.checked = val.includes(field.value);
					} else {
						field.checked = field.value === val;
					}
				} else {
					if (typeof val === 'string') {
						field.value = val;
					}
				}
				hasRestored = true;
				field.dispatchEvent(new Event('input', { bubbles: true }));
				field.dispatchEvent(new Event('change', { bubbles: true }));
			});
		});

		if (hasRestored) {
			renderRestoreAlert(form, parsed.timestamp || Date.now());
			form.dispatchEvent(new CustomEvent('hd:form:autosave-restored', { bubbles: true, detail: { data } }));
			form.dispatchEvent(new CustomEvent('hde:form:autosave-restored', { bubbles: true, detail: { data } }));
		}
	} catch {
		// Invalid JSON
	}
}

function initAutosave(form: HTMLFormElement): void {
	if (store.has(form)) return;

	restoreDraft(form);

	const debounceMs = parseInt(form.dataset.autosave || '', 10) || DEBOUNCE_MS;
	const debouncedSave = debounce(() => saveDraft(form), debounceMs);

	const onInput = () => debouncedSave();
	const onSuccess = () => clearDraft(form);

	on(form, 'input', onInput);
	on(form, 'change', onInput);
	on(form, 'hd:form:success', onSuccess);
	on(form, 'hde:form:success', onSuccess);

	store.set(form, {
		cleanup: () => {
			off(form, 'input', onInput);
			off(form, 'change', onInput);
			off(form, 'hd:form:success', onSuccess);
			off(form, 'hde:form:success', onSuccess);
		},
	});
}

function destroyAutosave(form: HTMLFormElement): void {
	const state = store.get(form);
	if (state) {
		state.cleanup();
		store.delete(form);
	}
}

export default {
	initAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR, root)];
		list.forEach(initAutosave);
	},

	destroyAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR, root)];
		list.forEach(destroyAutosave);
	},
};
