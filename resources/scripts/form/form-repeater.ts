/**
 * HD Form Repeater Field Module.
 *
 * Clone/remove groups of inputs with custom event notification bus.
 *
 * @package HDForm
 */

import '../../styles/form-repeater.scss';
import { createWeakStore } from '../shared/weak';
import { dispatchScan } from '../shared/events';
import { qs, qsa, on, off } from '../shared/helpers';

const SELECTOR_ROW = '[data-repeater-row]';
const SELECTOR_ADD = '[data-repeater-add]';
const SELECTOR_REMOVE = '[data-repeater-remove]';
const SELECTOR_REPEATER = '[data-repeater]';

const RE_INDEX = /\[\d+\]/;
const DEFAULT_MAX_ROWS = 10;

interface RepeaterState {
	cleanup: () => void;
}

const store = createWeakStore<HTMLElement, RepeaterState>();

function reindexRows(container: HTMLElement): void {
	const rows = qsa(SELECTOR_ROW, container);
	rows.forEach((row, i) => {
		qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name]', row).forEach((el) => {
			el.name = el.name.replace(RE_INDEX, `[${i}]`);
		});
	});
}

function updateButtons(container: HTMLElement, min: number, max: number): void {
	const rows = qsa(SELECTOR_ROW, container);
	const count = rows.length;
	const addBtn = qs<HTMLElement>(SELECTOR_ADD, container);

	if (addBtn) {
		addBtn.hidden = count >= max;
	}

	rows.forEach((row) => {
		const removeBtn = qs<HTMLElement>(SELECTOR_REMOVE, row);
		if (removeBtn) {
			removeBtn.hidden = count <= min;
		}
	});
}

function initRepeater(container: HTMLElement): void {
	if (store.has(container)) return;

	const min = parseInt(container.dataset.repeaterMin || '', 10) || 1;
	const max = parseInt(container.dataset.repeaterMax || '', 10) || DEFAULT_MAX_ROWS;

	const firstRow = qs(SELECTOR_ROW, container);
	if (!firstRow) return;

	const template = firstRow.cloneNode(true) as HTMLElement;

	qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea', template).forEach((el) => {
		if (el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio')) {
			el.checked = false;
		} else {
			el.value = '';
		}
	});

	const addBtn = qs<HTMLElement>(SELECTOR_ADD, container);

	function addRow(): void {
		const rows = qsa(SELECTOR_ROW, container);
		if (rows.length >= max) return;

		const newRow = template.cloneNode(true) as HTMLElement;
		const index = rows.length;

		qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name]', newRow).forEach((el) => {
			el.name = el.name.replace(RE_INDEX, `[${index}]`);
		});

		if (addBtn) {
			container.insertBefore(newRow, addBtn);
		} else {
			container.appendChild(newRow);
		}

		updateButtons(container, min, max);

		container.dispatchEvent(new CustomEvent('hd:repeater:add', { bubbles: true, detail: { row: newRow, index } }));
		container.dispatchEvent(new CustomEvent('hde:repeater:add', { bubbles: true, detail: { row: newRow, index } }));
		container.dispatchEvent(new Event('change', { bubbles: true }));

		dispatchScan(newRow);
	}

	function handleRemove(e: Event): void {
		const target = e.target as HTMLElement | null;
		const btn = target?.closest(SELECTOR_REMOVE);
		if (!btn || !container.contains(btn)) return;

		const rows = qsa(SELECTOR_ROW, container);
		if (rows.length <= min) return;

		const row = btn.closest(SELECTOR_ROW);
		if (row) {
			row.remove();
			reindexRows(container);
			updateButtons(container, min, max);

			container.dispatchEvent(new CustomEvent('hd:repeater:remove', { bubbles: true }));
			container.dispatchEvent(new CustomEvent('hde:repeater:remove', { bubbles: true }));
			container.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	if (addBtn) {
		on(addBtn, 'click', addRow);
	}
	on(container, 'click', handleRemove);

	updateButtons(container, min, max);

	store.set(container, {
		cleanup: () => {
			if (addBtn) off(addBtn, 'click', addRow);
			off(container, 'click', handleRemove);
		},
	});
}

function destroyRepeater(container: HTMLElement): void {
	const state = store.get(container);
	if (state) {
		state.cleanup();
		store.delete(container);
	}
}

export default {
	initAll(root: HTMLElement | Document = document): void {
		const matched = root.nodeType === 1 && (root as HTMLElement).matches?.(SELECTOR_REPEATER) ? [root as HTMLElement] : [];
		const list = [...matched, ...qsa<HTMLElement>(SELECTOR_REPEATER, root)];
		list.forEach(initRepeater);
	},

	destroyAll(root: HTMLElement | Document = document): void {
		const matched = root.nodeType === 1 && (root as HTMLElement).matches?.(SELECTOR_REPEATER) ? [root as HTMLElement] : [];
		const list = [...matched, ...qsa<HTMLElement>(SELECTOR_REPEATER, root)];
		list.forEach(destroyRepeater);
	},
};
