/**
 * HD Form Conditional Show/Hide Fields Module.
 *
 * @package HDForm
 */

import '../../styles/form-logic.scss';
import { createWeakStore } from '../shared/weak';
import { qs, qsa, on, off } from '../shared/helpers';

interface Condition {
	field: string;
	value: string;
}

interface ResolvedCondition extends Condition {
	el: Element | null;
}

interface LogicState {
	cleanup: () => void;
}

const store = createWeakStore<HTMLFormElement, LogicState>();

function parseConditions(condStr: string): Condition[] {
	return condStr
		.split(',')
		.map((pair) => {
			const [field, ...rest] = pair.split(':');
			return { field: field.trim(), value: rest.join(':').trim() };
		})
		.filter((c) => c.field && c.value);
}

function getFieldValue(form: HTMLFormElement, fieldName: string, cachedEl: Element | null = null): string {
	const el = (cachedEl?.isConnected ? cachedEl : qs(`[name="${fieldName}"]`, form)) as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null;
	if (!el) return '';

	if (el.type === 'radio') {
		const checked = qs<HTMLInputElement>(`[name="${fieldName}"]:checked`, form);
		return checked ? checked.value : '';
	}

	if (el.type === 'checkbox') {
		const boxes = qsa<HTMLInputElement>(`[name="${fieldName}"]:checked`, form);
		return boxes.length
			? boxes
					.map((b) => b.value)
					.join(',')
			: '';
	}

	return el.value;
}

function resolveElements(form: HTMLFormElement, conditions: Condition[]): ResolvedCondition[] {
	return conditions.map((c) => ({
		...c,
		el: qs(`[name="${c.field}"]`, form),
	}));
}

function evaluateConditions(form: HTMLFormElement, conditions: ResolvedCondition[]): boolean {
	return conditions.every(({ field, value, el }) => {
		const current = getFieldValue(form, field, el);

		if (value.includes('|')) {
			return value.split('|').some((v) => v.trim() === current);
		}

		return current === value;
	});
}

function toggleBlock(block: HTMLElement, show: boolean): void {
	const currentState = block.dataset.hdLogicState || block.dataset.hdeLogicState;
	const newState = String(show);

	if (currentState === newState) return;
	block.dataset.hdLogicState = newState;
	block.dataset.hdeLogicState = newState;

	if (show) {
		block.removeAttribute('hidden');
		block.style.display = 'block';
		qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea', block).forEach((el) => {
			if (el.dataset.hdLogicDisabledByRule === 'true' || el.dataset.hdeLogicDisabledByRule === 'true') {
				el.removeAttribute('disabled');
				delete el.dataset.hdLogicDisabledByRule;
				delete el.dataset.hdeLogicDisabledByRule;
			}
		});
	} else {
		block.setAttribute('hidden', '');
		block.style.display = 'none';
		qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea', block).forEach((el) => {
			if (!el.disabled) {
				el.disabled = true;
				el.dataset.hdLogicDisabledByRule = 'true';
				el.dataset.hdeLogicDisabledByRule = 'true';
			}
		});
	}
}

function initLogic(form: HTMLFormElement): void {
	if (store.has(form)) return;

	const showBlocks = qsa<HTMLElement>('[data-show-if]', form);
	const hideBlocks = qsa<HTMLElement>('[data-hide-if]', form);

	if (!showBlocks.length && !hideBlocks.length) return;

	const rules: { block: HTMLElement; conditions: ResolvedCondition[]; mode: 'show' | 'hide' }[] = [];

	showBlocks.forEach((block) => {
		rules.push({
			block,
			conditions: resolveElements(form, parseConditions(block.dataset.showIf || '')),
			mode: 'show',
		});
	});

	hideBlocks.forEach((block) => {
		rules.push({
			block,
			conditions: resolveElements(form, parseConditions(block.dataset.hideIf || '')),
			mode: 'hide',
		});
	});

	let rafId: number;
	const evaluate = () => {
		rules.forEach(({ block, conditions, mode }) => {
			const match = evaluateConditions(form, conditions);
			toggleBlock(block, mode === 'show' ? match : !match);
		});
	};

	const scheduleEvaluate = () => {
		cancelAnimationFrame(rafId);
		rafId = requestAnimationFrame(evaluate);
	};

	evaluate();

	on(form, 'change', scheduleEvaluate);
	on(form, 'input', scheduleEvaluate);
	on(form, 'hd:repeater:add', scheduleEvaluate);
	on(form, 'hde:repeater:add', scheduleEvaluate);
	on(form, 'hd:repeater:remove', scheduleEvaluate);
	on(form, 'hde:repeater:remove', scheduleEvaluate);

	store.set(form, {
		cleanup: () => {
			cancelAnimationFrame(rafId);
			off(form, 'change', scheduleEvaluate);
			off(form, 'input', scheduleEvaluate);
			off(form, 'hd:repeater:add', scheduleEvaluate);
			off(form, 'hde:repeater:add', scheduleEvaluate);
			off(form, 'hd:repeater:remove', scheduleEvaluate);
			off(form, 'hde:repeater:remove', scheduleEvaluate);
		},
	});
}

function destroyLogic(form: HTMLFormElement): void {
	const state = store.get(form);
	if (state) {
		state.cleanup();
		store.delete(form);
	}
}

const SELECTOR = '[data-hd-form][data-logic], [data-hde-form][data-logic]';

export default {
	initAll(root: Document | HTMLElement = document): void {
		const matched = root.nodeType === 1 && (root as HTMLElement).matches?.(SELECTOR) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR, root)];
		list.forEach(initLogic);
	},

	destroyAll(root: Document | HTMLElement = document): void {
		const matched = root.nodeType === 1 && (root as HTMLElement).matches?.(SELECTOR) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR, root)];
		list.forEach(destroyLogic);
	},
};
