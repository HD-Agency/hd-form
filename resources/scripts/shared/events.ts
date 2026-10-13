/**
 * HD Form Shared Utilities — Decoupled EventBus.
 *
 * Lightweight Pub/Sub event bus for cross-module signaling
 * without hard code dependencies.
 *
 * @package HDForm\Shared
 */

export type EventHandler<T = any> = (payload: T) => void;

export class EventBus {
	private listeners = new Map<string, Set<EventHandler>>();

	/**
	 * Subscribe to an event.
	 */
	on<T = any>(event: string, handler: EventHandler<T>): EventHandler<T> {
		if (!this.listeners.has(event)) {
			this.listeners.set(event, new Set());
		}
		this.listeners.get(event)!.add(handler);
		return handler;
	}

	/**
	 * Subscribe to an event once.
	 */
	once<T = any>(event: string, handler: EventHandler<T>): void {
		const wrapper: EventHandler<T> = (payload: T) => {
			handler(payload);
			this.off(event, wrapper);
		};
		this.on(event, wrapper);
	}

	/**
	 * Unsubscribe from an event.
	 */
	off<T = any>(event: string, handler?: EventHandler<T>): void {
		if (!this.listeners.has(event)) return;

		if (!handler) {
			this.listeners.delete(event);
			return;
		}

		const set = this.listeners.get(event)!;
		set.delete(handler);
		if (set.size === 0) {
			this.listeners.delete(event);
		}
	}

	/**
	 * Emit an event with optional payload.
	 */
	emit<T = any>(event: string, payload?: T): void {
		const set = this.listeners.get(event);
		if (!set || set.size === 0) return;

		set.forEach((handler) => {
			try {
				handler(payload);
			} catch (err) {
				console.error(`[HDForm EventBus] Error in handler for "${event}":`, err);
			}
		});
	}

	/**
	 * Clear all event listeners.
	 */
	clear(): void {
		this.listeners.clear();
	}
}

/**
 * Global HD Form cross-module event bus singleton instance.
 */
export const globalBus = new EventBus();

/**
 * Dispatch DOM scan event to re-hydrate dynamic HTML.
 */
export function dispatchScan(root: Element | Document = document): void {
	if (typeof document === 'undefined') return;

	document.dispatchEvent(
		new CustomEvent('hd:scan', {
			detail: { root },
		}),
	);
}
