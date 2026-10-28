/**
 * HD Form Shared Utilities — Timing & Pure Helpers.
 *
 * @package HDForm\Shared
 */

/**
 * Tiny translation helper fallback.
 */
export function __(text: string, domain?: string): string {
	if (typeof window !== 'undefined' && (window as any).wp?.i18n?.__) {
		return (window as any).wp.i18n.__(text, domain || 'hd-form');
	}
	return text;
}

/**
 * Tiny sprintf helper fallback.
 */
export function sprintf(format: string, ...args: Array<string | number>): string {
	if (typeof window !== 'undefined' && (window as any).wp?.i18n?.sprintf) {
		return (window as any).wp.i18n.sprintf(format, ...args);
	}
	let i = 0;
	return format.replace(/%s|%d/g, () => (args[i++] !== undefined ? String(args[i - 1]) : ''));
}

/**
 * Standard debouncer.
 */
export function debounce<T extends (...args: any[]) => any>(fn: T, ms: number): (...args: Parameters<T>) => void {
	let timer: ReturnType<typeof setTimeout> | null = null;
	return (...args: Parameters<T>) => {
		if (timer) clearTimeout(timer);
		timer = setTimeout(() => {
			fn(...args);
			timer = null;
		}, ms);
	};
}

/**
 * Throttle function execution using requestAnimationFrame.
 */
export function throttleRAF<T extends (...args: any[]) => any>(fn: T): (...args: Parameters<T>) => void {
	let queued = false;
	return (...args: Parameters<T>) => {
		if (queued) return;
		queued = true;
		requestAnimationFrame(() => {
			fn(...args);
			queued = false;
		});
	};
}

/**
 * Safe JSON parser with fallback default value.
 */
export function parseJSON<T = unknown>(str: unknown, fallback: T): T {
	if (typeof str !== 'string') return fallback;
	try {
		const parsed = JSON.parse(str);
		return (parsed ?? fallback) as T;
	} catch {
		return fallback;
	}
}

/**
 * Safe querySelector helper.
 */
export function qs<T extends HTMLElement = HTMLElement>(
	selector: string,
	parent: ParentNode = document
): T | null {
	return parent.querySelector<T>(selector);
}

/**
 * Safe querySelectorAll helper returning Array.
 */
export function qsa<T extends HTMLElement = HTMLElement>(
	selector: string,
	parent: ParentNode = document
): T[] {
	return Array.from(parent.querySelectorAll<T>(selector));
}

/**
 * Type-safe event listener attacher.
 */
export function on<K extends keyof HTMLElementEventMap>(
	el: HTMLElement | Document | Window,
	type: K | string,
	listener: (e: any) => void,
	options?: boolean | AddEventListenerOptions
): void {
	el.addEventListener(type, listener, options);
}

/**
 * Type-safe event listener remover.
 */
export function off<K extends keyof HTMLElementEventMap>(
	el: HTMLElement | Document | Window,
	type: K | string,
	listener: (e: any) => void,
	options?: boolean | EventListenerOptions
): void {
	el.removeEventListener(type, listener, options);
}
