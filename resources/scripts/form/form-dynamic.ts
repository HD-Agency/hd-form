/**
 * HD Form Dynamic Dropdowns.
 *
 * Lazy-loaded on [data-hd-form] [data-source] elements.
 * Loads post/page/category options via /wp-json/hd/v1/form/dynamic-options.
 *
 * @package HDForm
 */

import { createWeakStore } from '../shared/weak';
import { qs, qsa, on, off } from '../shared/helpers';

const getConfig = () => (window as any).hdConfig || (window as any).hdeConfig;

const API_BASE = () => {
	const cfg = getConfig();
	if (cfg?.restApiUrl) {
		return `${cfg.restApiUrl.replace(/\/?$/, '/')}form/dynamic-options`;
	}
	const base = cfg?._baseUrl || '/';
	return `${base.replace(/\/?$/, '/')}wp-json/hd/v1/form/dynamic-options`;
};

const SELECTOR_SOURCE = '[data-source], [data-cascade-map], [data-options]';
const SELECTOR_PLACEHOLDER = 'option[value=""]';

type OptionItem = { id: string | number; title: string };

interface DynamicState {
	cleanup?: () => void;
}

const store = createWeakStore<HTMLSelectElement, DynamicState>();
const cache = new Map<string, OptionItem[]>();

function cacheKey(params: Record<string, string | undefined>): string {
	return JSON.stringify(params);
}

async function fetchOptions(params: Record<string, string | undefined>): Promise<OptionItem[]> {
	const key = cacheKey(params);
	if (cache.has(key)) return cache.get(key)!;

	const url = new URL(API_BASE(), window.location.origin);
	Object.entries(params).forEach(([k, v]) => {
		if (v !== undefined && v !== '') url.searchParams.set(k, v);
	});

	try {
		const res = await fetch(url.toString());
		if (!res.ok) return [];

		const json = await res.json();
		const data: OptionItem[] = Array.isArray(json?.data) ? json.data : Array.isArray(json) ? json : [];
		cache.set(key, data);
		return data;
	} catch {
		return [];
	}
}

function populateSelect(select: HTMLSelectElement, items: OptionItem[]): void {
	const placeholder = qs<HTMLOptionElement>(SELECTOR_PLACEHOLDER, select);
	select.innerHTML = '';
	if (placeholder) select.appendChild(placeholder);

	items.forEach((item) => {
		const opt = document.createElement('option');
		opt.value = String(item.id);
		opt.textContent = item.title;
		select.appendChild(opt);
	});
}

function initDynamicField(select: HTMLSelectElement): void {
	if (store.has(select)) return;

	const rawSource = select.dataset.source;
	const ids = select.dataset.ids;
	const postType = select.dataset.postType;
	const taxonomy = select.dataset.taxonomy;
	const parentField = select.dataset.parentField;
	const filterField = select.dataset.filterField;
	const filterTaxonomy = select.dataset.filterTaxonomy;
	const hideEmpty = select.dataset.hideEmpty;
	const limit = select.dataset.limit;
	const inlineOptions = select.dataset.options;
	const cascadeMapStr = select.dataset.cascadeMap;

	const source = rawSource || (taxonomy ? 'category' : postType ? 'post' : '');

	const form = select.closest<HTMLElement>('[data-hd-form], [data-hde-form]');
	if (!form) return;

	const lang = select.dataset.lang || form.dataset.lang;

	if (inlineOptions) {
		try {
			const items = JSON.parse(inlineOptions);
			if (Array.isArray(items)) {
				populateSelect(select, items);
				store.set(select, {});
				return;
			}
		} catch {
			const items = inlineOptions.split(',').map((pair) => {
				const [id, ...title] = pair.split(':');
				return { id: id.trim(), title: (title.join(':') || id).trim() };
			});
			populateSelect(select, items);
			store.set(select, {});
			return;
		}
	}

	if (cascadeMapStr && parentField) {
		const parentSelect = qs<HTMLSelectElement>(`[name="${parentField}"]`, form);
		if (parentSelect) {
			let map: Record<string, OptionItem[]> = {};
			try {
				map = JSON.parse(cascadeMapStr);
			} catch {}

			const loadFromMap = () => {
				const val = parentSelect.value;
				const items = map[val] || [];
				populateSelect(select, items);
			};

			on(parentSelect, 'change', loadFromMap);
			store.set(select, {
				cleanup: () => off(parentSelect, 'change', loadFromMap),
			});

			if (parentSelect.value) loadFromMap();
			return;
		}
	}

	if (!source) return;

	const commonParams: Record<string, string | undefined> = { source };
	if (postType) commonParams.post_type = postType;
	if (taxonomy) commonParams.taxonomy = taxonomy;
	if (hideEmpty) commonParams.hide_empty = hideEmpty;
	if (limit) commonParams.limit = limit;
	if (lang) commonParams.lang = lang;

	if (ids) {
		fetchOptions({ ...commonParams, ids }).then((items) => populateSelect(select, items));
		store.set(select, {});
		return;
	}

	if (parentField) {
		const parentSelect = qs<HTMLSelectElement>(`[name="${parentField}"]`, form);
		if (!parentSelect) return;

		const loadChildren = () => {
			const parentId = parentSelect.value;
			if (!parentId) {
				populateSelect(select, []);
				return;
			}
			fetchOptions({ ...commonParams, parent: parentId }).then((items) => populateSelect(select, items));
		};

		on(parentSelect, 'change', loadChildren);
		store.set(select, {
			cleanup: () => off(parentSelect, 'change', loadChildren),
		});

		if (parentSelect.value) loadChildren();
		return;
	}

	if (filterField) {
		const filterSelect = qs<HTMLSelectElement>(`[name="${filterField}"]`, form);
		if (!filterSelect) return;

		const loadFiltered = () => {
			const termId = filterSelect.value;
			if (!termId) {
				populateSelect(select, []);
				return;
			}
			fetchOptions({
				...commonParams,
				taxonomy: filterTaxonomy || taxonomy || 'category',
				term_id: termId,
			}).then((items) => populateSelect(select, items));
		};

		on(filterSelect, 'change', loadFiltered);
		store.set(select, {
			cleanup: () => off(filterSelect, 'change', loadFiltered),
		});

		if (filterSelect.value) loadFiltered();
		return;
	}

	fetchOptions(commonParams).then((items) => populateSelect(select, items));
	store.set(select, {});
}

function destroyDynamicField(select: HTMLSelectElement): void {
	const state = store.get(select);
	if (state) {
		state.cleanup?.();
		store.delete(select);
	}
}

export default {
	initAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR_SOURCE) ? [root as HTMLSelectElement] : [];
		const list = [...matched, ...qsa<HTMLSelectElement>(SELECTOR_SOURCE, root)];
		list.forEach(initDynamicField);
	},

	destroyAll(root: Document | Element = document): void {
		const matched = root.nodeType === 1 && (root as Element).matches?.(SELECTOR_SOURCE) ? [root as HTMLSelectElement] : [];
		const list = [...matched, ...qsa<HTMLSelectElement>(SELECTOR_SOURCE, root)];
		list.forEach(destroyDynamicField);
	},
};
