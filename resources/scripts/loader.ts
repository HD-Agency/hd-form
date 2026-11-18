/**
 * HD Form Standalone Lazy Loader.
 *
 * Scans DOM for form triggers and loads relevant modules on demand.
 *
 * @package HDForm
 */

import config from './form/config';

export interface LoaderConfigItem {
	selector: string;
	loader: () => Promise<any>;
}

export interface LoaderConfig {
	[key: string]: LoaderConfigItem;
}

export function createLoader(cfg: LoaderConfig, name = 'HDFormLoader') {
	const loaded = new Map<string, any>();
	const pending = new Map<string, Promise<any>>();

	const isNeeded = (key: string, root: Document | Element = document): boolean => {
		if (key === '__proto__' || key === 'constructor' || key === 'prototype') return false;
		const item = Reflect.get(cfg, key);
		if (!item) return false;

		const rootMatches = root.nodeType === 1 && (root as Element).matches?.(item.selector);
		return rootMatches || root.querySelector(item.selector) !== null;
	};

	const load = async (key: string): Promise<any> => {
		if (loaded.has(key)) return loaded.get(key);
		if (pending.has(key)) return pending.get(key);

		if (key === '__proto__' || key === 'constructor' || key === 'prototype') return null;
		const item = Reflect.get(cfg, key);
		if (!item) return null;

		const promise = item
			.loader()
			.then((module: any) => {
				const m = module.default || module;
				loaded.set(key, m);
				pending.delete(key);
				return m;
			})
			.catch((e: any) => {
				pending.delete(key);
				console.error(`[${name}] Failed to load: ${key}`, e);
				return null;
			});

		pending.set(key, promise);
		return promise;
	};

	return {
		async init({ root = document }: { root?: Document | Element } = {}): Promise<void> {
			const needed = Object.keys(cfg).filter((key) => isNeeded(key, root));
			const promises = needed.map(async (key) => {
				const m = await load(key);
				m?.initAll?.(root);
			});
			await Promise.all(promises);
		},

		async scan(root: Element): Promise<void> {
			if (!root) return;
			await this.init({ root });
		},

		async destroy(key: string, root: Document | Element = document): Promise<void> {
			const m = loaded.get(key);
			m?.destroyAll?.(root);
		},

		load,
	};
}

const formLoader = createLoader(config);

if (typeof document !== 'undefined') {
	const init = () => formLoader.init();

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}

	const handleScan = (e: Event) => {
		const root = (e as CustomEvent)?.detail?.root || document;
		formLoader.init({ root });
	};

	document.addEventListener('hd:scan', handleScan);
	document.addEventListener('hde:scan', handleScan);
	document.addEventListener('core:scan', handleScan);
}

export default formLoader;
