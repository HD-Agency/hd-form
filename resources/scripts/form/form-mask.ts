/**
 * HD Form Input Masking & Formatting Module.
 *
 * Provides real-time input formatting for phone numbers, currency, dates,
 * and custom pattern masks.
 *
 * @package HDForm
 */

import { createWeakStore } from '../shared/weak';
import { qsa, on, off } from '../shared/helpers';

interface MaskState {
	handler: (e: Event) => void;
}

const store = createWeakStore<HTMLInputElement, MaskState>();
const SELECTOR = '[data-mask], [data-hd-mask], [data-hde-mask]';

function applyPatternMask(value: string, pattern: string): string {
	const digits = value.replace(/\D/g, '');
	let result = '';
	let digitIdx = 0;

	for (let i = 0; i < pattern.length && digitIdx < digits.length; i++) {
		const patternChar = pattern[i];
		if (patternChar === '0') {
			result += digits[digitIdx++];
		} else {
			result += patternChar;
		}
	}

	return result;
}

function applyCurrencyMask(value: string): string {
	const digits = value.replace(/\D/g, '').slice(0, 15);
	if (!digits) return '';
	const num = parseInt(digits, 10);
	return isNaN(num) ? '' : new Intl.NumberFormat('vi-VN').format(num);
}

function applyDateMask(value: string): string {
	const digits = value.replace(/\D/g, '').slice(0, 8);
	let result = '';
	if (digits.length > 0) result += digits.slice(0, 2);
	if (digits.length >= 3) result += '/' + digits.slice(2, 4);
	if (digits.length >= 5) result += '/' + digits.slice(4, 8);
	return result;
}

function formatInputValue(input: HTMLInputElement): void {
	const maskType = input.dataset.mask || input.dataset.hdMask || input.dataset.hdeMask || '';
	if (!maskType) return;

	const val = input.value;
	let formatted = '';

	if (maskType === 'currency') {
		formatted = applyCurrencyMask(val);
	} else if (maskType === 'date') {
		formatted = applyDateMask(val);
	} else {
		formatted = applyPatternMask(val, maskType);
	}

	if (input.value !== formatted) {
		input.value = formatted;
	}
}

function initMaskInput(input: HTMLInputElement): void {
	if (store.has(input)) return;

	const handler = () => formatInputValue(input);
	on(input, 'input', handler);
	formatInputValue(input);

	store.set(input, { handler });
}

function destroyMaskInput(input: HTMLInputElement): void {
	const state = store.get(input);
	if (state) {
		off(input, 'input', state.handler);
		store.delete(input);
	}
}

export default {
	initAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR) ? [root as HTMLInputElement] : [];
		const list = [...matched, ...qsa<HTMLInputElement>(SELECTOR, root)];
		list.forEach(initMaskInput);
	},

	destroyAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR) ? [root as HTMLInputElement] : [];
		const list = [...matched, ...qsa<HTMLInputElement>(SELECTOR, root)];
		list.forEach(destroyMaskInput);
	},
};
