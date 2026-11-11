/**
 * HD Form Calculated Fields Module.
 *
 * Evaluates dynamic formulas declaratively declared on form inputs.
 * Supports field placeholders {field_name}, arithmetic operators, min/max/round,
 * and currency/percentage/decimal formatting.
 *
 * @package HDForm
 */

import { createWeakStore } from '../shared/weak';
import { qsa, on, off } from '../shared/helpers';

interface FormCalcState {
	cleanup: () => void;
}

const store = createWeakStore<HTMLFormElement, FormCalcState>();

function parseFieldValue(form: HTMLFormElement, fieldName: string): number {
	const escapedName = CSS.escape(fieldName);
	const elements = qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(`[name="${escapedName}"]`, form);
	if (!elements.length) return 0;

	let rawVal = '';

	elements.forEach((el) => {
		if (el instanceof HTMLInputElement && (el.type === 'radio' || el.type === 'checkbox')) {
			if (el.checked) {
				rawVal = el.value;
			}
		} else {
			rawVal = el.value;
		}
	});

	if (!rawVal) return 0;

	let cleaned = rawVal.trim();
	if (/^\d{1,3}(\.\d{3})+$/.test(cleaned) || /^\d+(\.\d{3})+$/.test(cleaned)) {
		cleaned = cleaned.replace(/\./g, '');
	} else if (cleaned.includes(',') && cleaned.includes('.')) {
		cleaned = cleaned.replace(/\./g, '').replace(',', '.');
	} else if (cleaned.includes(',')) {
		cleaned = cleaned.replace(',', '.');
	}

	const cleanNum = cleaned.replace(/[^\d.-]/g, '');
	const num = parseFloat(cleanNum);
	return isNaN(num) ? 0 : num;
}

function safeEvaluateMath(expr: string): number {
	const sanitized = expr
		.replace(/\bmin\b/g, 'Math.min')
		.replace(/\bmax\b/g, 'Math.max')
		.replace(/\bround\b/g, 'Math.round')
		.replace(/\babs\b/g, 'Math.abs')
		.replace(/\bceil\b/g, 'Math.ceil')
		.replace(/\bfloor\b/g, 'Math.floor');

	if (/[^0-9\s.+\-*/()%Math.minmaxroundabsceilfloor,]/g.test(sanitized)) {
		return 0;
	}

	try {
		const fn = new Function(`return (${sanitized});`);
		const res = fn();
		return typeof res === 'number' && !isNaN(res) && isFinite(res) ? res : 0;
	} catch {
		return 0;
	}
}

function formatResult(val: number, formatSpec: string): string {
	if (!formatSpec || formatSpec === 'raw') {
		return String(Math.round(val * 100) / 100);
	}

	if (formatSpec === 'currency') {
		return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val);
	}

	if (formatSpec.startsWith('number:')) {
		const decimals = parseInt(formatSpec.split(':')[1], 10) || 0;
		return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(val);
	}

	if (formatSpec === 'percentage') {
		return `${(val * 100).toFixed(1)}%`;
	}

	return String(val);
}

function evaluateCalcInput(form: HTMLFormElement, calcInput: HTMLInputElement): void {
	const formula = calcInput.dataset.calc || calcInput.dataset.hdCalc || calcInput.dataset.hdeCalc || '';
	if (!formula) return;

	const interpolated = formula.replace(/\{([a-zA-Z0-9_\-]+)\}/g, (_, fieldName) => {
		const val = parseFieldValue(form, fieldName);
		return String(val);
	});

	const numericResult = safeEvaluateMath(interpolated);
	const formatSpec = calcInput.dataset.calcFormat || 'raw';
	const formattedText = formatResult(numericResult, formatSpec);

	if (calcInput.value !== formattedText) {
		calcInput.value = formattedText;
		calcInput.dispatchEvent(new Event('input', { bubbles: true }));
		calcInput.dispatchEvent(new Event('change', { bubbles: true }));
	}
}

function initFormCalc(form: HTMLFormElement): void {
	if (store.has(form)) return;

	const calcInputs = qsa<HTMLInputElement>('[data-calc], [data-hd-calc], [data-hde-calc]', form);
	if (!calcInputs.length) return;

	const updateAll = () => {
		calcInputs.forEach((input) => evaluateCalcInput(form, input));
	};

	on(form, 'input', updateAll);
	on(form, 'change', updateAll);
	on(form, 'hd:form:autosave-restored', updateAll);
	on(form, 'hde:form:autosave-restored', updateAll);

	updateAll();

	store.set(form, {
		cleanup: () => {
			off(form, 'input', updateAll);
			off(form, 'change', updateAll);
			off(form, 'hd:form:autosave-restored', updateAll);
			off(form, 'hde:form:autosave-restored', updateAll);
		},
	});
}

function destroyFormCalc(form: HTMLFormElement): void {
	const state = store.get(form);
	if (state) {
		state.cleanup();
		store.delete(form);
	}
}

export default {
	initAll(root: Document | Element = document): void {
		const selector = '[data-hd-form], [data-hde-form]';
		const matched = root.nodeType === 1 && (root as Element).matches?.(selector) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(selector, root)];
		list.forEach((form) => {
			if (form.querySelector('[data-calc], [data-hd-calc], [data-hde-calc]')) {
				initFormCalc(form);
			}
		});
	},

	destroyAll(root: Document | Element = document): void {
		const selector = '[data-hd-form], [data-hde-form]';
		const matched = root.nodeType === 1 && (root as Element).matches?.(selector) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(selector, root)];
		list.forEach(destroyFormCalc);
	},
};
