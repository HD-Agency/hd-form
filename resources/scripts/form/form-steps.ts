/**
 * HD Form Multi-step Form Handler.
 *
 * Splits [data-step] fieldsets into wizard steps with validation.
 *
 * @package HDForm
 */

import '../../styles/form-steps.scss';
import { createWeakStore } from '../shared/weak';
import { qs, qsa, on, off } from '../shared/helpers';

interface MultistepState {
	cleanup: () => void;
}

const store = createWeakStore<HTMLFormElement, MultistepState>();

const CLASS_STEP_ACTIVE = 'hd-step--active';
const CLASS_PROGRESS = 'hd-step-progress';
const CLASS_DOT = 'hd-step-progress__dot';
const CLASS_DOT_ACTIVE = 'hd-step-progress__dot--active';
const CLASS_DOT_DONE = 'hd-step-progress__dot--done';
const CLASS_LABEL = 'hd-step-progress__label';

function initMultistep(form: HTMLFormElement): void {
	if (store.has(form)) return;

	const steps = qsa<HTMLElement>('[data-step]', form);
	if (steps.length < 2) return;

	let current = 0;

	const progressContainer = qs<HTMLElement>('[data-step-progress]', form);
	const isBarMode =
		progressContainer?.dataset.stepProgress === 'bar' ||
		progressContainer?.classList.contains('hd-step-progress--bar') ||
		progressContainer?.classList.contains('hde-step-progress--bar');

	if (progressContainer) {
		if (isBarMode) {
			renderProgressBar(progressContainer);
		} else {
			renderProgress(progressContainer, steps);
		}
	}

	function handleProgressClick(e: Event): void {
		if (!progressContainer || isBarMode) return;
		const target = e.target as HTMLElement | null;
		const dot = target?.closest<HTMLElement>(`.${CLASS_DOT}, .hde-step-progress__dot`);
		if (!dot || !progressContainer.contains(dot)) return;

		const targetIndex = parseInt(dot.dataset.stepIndex || '', 10);
		if (isNaN(targetIndex)) return;

		if (targetIndex < current || dot.classList.contains(CLASS_DOT_DONE) || dot.classList.contains('hde-step-progress__dot--done')) {
			showStep(targetIndex);
		}
	}

	if (progressContainer && !isBarMode) {
		on(progressContainer, 'click', handleProgressClick);
	}

	function showStep(index: number, shouldScroll: boolean = true): void {
		steps.forEach((step, i) => {
			const isActive = i === index;
			step.classList.toggle(CLASS_STEP_ACTIVE, isActive);
			step.classList.toggle('hde-step--active', isActive);
			step.hidden = !isActive;

			qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea', step).forEach((el) => {
				if (isActive) {
					if (el.dataset.hdStepDisabled === 'true' || el.dataset.hdeStepDisabled === 'true') {
						delete el.dataset.hdStepDisabled;
						delete el.dataset.hdeStepDisabled;
						if (el.dataset.hdLogicDisabledByRule !== 'true' && el.dataset.hdeLogicDisabledByRule !== 'true') {
							el.removeAttribute('disabled');
						}
					}
				} else {
					if (!el.disabled) {
						el.disabled = true;
						el.dataset.hdStepDisabled = 'true';
						el.dataset.hdeStepDisabled = 'true';
					}
				}
			});
		});

		current = index;
		form.dataset.currentStep = current.toString();

		if (progressContainer) {
			if (isBarMode) {
				updateProgressBar(progressContainer, current, steps);
			} else {
				updateProgress(progressContainer, current);
			}
		}

		form.dispatchEvent(new Event('change', { bubbles: true }));

		if (shouldScroll) {
			form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	function validateCurrentStep(): boolean {
		const currentStep = steps[current];
		const requiredInputs = qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[required]:not([disabled])', currentStep);

		for (let i = 0; i < requiredInputs.length; i++) {
			const input = requiredInputs[i];
			if (!input.reportValidity()) {
				input.focus();
				return false;
			}
		}

		return true;
	}

	function handleNavClick(e: Event): void {
		const target = e.target as HTMLElement | null;
		const btn = target?.closest<HTMLElement>('[data-action]');
		if (!btn || !form.contains(btn)) return;

		const action = btn.dataset.action;

		if (action === 'next' && current < steps.length - 1) {
			if (validateCurrentStep()) {
				showStep(current + 1);
			}
		} else if (action === 'prev' && current > 0) {
			showStep(current - 1);
		}
	}

	on(form, 'click', handleNavClick);

	function handleKeyDown(e: Event): void {
		const keyEvent = e as KeyboardEvent;
		if (keyEvent.key !== 'Enter') return;
		const target = e.target as HTMLElement | null;
		if (target?.tagName === 'TEXTAREA') return;
		if (current === steps.length - 1) return;
		e.preventDefault();
		if (validateCurrentStep()) showStep(current + 1);
	}

	on(form, 'keydown', handleKeyDown);

	function handleBeforeSubmit(): void {
		steps.forEach((step) => {
			qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea', step).forEach((el) => {
				if (el.dataset.hdStepDisabled === 'true' || el.dataset.hdeStepDisabled === 'true') {
					delete el.dataset.hdStepDisabled;
					delete el.dataset.hdeStepDisabled;
					if (el.dataset.hdLogicDisabledByRule !== 'true' && el.dataset.hdeLogicDisabledByRule !== 'true') {
						el.removeAttribute('disabled');
					}
				}
			});
		});
	}

	on(form, 'submit', handleBeforeSubmit, { capture: true });

	const onRestored = (e: Event) => {
		const customEvent = e as CustomEvent;
		const targetStep = customEvent.detail?.data?.__current_step;
		if (targetStep !== undefined) {
			const stepIndex = parseInt(targetStep, 10);
			if (!isNaN(stepIndex) && stepIndex >= 0 && stepIndex < steps.length) {
				showStep(stepIndex, false);
			}
		}
	};

	on(form, 'hd:form:autosave-restored', onRestored);
	on(form, 'hde:form:autosave-restored', onRestored);

	showStep(0, false);

	store.set(form, {
		cleanup: () => {
			if (progressContainer && !isBarMode) {
				off(progressContainer, 'click', handleProgressClick);
			}
			off(form, 'click', handleNavClick);
			off(form, 'keydown', handleKeyDown);
			off(form, 'submit', handleBeforeSubmit, { capture: true });
			off(form, 'hd:form:autosave-restored', onRestored);
			off(form, 'hde:form:autosave-restored', onRestored);
		},
	});
}

function renderProgress(container: HTMLElement, steps: HTMLElement[]): void {
	container.innerHTML = '';
	container.classList.add(CLASS_PROGRESS);

	steps.forEach((step, i) => {
		const dot = document.createElement('div');
		dot.className = CLASS_DOT;
		dot.dataset.stepIndex = i.toString();

		const label = document.createElement('span');
		label.className = CLASS_LABEL;
		label.textContent = step.dataset.stepTitle || `${i + 1}`;

		dot.appendChild(label);
		container.appendChild(dot);
	});
}

function renderProgressBar(container: HTMLElement): void {
	container.innerHTML = `
		<div class="hd-step-progress__bar-info">
			<span class="hd-step-progress__bar-title"></span>
			<span class="hd-step-progress__bar-percent"></span>
		</div>
		<div class="hd-step-progress__bar-track">
			<div class="hd-step-progress__bar-fill"></div>
		</div>
	`;
	container.classList.add(CLASS_PROGRESS, 'hd-step-progress--bar');
}

function updateProgress(container: HTMLElement | null, current: number): void {
	if (!container) return;

	qsa(`.${CLASS_DOT}, .hde-step-progress__dot`, container).forEach((dot, i) => {
		dot.classList.toggle(CLASS_DOT_ACTIVE, i === current);
		dot.classList.toggle(CLASS_DOT_DONE, i < current);
		dot.classList.toggle('hde-step-progress__dot--active', i === current);
		dot.classList.toggle('hde-step-progress__dot--done', i < current);
	});
}

function updateProgressBar(container: HTMLElement, current: number, steps: HTMLElement[]): void {
	const total = steps.length;
	const currentStep = steps[current];
	const stepTitle = currentStep?.dataset.stepTitle || `Step ${current + 1}`;
	const percent = Math.round(((current + 1) / total) * 100);

	const titleEl = qs<HTMLElement>('.hd-step-progress__bar-title, .hde-step-progress__bar-title', container);
	const percentEl = qs<HTMLElement>('.hd-step-progress__bar-percent, .hde-step-progress__bar-percent', container);
	const fillEl = qs<HTMLElement>('.hd-step-progress__bar-fill, .hde-step-progress__bar-fill', container);

	if (titleEl) titleEl.textContent = `${stepTitle} (${current + 1}/${total})`;
	if (percentEl) percentEl.textContent = `${percent}%`;
	if (fillEl) fillEl.style.width = `${percent}%`;
}

function destroyMultistep(form: HTMLFormElement): void {
	const state = store.get(form);
	if (state) {
		state.cleanup();
		store.delete(form);
	}

	qsa<HTMLElement>('[data-step]', form).forEach((step) => {
		step.hidden = false;
		step.classList.remove(CLASS_STEP_ACTIVE, 'hde-step--active');
		qsa<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('input, select, textarea', step).forEach((el) => {
			if (el.dataset.hdStepDisabled === 'true' || el.dataset.hdeStepDisabled === 'true') {
				delete el.dataset.hdStepDisabled;
				delete el.dataset.hdeStepDisabled;
				if (el.dataset.hdLogicDisabledByRule !== 'true' && el.dataset.hdeLogicDisabledByRule !== 'true') {
					el.removeAttribute('disabled');
				}
			}
		});
	});
}

const SELECTOR_MULTISTEP = '[data-hd-form][data-multistep], [data-hde-form][data-multistep]';

export default {
	initAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR_MULTISTEP) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR_MULTISTEP, root)];
		list.forEach(initMultistep);
	},

	destroyAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR_MULTISTEP) ? [root as HTMLFormElement] : [];
		const list = [...matched, ...qsa<HTMLFormElement>(SELECTOR_MULTISTEP, root)];
		list.forEach(destroyMultistep);
	},
};
