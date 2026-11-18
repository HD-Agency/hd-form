/**
 * HD Form Drag & Drop File Upload Module.
 *
 * Progressive enhancement over <input type="file">.
 *
 * @package HDForm
 */

import '../../styles/form-dropzone.scss';
import { createWeakStore } from '../shared/weak';
import { qs, qsa, on, off, __, sprintf } from '../shared/helpers';

const SELECTOR_DROPZONE = '[data-dropzone]';
const SELECTOR_DROP_AREA = '.hd-dropzone-area, .hde-dropzone-area';
const SELECTOR_PREVIEW = '.hd-dropzone-preview, .hde-dropzone-preview';

const CLASS_HAS_FILE = 'hd-dropzone--has-file';
const CLASS_DRAGOVER = 'hd-dropzone--dragover';
const CLASS_FILE = 'hd-dropzone-file';
const CLASS_FILE_NAME = 'hd-dropzone-file__name';
const CLASS_FILE_SIZE = 'hd-dropzone-file__size';
const CLASS_FILE_REMOVE = 'hd-dropzone-file__remove';
const CLASS_ERROR = 'hd-dropzone-error';

const ERROR_DISMISS_MS = 4000;
const DEFAULT_MAX_SIZE_MB = 10;

interface DropzoneState {
	cleanup: () => void;
	errorTimer?: number;
}

const store = createWeakStore<HTMLElement, DropzoneState>();

function validateFile(file: File, accept: string, maxSizeMB: number): string | null {
	if (accept) {
		const allowed = accept.split(',').map((item) => item.trim().toLowerCase());
		const fileName = file.name.toLowerCase();
		const fileType = file.type.toLowerCase();

		const hasValid = allowed.some((rule) => {
			if (rule.startsWith('.')) {
				return fileName.endsWith(rule);
			}
			if (rule.endsWith('/*')) {
				const mainType = rule.replace('/*', '');
				return fileType.startsWith(`${mainType}/`);
			}
			if (rule.includes('/')) {
				return fileType === rule;
			}
			return fileName.endsWith(`.${rule}`);
		});

		if (!hasValid) {
			return sprintf(__('Invalid file format. Supported formats: %s', 'hd-form'), accept);
		}
	}

	if (maxSizeMB && file.size > maxSizeMB * 1024 * 1024) {
		return sprintf(__('File size too large. Maximum: %sMB', 'hd-form'), String(maxSizeMB));
	}

	return null;
}

function formatSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`;
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function initDropzone(container: HTMLElement): void {
	if (store.has(container)) return;

	const rawFileInput = qs<HTMLInputElement>('input[type="file"]', container);
	const rawDropArea = qs<HTMLElement>(SELECTOR_DROP_AREA, container);
	const rawPreview = qs<HTMLElement>(SELECTOR_PREVIEW, container);

	if (!rawFileInput || !rawDropArea || !rawPreview) return;

	const fileInput: HTMLInputElement = rawFileInput;
	const dropArea: HTMLElement = rawDropArea;
	const preview: HTMLElement = rawPreview;

	const accept = container.dataset.accept || '';
	const maxSize = parseFloat(container.dataset.maxSize || '') || DEFAULT_MAX_SIZE_MB;
	let errorTimer: number | undefined;

	function renderError(el: HTMLElement, msg: string): void {
		if (errorTimer) {
			clearTimeout(errorTimer);
		}

		el.innerHTML = '';
		const div = document.createElement('div');
		div.className = CLASS_ERROR;
		div.textContent = msg;
		el.appendChild(div);

		errorTimer = window.setTimeout(() => {
			if (el.contains(div)) {
				div.remove();
			}
			errorTimer = undefined;
		}, ERROR_DISMISS_MS);
	}

	function renderPreview(el: HTMLElement, file: File): void {
		if (errorTimer) {
			clearTimeout(errorTimer);
			errorTimer = undefined;
		}

		el.innerHTML = '';

		const wrapper = document.createElement('div');
		wrapper.className = CLASS_FILE;

		const nameSpan = document.createElement('span');
		nameSpan.className = CLASS_FILE_NAME;
		nameSpan.textContent = file.name;

		const sizeSpan = document.createElement('span');
		sizeSpan.className = CLASS_FILE_SIZE;
		sizeSpan.textContent = formatSize(file.size);

		const removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = CLASS_FILE_REMOVE;
		removeBtn.setAttribute('aria-label', __('Remove', 'hd-form'));
		removeBtn.textContent = '\u2715';
		on(removeBtn, 'click', () => {
			fileInput.value = '';
			el.innerHTML = '';
			container.classList.remove(CLASS_HAS_FILE, 'hde-dropzone--has-file');
			container.dispatchEvent(new Event('change', { bubbles: true }));
		});

		wrapper.append(nameSpan, sizeSpan, removeBtn);
		el.appendChild(wrapper);
	}

	function handleFiles(fileList: FileList): void {
		if (!fileList.length) return;

		const file = fileList[0];

		const error = validateFile(file, accept, maxSize);
		if (error) {
			fileInput.value = '';
			renderError(preview, error);
			return;
		}

		const dt = new DataTransfer();
		dt.items.add(file);
		fileInput.files = dt.files;

		renderPreview(preview, file);
		container.classList.add(CLASS_HAS_FILE);
		container.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function onDragOver(e: Event): void {
		e.preventDefault();
		container.classList.add(CLASS_DRAGOVER);
	}

	function onDragLeave(): void {
		container.classList.remove(CLASS_DRAGOVER);
	}

	function onDrop(e: Event): void {
		e.preventDefault();
		container.classList.remove(CLASS_DRAGOVER);
		const dragEvent = e as DragEvent;
		if (dragEvent.dataTransfer) {
			handleFiles(dragEvent.dataTransfer.files);
		}
	}

	function onAreaClick(): void {
		fileInput.click();
	}

	function onAreaKeyDown(e: Event): void {
		const keyEvent = e as KeyboardEvent;
		if (keyEvent.key === 'Enter' || keyEvent.key === ' ') {
			e.preventDefault();
			fileInput.click();
		}
	}

	function onFileChange(): void {
		if (fileInput.files) {
			handleFiles(fileInput.files);
		}
	}

	dropArea.setAttribute('role', 'button');
	dropArea.setAttribute('tabindex', '0');
	on(dropArea, 'dragover', onDragOver);
	on(dropArea, 'dragleave', onDragLeave);
	on(dropArea, 'drop', onDrop);
	on(dropArea, 'click', onAreaClick);
	on(dropArea, 'keydown', onAreaKeyDown);

	on(fileInput, 'change', onFileChange);

	store.set(container, {
		cleanup: () => {
			if (errorTimer) {
				clearTimeout(errorTimer);
			}
			dropArea.removeAttribute('role');
			dropArea.removeAttribute('tabindex');
			off(dropArea, 'dragover', onDragOver);
			off(dropArea, 'dragleave', onDragLeave);
			off(dropArea, 'drop', onDrop);
			off(dropArea, 'click', onAreaClick);
			off(dropArea, 'keydown', onAreaKeyDown);
			off(fileInput, 'change', onFileChange);
		},
	});
}

function destroyDropzone(container: HTMLElement): void {
	const state = store.get(container);
	if (state) {
		state.cleanup();
		store.delete(container);
	}
}

export default {
	initAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR_DROPZONE) ? [root as HTMLElement] : [];
		const list = [...matched, ...qsa<HTMLElement>(SELECTOR_DROPZONE, root)];
		list.forEach(initDropzone);
	},

	destroyAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR_DROPZONE) ? [root as HTMLElement] : [];
		const list = [...matched, ...qsa<HTMLElement>(SELECTOR_DROPZONE, root)];
		list.forEach(destroyDropzone);
	},
};
