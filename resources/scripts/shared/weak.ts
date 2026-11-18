/**
 * HD Form Shared Utilities — Type-Safe WeakMap Store.
 *
 * Provides a clean WeakMap wrapper to manage component instance lifecycles
 * without memory leaks across dynamic DOM updates and AJAX navigations.
 *
 * @package HDForm\Shared
 */

export interface WeakStore<K extends object, V> {
	has(key: K): boolean;
	get(key: K): V | undefined;
	set(key: K, value: V): void;
	delete(key: K): boolean;
	getOrCreate(key: K, factory: () => V): V;
}

/**
 * Creates a type-safe WeakMap store.
 */
export function createWeakStore<K extends object = HTMLElement, V = unknown>(): WeakStore<K, V> {
	const map = new WeakMap<K, V>();

	const isObject = (v: unknown): v is K => typeof v === 'object' && v !== null;

	return {
		has(key: K): boolean {
			return isObject(key) && map.has(key);
		},

		get(key: K): V | undefined {
			return isObject(key) ? map.get(key) : undefined;
		},

		set(key: K, value: V): void {
			if (isObject(key)) {
				map.set(key, value);
			}
		},

		delete(key: K): boolean {
			return isObject(key) ? map.delete(key) : false;
		},

		getOrCreate(key: K, factory: () => V): V {
			if (!isObject(key)) {
				return factory();
			}
			let instance = map.get(key);
			if (instance === undefined) {
				instance = factory();
				map.set(key, instance);
			}
			return instance;
		},
	};
}
